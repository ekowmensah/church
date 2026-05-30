<?php

function spouse_link_table_exists(mysqli $conn): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }

    $sql = "
        SELECT COUNT(*) AS cnt
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'spouse_link_requests'
    ";
    $res = $conn->query($sql);
    $row = $res ? $res->fetch_assoc() : null;
    $exists = ((int) ($row['cnt'] ?? 0)) > 0;
    return $exists;
}

function spouse_link_members_columns(mysqli $conn): array
{
    static $columns = null;
    if (is_array($columns)) {
        return $columns;
    }

    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM members');
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $field = (string) ($row['Field'] ?? '');
            if ($field !== '') {
                $columns[$field] = true;
            }
        }
        $result->free();
    }

    return $columns;
}

function spouse_link_dashboard_notifications_exists(mysqli $conn): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }

    $sql = "
        SELECT COUNT(*) AS cnt
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'dashboard_notifications'
    ";
    $res = $conn->query($sql);
    $row = $res ? $res->fetch_assoc() : null;
    $exists = ((int) ($row['cnt'] ?? 0)) > 0;
    return $exists;
}

function spouse_link_full_name(array $member): string
{
    return trim(implode(' ', array_filter([
        (string) ($member['first_name'] ?? ''),
        (string) ($member['middle_name'] ?? ''),
        (string) ($member['last_name'] ?? ''),
    ])));
}

