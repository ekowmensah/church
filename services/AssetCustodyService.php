<?php

require_once __DIR__ . '/../helpers/asset_register_helper.php';

/**
 * Transactional custody state machine for member/staff asset-use requests.
 *
 * The request header remains the workflow summary. Physical availability and
 * custody are controlled by asset_items, while asset_custody_events provides
 * immutable evidence for every reservation, issue and return.
 */
final class AssetCustodyService
{
    private mysqli $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function isAvailable(): bool
    {
        return asset_table_exists($this->conn, 'asset_custody_events')
            && asset_column_exists($this->conn, 'asset_items', 'custody_status')
            && asset_column_exists($this->conn, 'asset_items', 'active_request_item_id');
    }

    public function process(
        int $requestId,
        string $action,
        array $input,
        ?int $scopeChurchId,
        bool $isSuper,
        ?int $actorUserId,
        ?int $actorMemberId
    ): array {
        if (!$this->isAvailable()) {
            throw new RuntimeException('Run Phase 0092 before managing asset custody.');
        }
        if (!in_array($action, ['approve', 'reject', 'checkout', 'return', 'cancel'], true)) {
            throw new InvalidArgumentException('Invalid asset custody action.');
        }

        $this->conn->begin_transaction();
        try {
            $request = $this->lockRequest($requestId, $scopeChurchId, $isSuper);
            if (!$request) {
                throw new RuntimeException('Asset request not found in your church scope.');
            }

            switch ($action) {
                case 'cancel':
                    $this->cancel($request, $actorUserId, $actorMemberId);
                    break;
                case 'reject':
                    $this->reject($request, trim((string) ($input['approval_note'] ?? '')), $actorUserId);
                    break;
                case 'approve':
                    $this->approve($request, $input, $actorUserId);
                    break;
                case 'checkout':
                    $this->issue($request, trim((string) ($input['approval_note'] ?? '')), $actorUserId);
                    break;
                case 'return':
                    $this->receiveReturn($request, $input, $actorUserId);
                    break;
            }

            $this->conn->commit();
            asset_log_action(
                'asset_use_request_' . $action,
                'asset_use_request',
                $requestId,
                ['church_id' => (int) $request['church_id'], 'asset_id' => (int) $request['asset_id']],
                ['status' => (string) $request['status']],
                ['action' => $action]
            );
            return ['request_id' => $requestId, 'action' => $action];
        } catch (Throwable $exception) {
            $this->conn->rollback();
            throw $exception;
        }
    }

    private function lockRequest(int $requestId, ?int $churchId, bool $isSuper): ?array
    {
        $sql = 'SELECT request.* FROM asset_use_requests request WHERE request.id = ?';
        if (!$isSuper) {
            $sql .= ' AND request.church_id = ?';
        }
        $sql .= ' LIMIT 1 FOR UPDATE';
        $stmt = $this->conn->prepare($sql);
        if ($isSuper) {
            $stmt->bind_param('i', $requestId);
        } else {
            $scope = (int) $churchId;
            $stmt->bind_param('ii', $requestId, $scope);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    private function cancel(array $request, ?int $actorUserId, ?int $actorMemberId): void
    {
        $ownsRequest = ($actorMemberId && (int) $request['requested_by_member_id'] === $actorMemberId)
            || ($actorUserId && (int) $request['requested_by_user_id'] === $actorUserId);
        if (!$ownsRequest || (string) $request['status'] !== 'pending') {
            throw new RuntimeException('Only the requester can cancel a pending request.');
        }
        foreach ($this->lockLines((int) $request['id']) as $line) {
            if ((string) $line['line_status'] !== 'pending') {
                continue;
            }
            $this->updateLineStatus((int) $line['id'], 'cancelled', '', $actorUserId);
            asset_request_line_audit($this->conn, (int) $request['id'], (int) $line['id'], 'cancelled', $line, ['line_status' => 'cancelled'], $actorUserId);
        }
        $stmt = $this->conn->prepare("UPDATE asset_use_requests SET status = 'cancelled' WHERE id = ?");
        $id = (int) $request['id'];
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    private function reject(array $request, string $note, ?int $actorUserId): void
    {
        if ((string) $request['status'] !== 'pending') {
            throw new RuntimeException('Only pending requests can be rejected.');
        }
        if ($note === '') {
            throw new RuntimeException('A rejection reason is required.');
        }
        foreach ($this->lockLines((int) $request['id']) as $line) {
            if ((string) $line['line_status'] !== 'pending') continue;
            $this->updateLineStatus((int) $line['id'], 'rejected', $note, $actorUserId);
            asset_request_line_audit($this->conn, (int) $request['id'], (int) $line['id'], 'rejected', $line, ['line_status' => 'rejected', 'decision_note' => $note], $actorUserId);
        }
        $stmt = $this->conn->prepare("UPDATE asset_use_requests SET status = 'rejected', approved_by = ?, approved_at = NOW(), approval_note = ?, approved_quantity = 0, last_edited_by_user_id = ?, last_edited_at = NOW() WHERE id = ?");
        $id = (int) $request['id'];
        $stmt->bind_param('isii', $actorUserId, $note, $actorUserId, $id);
        $stmt->execute();
        $stmt->close();
    }

    private function approve(array $request, array $input, ?int $actorUserId): void
    {
        if ((string) $request['status'] !== 'pending') {
            throw new RuntimeException('Only pending requests can be reviewed.');
        }
        $start = trim((string) ($input['borrow_start_date'] ?? ''));
        $due = trim((string) ($input['expected_return_date'] ?? ''));
        $note = trim((string) ($input['approval_note'] ?? ''));
        if (!$this->validDate($start) || !$this->validDate($due) || $due < $start) {
            throw new RuntimeException('Enter a valid borrowing and expected return period.');
        }

        $lineIds = array_values((array) ($input['line_id'] ?? []));
        $assetIds = array_values((array) ($input['line_asset_id'] ?? []));
        $itemIds = array_values((array) ($input['line_asset_item_id'] ?? []));
        $decisions = array_values((array) ($input['line_decision'] ?? []));
        if (!$lineIds || count($lineIds) !== count($assetIds) || count($assetIds) !== count($decisions)) {
            throw new RuntimeException('The reviewed request lines are incomplete.');
        }
        if (count($itemIds) !== count($assetIds)) {
            $itemIds = array_fill(0, count($assetIds), 0);
        }

        $existing = [];
        foreach ($this->lockLines((int) $request['id']) as $line) {
            $existing[(int) $line['id']] = $line;
        }
        $seenLines = [];
        $seenItems = [];
        $approvedCount = 0;
        $firstAssetId = 0;

        foreach ($assetIds as $index => $postedAssetId) {
            $assetId = (int) $postedAssetId;
            $lineId = (int) $lineIds[$index];
            $decision = ((string) $decisions[$index] === 'approved') ? 'approved' : 'rejected';
            if ($lineId > 0 && isset($seenLines[$lineId])) {
                throw new RuntimeException('A request line was submitted more than once.');
            }
            $this->assertAssetInChurch($assetId, (int) $request['church_id']);
            if ($firstAssetId === 0) $firstAssetId = $assetId;

            $before = $lineId > 0 ? ($existing[$lineId] ?? null) : null;
            if ($lineId > 0 && !$before) {
                throw new RuntimeException('A request line does not belong to this request.');
            }
            if ($lineId === 0) {
                $stmt = $this->conn->prepare('INSERT INTO asset_use_request_items (request_id, asset_id, line_status, decision_note, reviewed_by_user_id, reviewed_at) VALUES (?, ?, ?, ?, ?, NOW())');
                $requestId = (int) $request['id'];
                $stmt->bind_param('iissi', $requestId, $assetId, $decision, $note, $actorUserId);
                $stmt->execute();
                $lineId = (int) $this->conn->insert_id;
                $stmt->close();
            } else {
                $stmt = $this->conn->prepare('UPDATE asset_use_request_items SET asset_id = ?, asset_item_id = NULL, line_status = ?, decision_note = ?, reviewed_by_user_id = ?, reviewed_at = NOW() WHERE id = ? AND request_id = ?');
                $requestId = (int) $request['id'];
                $stmt->bind_param('issiii', $assetId, $decision, $note, $actorUserId, $lineId, $requestId);
                $stmt->execute();
                $stmt->close();
            }

            if ($decision === 'approved') {
                $requestedItemId = (int) ($itemIds[$index] ?? 0);
                $item = $this->reserveItem($assetId, $requestedItemId, $lineId, $request, $actorUserId, $note);
                if (isset($seenItems[(int) $item['id']])) {
                    throw new RuntimeException('A physical item cannot be reserved twice in one request.');
                }
                $seenItems[(int) $item['id']] = true;
                $approvedCount++;
            }

            asset_request_line_audit(
                $this->conn,
                (int) $request['id'],
                $lineId,
                $before ? $decision : 'added',
                $before,
                ['asset_id' => $assetId, 'line_status' => $decision],
                $actorUserId
            );
            $seenLines[$lineId] = true;
        }

        foreach ($existing as $lineId => $line) {
            if (isset($seenLines[$lineId]) || (string) $line['line_status'] !== 'pending') continue;
            $this->updateLineStatus($lineId, 'rejected', 'Removed during administrator review', $actorUserId);
            asset_request_line_audit($this->conn, (int) $request['id'], $lineId, 'rejected', $line, ['line_status' => 'rejected', 'reason' => 'removed'], $actorUserId);
        }

        $newStatus = $approvedCount > 0 ? 'approved' : 'rejected';
        $requestedCount = count($assetIds);
        $stmt = $this->conn->prepare('UPDATE asset_use_requests SET asset_id = ?, status = ?, approved_by = ?, approved_at = NOW(), approval_note = ?, quantity_requested = ?, approved_quantity = ?, borrow_start_date = ?, expected_return_date = ?, last_edited_by_user_id = ?, last_edited_at = NOW() WHERE id = ?');
        $id = (int) $request['id'];
        $stmt->bind_param('isisiissii', $firstAssetId, $newStatus, $actorUserId, $note, $requestedCount, $approvedCount, $start, $due, $actorUserId, $id);
        $stmt->execute();
        $stmt->close();
    }

    private function issue(array $request, string $note, ?int $actorUserId): void
    {
        if ((string) $request['status'] !== 'approved') {
            throw new RuntimeException('Only approved requests can be issued.');
        }
        $issued = 0;
        foreach ($this->lockLines((int) $request['id']) as $line) {
            if ((string) $line['line_status'] !== 'approved') continue;
            $itemId = (int) ($line['asset_item_id'] ?? 0);
            if ($itemId < 1) {
                throw new RuntimeException('An approved line has no reserved physical item. Review the request again.');
            }
            $stmt = $this->conn->prepare("SELECT * FROM asset_items WHERE id = ? AND active_request_item_id = ? AND custody_status = 'reserved' FOR UPDATE");
            $lineId = (int) $line['id'];
            $stmt->bind_param('ii', $itemId, $lineId);
            $stmt->execute();
            $item = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$item) throw new RuntimeException('A reserved physical item is no longer available for issue.');

            $stmt = $this->conn->prepare("UPDATE asset_items SET custody_status = 'issued', current_custodian_member_id = ?, current_custodian_user_id = ?, custody_since_at = NOW() WHERE id = ?");
            $memberId = (int) ($request['requested_by_member_id'] ?? 0) ?: null;
            $userId = (int) ($request['requested_by_user_id'] ?? 0) ?: null;
            $stmt->bind_param('iii', $memberId, $userId, $itemId);
            $stmt->execute();
            $stmt->close();
            $stmt = $this->conn->prepare("UPDATE asset_use_request_items SET line_status = 'checked_out', checked_out_by_user_id = ?, checked_out_at = NOW() WHERE id = ?");
            $stmt->bind_param('ii', $actorUserId, $lineId);
            $stmt->execute();
            $stmt->close();
            $this->event($item, $request, $lineId, 'issued', $actorUserId, null, null, $note);
            asset_request_line_audit($this->conn, (int) $request['id'], $lineId, 'checked_out', $line, ['asset_item_id' => $itemId, 'line_status' => 'checked_out'], $actorUserId);
            $issued++;
        }
        if ($issued < 1) throw new RuntimeException('This request has no approved items to issue.');
        $stmt = $this->conn->prepare("UPDATE asset_use_requests SET status = 'checked_out', checked_out_by = ?, checked_out_at = NOW(), approval_note = ? WHERE id = ?");
        $id = (int) $request['id'];
        $stmt->bind_param('isi', $actorUserId, $note, $id);
        $stmt->execute();
        $stmt->close();
    }

    private function receiveReturn(array $request, array $input, ?int $actorUserId): void
    {
        if (!in_array((string) $request['status'], ['checked_out', 'overdue'], true)) {
            throw new RuntimeException('Only issued or overdue requests can be returned.');
        }
        $returnDate = trim((string) ($input['actual_return_date'] ?? ''));
        $condition = trim((string) ($input['return_condition'] ?? 'Good'));
        $note = trim((string) ($input['return_note'] ?? ''));
        if (!$this->validDate($returnDate)) throw new RuntimeException('A valid actual return date is required.');
        if (!in_array($condition, asset_condition_options(), true) || $condition === 'Disposed') {
            throw new RuntimeException('Select a valid condition observed at return.');
        }
        $returned = 0;
        foreach ($this->lockLines((int) $request['id']) as $line) {
            if ((string) $line['line_status'] !== 'checked_out') continue;
            $itemId = (int) ($line['asset_item_id'] ?? 0);
            $lineId = (int) $line['id'];
            $stmt = $this->conn->prepare("SELECT * FROM asset_items WHERE id = ? AND active_request_item_id = ? AND custody_status = 'issued' FOR UPDATE");
            $stmt->bind_param('ii', $itemId, $lineId);
            $stmt->execute();
            $item = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$item) throw new RuntimeException('Issued item custody no longer matches this request.');
            $lifecycle = in_array($condition, ['Poor', 'Under Maintenance', 'Damaged'], true) ? 'under_maintenance' : 'in_use';
            $stmt = $this->conn->prepare("UPDATE asset_items SET custody_status = 'available', current_custodian_member_id = NULL, current_custodian_user_id = NULL, active_request_item_id = NULL, custody_since_at = NULL, condition_status = ?, lifecycle_status = ? WHERE id = ?");
            $stmt->bind_param('ssi', $condition, $lifecycle, $itemId);
            $stmt->execute();
            $stmt->close();
            $stmt = $this->conn->prepare("UPDATE asset_use_request_items SET line_status = 'returned', returned_by_user_id = ?, returned_at = NOW() WHERE id = ?");
            $stmt->bind_param('ii', $actorUserId, $lineId);
            $stmt->execute();
            $stmt->close();
            $this->event($item, $request, $lineId, 'returned', $actorUserId, (string) $item['condition_status'], $condition, $note);
            asset_request_line_audit($this->conn, (int) $request['id'], $lineId, 'returned', $line, ['line_status' => 'returned', 'condition_after' => $condition], $actorUserId);
            $returned++;
        }
        if ($returned < 1) throw new RuntimeException('This request has no issued items to receive.');
        $stmt = $this->conn->prepare("UPDATE asset_use_requests SET status = 'returned', actual_return_date = ?, returned_by = ?, returned_at = NOW(), return_note = ? WHERE id = ?");
        $id = (int) $request['id'];
        $stmt->bind_param('sisi', $returnDate, $actorUserId, $note, $id);
        $stmt->execute();
        $stmt->close();
    }

    private function reserveItem(int $assetId, int $requestedItemId, int $lineId, array $request, ?int $actorUserId, string $note): array
    {
        $sql = "SELECT item.* FROM asset_items item
                WHERE item.asset_id = ? AND item.church_id = ? AND item.status = 'active'
                  AND item.lifecycle_status NOT IN ('under_maintenance','retired','disposed')
                  AND item.custody_status = 'available'";
        $types = 'ii';
        $params = [$assetId, (int) $request['church_id']];
        if ($requestedItemId > 0) {
            $sql .= ' AND item.id = ?';
            $types .= 'i';
            $params[] = $requestedItemId;
        }
        $sql .= ' ORDER BY item.item_number, item.id LIMIT 1 FOR UPDATE';
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$item) throw new RuntimeException('The selected physical item is no longer available for reservation.');

        $itemId = (int) $item['id'];
        $stmt = $this->conn->prepare("UPDATE asset_items SET custody_status = 'reserved', active_request_item_id = ?, current_custodian_member_id = NULL, current_custodian_user_id = NULL, custody_since_at = NOW() WHERE id = ? AND custody_status = 'available'");
        $stmt->bind_param('ii', $lineId, $itemId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('The selected physical item was reserved by another request.');
        }
        $stmt->close();
        $stmt = $this->conn->prepare('UPDATE asset_use_request_items SET asset_item_id = ? WHERE id = ?');
        $stmt->bind_param('ii', $itemId, $lineId);
        $stmt->execute();
        $stmt->close();
        $this->event($item, $request, $lineId, 'reserved', $actorUserId, null, null, $note);
        return $item;
    }