function spouse_link_find_member_by_crn(mysqli $conn, string $crn): ?array
{
    $crn = trim($crn);
    if ($crn === '') {
        return null;
    }

    $stmt = $conn->prepare('SELECT id, crn, first_name, middle_name, last_name FROM members WHERE crn = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $crn);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function spouse_link_insert_notification(mysqli $conn, int $target_member_id, string $type, string $title, string $message, ?string $action_url = null): void
{
    if ($target_member_id <= 0 || !spouse_link_dashboard_notifications_exists($conn)) {
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO dashboard_notifications
        (target_user_id, target_member_id, notification_type, title, message, action_url, is_read, created_at)
        VALUES (NULL, ?, ?, ?, ?, ?, 0, NOW())
    ");
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('issss', $target_member_id, $type, $title, $message, $action_url);
    $stmt->execute();
    $stmt->close();
}

function spouse_link_create_request_by_crn(mysqli $conn, int $requester_member_id, string $target_spouse_crn): array
{
    if ($requester_member_id <= 0) {
        return ['ok' => false, 'status' => 'invalid', 'message' => 'Invalid requester member id.'];
    }
    if (!spouse_link_table_exists($conn)) {
        return ['ok' => false, 'status' => 'unavailable', 'message' => 'Spouse request module is not installed.'];
    }

    $target = spouse_link_find_member_by_crn($conn, $target_spouse_crn);
    if (!$target) {
        return ['ok' => true, 'status' => 'not_member', 'message' => 'Spouse CRN is not linked to an existing member; no approval request sent.'];
    }

    $target_member_id = (int) ($target['id'] ?? 0);
    if ($target_member_id <= 0) {
        return ['ok' => false, 'status' => 'invalid_target', 'message' => 'Invalid spouse target member.'];
    }
    if ($target_member_id === $requester_member_id) {
        return ['ok' => false, 'status' => 'self', 'message' => 'You cannot set yourself as spouse.'];
    }

    $pending_stmt = $conn->prepare("
        SELECT id, requester_member_id, target_member_id
        FROM spouse_link_requests
        WHERE status = 'pending'
          AND (
                (requester_member_id = ? AND target_member_id = ?)
             OR (requester_member_id = ? AND target_member_id = ?)
          )
        ORDER BY id DESC
        LIMIT 1
    ");
    if ($pending_stmt) {
        $pending_stmt->bind_param('iiii', $requester_member_id, $target_member_id, $target_member_id, $requester_member_id);
        $pending_stmt->execute();
        $pending_res = $pending_stmt->get_result();
        $pending_row = $pending_res ? $pending_res->fetch_assoc() : null;
        $pending_stmt->close();
        if ($pending_row) {
            return ['ok' => true, 'status' => 'pending_exists', 'message' => 'Spouse approval request is already pending.'];
        }
    }

    $insert_stmt = $conn->prepare("
        INSERT INTO spouse_link_requests
        (requester_member_id, target_member_id, status, requested_at)
        VALUES (?, ?, 'pending', NOW())
    ");
    if (!$insert_stmt) {
        return ['ok' => false, 'status' => 'db_error', 'message' => 'Unable to create spouse approval request.'];
    }
    $insert_stmt->bind_param('ii', $requester_member_id, $target_member_id);
    $ok = $insert_stmt->execute();
    $insert_stmt->close();
    if (!$ok) {
        return ['ok' => false, 'status' => 'db_error', 'message' => 'Unable to create spouse approval request.'];
    }

    $requester_stmt = $conn->prepare('SELECT first_name, middle_name, last_name, crn FROM members WHERE id = ? LIMIT 1');
    $requester = null;
    if ($requester_stmt) {
        $requester_stmt->bind_param('i', $requester_member_id);
        $requester_stmt->execute();
        $requester_res = $requester_stmt->get_result();
        $requester = $requester_res ? $requester_res->fetch_assoc() : null;
        $requester_stmt->close();
    }
    $requester_name = spouse_link_full_name($requester ?: []);
    $requester_crn = trim((string) ($requester['crn'] ?? ''));
    $title = 'Spouse Relationship Request';
    $message = ($requester_name !== '' ? $requester_name : 'A member')
        . ($requester_crn !== '' ? ' (' . $requester_crn . ')' : '')
        . ' added you as spouse and is waiting for your approval.';
    spouse_link_insert_notification(
        $conn,
        $target_member_id,
        'spouse_relationship_request',
        $title,
        $message,
        'views/member_profile.php#spouse-requests'
    );

    return ['ok' => true, 'status' => 'created', 'message' => 'Spouse approval request sent.'];
}

function spouse_link_get_pending_incoming(mysqli $conn, int $member_id): array
{
    if ($member_id <= 0 || !spouse_link_table_exists($conn)) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT r.id, r.requested_at, r.requester_member_id, m.crn, m.first_name, m.middle_name, m.last_name
        FROM spouse_link_requests r
        INNER JOIN members m ON m.id = r.requester_member_id
        WHERE r.target_member_id = ?
          AND r.status = 'pending'
        ORDER BY r.requested_at DESC, r.id DESC
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $row['requester_name'] = spouse_link_full_name($row);
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function spouse_link_get_pending_outgoing(mysqli $conn, int $member_id): array
{
    if ($member_id <= 0 || !spouse_link_table_exists($conn)) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT r.id, r.requested_at, r.target_member_id, m.crn, m.first_name, m.middle_name, m.last_name
        FROM spouse_link_requests r
        INNER JOIN members m ON m.id = r.target_member_id
        WHERE r.requester_member_id = ?
          AND r.status = 'pending'
        ORDER BY r.requested_at DESC, r.id DESC
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $row['target_name'] = spouse_link_full_name($row);
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function spouse_link_approve_request(mysqli $conn, int $request_id, int $approver_member_id): array
{
    if ($request_id <= 0 || $approver_member_id <= 0) {
        return ['ok' => false, 'message' => 'Invalid request.'];
    }
    if (!spouse_link_table_exists($conn)) {
        return ['ok' => false, 'message' => 'Spouse request module is not installed.'];
    }

    $cols = spouse_link_members_columns($conn);
    if (!isset($cols['spouse_crn']) || !isset($cols['spouse_name'])) {
        return ['ok' => false, 'message' => 'Member spouse fields are missing in database.'];
    }

    $stmt = $conn->prepare("
        SELECT r.id, r.requester_member_id, r.target_member_id
        FROM spouse_link_requests r
        WHERE r.id = ?
          AND r.target_member_id = ?
          AND r.status = 'pending'
        LIMIT 1
    ");
    if (!$stmt) {
        return ['ok' => false, 'message' => 'Unable to process request.'];
    }
    $stmt->bind_param('ii', $request_id, $approver_member_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $req = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$req) {
        return ['ok' => false, 'message' => 'Request not found or already handled.'];
    }

    $requester_id = (int) ($req['requester_member_id'] ?? 0);
    $target_id = (int) ($req['target_member_id'] ?? 0);
    if ($requester_id <= 0 || $target_id <= 0) {
        return ['ok' => false, 'message' => 'Invalid request payload.'];
    }

    $member_stmt = $conn->prepare('SELECT id, crn, first_name, middle_name, last_name FROM members WHERE id IN (?, ?)');
    if (!$member_stmt) {
        return ['ok' => false, 'message' => 'Unable to load member records.'];
    }
    $member_stmt->bind_param('ii', $requester_id, $target_id);
    $member_stmt->execute();
    $member_res = $member_stmt->get_result();
    $members = [];
    while ($member_res && ($row = $member_res->fetch_assoc())) {
        $members[(int) $row['id']] = $row;
    }
    $member_stmt->close();

    if (!isset($members[$requester_id]) || !isset($members[$target_id])) {
        return ['ok' => false, 'message' => 'One or both members no longer exist.'];
    }

    $requester = $members[$requester_id];
    $target = $members[$target_id];
    $requester_name = spouse_link_full_name($requester);
    $target_name = spouse_link_full_name($target);
    $requester_crn = (string) ($requester['crn'] ?? '');
    $target_crn = (string) ($target['crn'] ?? '');

    try {
        $conn->begin_transaction();

        $upd_req = $conn->prepare("
            UPDATE spouse_link_requests
            SET status = 'approved',
                responded_at = NOW(),
                responded_by_member_id = ?
            WHERE id = ?
              AND status = 'pending'
        ");
        if (!$upd_req) {
            throw new Exception('Failed to update approval request.');
        }
        $upd_req->bind_param('ii', $approver_member_id, $request_id);
        if (!$upd_req->execute()) {
            $upd_req->close();
            throw new Exception('Failed to update approval request.');
        }
        $upd_req->close();

        $upd_reverse = $conn->prepare("
            UPDATE spouse_link_requests
            SET status = 'approved',
                responded_at = NOW(),
                responded_by_member_id = ?
            WHERE requester_member_id = ?
              AND target_member_id = ?
              AND status = 'pending'
        ");
        if ($upd_reverse) {
            $upd_reverse->bind_param('iii', $approver_member_id, $target_id, $requester_id);
            $upd_reverse->execute();
            $upd_reverse->close();
        }

        $upd_member = $conn->prepare('UPDATE members SET marital_status = ?, spouse_crn = ?, spouse_name = ? WHERE id = ?');
        if (!$upd_member) {
            throw new Exception('Failed to link spouse records.');
        }

        $married = 'Married';
        $upd_member->bind_param('sssi', $married, $target_crn, $target_name, $requester_id);
        if (!$upd_member->execute()) {
            $upd_member->close();
            throw new Exception('Failed to link requester spouse details.');
        }

        $upd_member->bind_param('sssi', $married, $requester_crn, $requester_name, $target_id);
        if (!$upd_member->execute()) {
            $upd_member->close();
            throw new Exception('Failed to link target spouse details.');
        }
        $upd_member->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'message' => 'Approval failed: ' . $e->getMessage()];
    }

    spouse_link_insert_notification(
        $conn,
        $requester_id,
        'spouse_relationship_approved',
        'Spouse Relationship Approved',
        ($target_name !== '' ? $target_name : 'Your spouse') . ' approved your spouse relationship request.',
        'views/member_profile.php'
    );
    spouse_link_insert_notification(
        $conn,
        $target_id,
        'spouse_relationship_linked',
        'Spouse Linked',
        ($requester_name !== '' ? $requester_name : 'Member') . ' has been linked to your profile as spouse.',
        'views/member_profile.php'
    );

    return ['ok' => true, 'message' => 'Spouse request approved and both profiles linked.'];
}

function spouse_link_reject_request(mysqli $conn, int $request_id, int $approver_member_id): array
{
    if ($request_id <= 0 || $approver_member_id <= 0) {
        return ['ok' => false, 'message' => 'Invalid request.'];
    }
    if (!spouse_link_table_exists($conn)) {
        return ['ok' => false, 'message' => 'Spouse request module is not installed.'];
    }

    $stmt = $conn->prepare("
        SELECT r.id, r.requester_member_id, m.first_name, m.middle_name, m.last_name
        FROM spouse_link_requests r
        INNER JOIN members m ON m.id = r.requester_member_id
        WHERE r.id = ?
          AND r.target_member_id = ?
          AND r.status = 'pending'
        LIMIT 1
    ");
    if (!$stmt) {
        return ['ok' => false, 'message' => 'Unable to process request.'];
    }
    $stmt->bind_param('ii', $request_id, $approver_member_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $req = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$req) {
        return ['ok' => false, 'message' => 'Request not found or already handled.'];
    }

    $upd = $conn->prepare("
        UPDATE spouse_link_requests
        SET status = 'rejected',
            responded_at = NOW(),
            responded_by_member_id = ?
        WHERE id = ?
          AND status = 'pending'
    ");
    if (!$upd) {
        return ['ok' => false, 'message' => 'Unable to reject request.'];
    }
    $upd->bind_param('ii', $approver_member_id, $request_id);
    $ok = $upd->execute();
    $upd->close();
    if (!$ok) {
        return ['ok' => false, 'message' => 'Unable to reject request.'];
    }

    $requester_name = spouse_link_full_name($req);
    spouse_link_insert_notification(
        $conn,
        (int) $req['requester_member_id'],
        'spouse_relationship_rejected',
        'Spouse Relationship Request Declined',
        'Your spouse relationship request was declined.',
        'views/member_profile.php'
    );

    return ['ok' => true, 'message' => 'Spouse request rejected.'];
}