    private function lockLines(int $requestId): array
    {
        $stmt = $this->conn->prepare('SELECT * FROM asset_use_request_items WHERE request_id = ? ORDER BY id FOR UPDATE');
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private function updateLineStatus(int $lineId, string $status, string $note, ?int $actorUserId): void
    {
        $stmt = $this->conn->prepare('UPDATE asset_use_request_items SET line_status = ?, decision_note = ?, reviewed_by_user_id = ?, reviewed_at = NOW() WHERE id = ?');
        $stmt->bind_param('ssii', $status, $note, $actorUserId, $lineId);
        $stmt->execute();
        $stmt->close();
    }

    private function assertAssetInChurch(int $assetId, int $churchId): void
    {
        $stmt = $this->conn->prepare("SELECT id FROM assets WHERE id = ? AND church_id = ? AND status = 'active' LIMIT 1");
        $stmt->bind_param('ii', $assetId, $churchId);
        $stmt->execute();
        $found = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$found) throw new RuntimeException('A reviewed asset is unavailable in this church.');
    }

    private function event(array $item, array $request, int $lineId, string $type, ?int $actorUserId, ?string $before, ?string $after, string $notes): void
    {
        $stmt = $this->conn->prepare('INSERT INTO asset_custody_events (church_id, asset_id, asset_item_id, request_id, request_item_id, event_type, custodian_member_id, custodian_user_id, condition_before, condition_after, notes, performed_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $churchId = (int) $request['church_id'];
        $assetId = (int) $item['asset_id'];
        $itemId = (int) $item['id'];
        $requestId = (int) $request['id'];
        $memberId = (int) ($request['requested_by_member_id'] ?? 0) ?: null;
        $userId = (int) ($request['requested_by_user_id'] ?? 0) ?: null;
        $stmt->bind_param('iiiiisiisssi', $churchId, $assetId, $itemId, $requestId, $lineId, $type, $memberId, $userId, $before, $after, $notes, $actorUserId);
        $stmt->execute();
        $stmt->close();
    }

    private function validDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}

