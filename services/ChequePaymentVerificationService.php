<?php

final class ChequePaymentVerificationService {
    private mysqli $conn;
    private int $actorUserId;
    private bool $superAdmin;
    private ?int $churchId = null;

    public function __construct(mysqli $conn, int $actorUserId, bool $superAdmin = false) {
        $this->conn = $conn;
        $this->actorUserId = $actorUserId;
        $this->superAdmin = $superAdmin;
        $stmt = $conn->prepare('SELECT church_id FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $actorUserId);
        $stmt->execute();
        $this->churchId = (int) ($stmt->get_result()->fetch_assoc()['church_id'] ?? 0) ?: null;
        $stmt->close();
    }

    public function listPending(): array {
        return $this->search(['status' => 'pending'], 1, 500)['rows'];
    }

    public function search(array $filters, int $page = 1, int $perPage = 25): array {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $status = strtolower(trim((string) ($filters['status'] ?? 'pending')));
        if (!in_array($status, ['pending', 'verified', 'rejected', 'all'], true)) {
            $status = 'pending';
        }
        $search = mb_substr(trim((string) ($filters['search'] ?? '')), 0, 120);
        $dateFrom = $this->validDate((string) ($filters['date_from'] ?? ''));
        $dateTo = $this->validDate((string) ($filters['date_to'] ?? ''));
        if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $conditions = ["LOWER(TRIM(payment.mode)) IN ('cheque', 'check')"];
        $types = '';
        $params = [];
        $this->appendChurchScope($conditions, $types, $params, (int) ($filters['church_id'] ?? 0));
        if ($status !== 'all') {
            $conditions[] = 'payment.cheque_verification_status = ?';
            $types .= 's';
            $params[] = $status;
        }
        if ($dateFrom !== '') {
            $conditions[] = 'payment.payment_date >= ?';
            $types .= 's';
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $conditions[] = 'payment.payment_date <= ?';
            $types .= 's';
            $params[] = $dateTo;
        }
        if ($search !== '') {
            $like = '%' . $search . '%';
            $conditions[] = "(payment.cheque_number LIKE ? OR payment.bank_name LIKE ?
                OR payment.manual_batch_reference LIKE ? OR member.crn LIKE ? OR child.srn LIKE ?
                OR member.first_name LIKE ? OR member.last_name LIKE ?
                OR child.first_name LIKE ? OR child.last_name LIKE ? OR type.name LIKE ?)";
            $types .= 'ssssssssss';
            for ($i = 0; $i < 10; $i++) $params[] = $like;
        }
        $where = implode(' AND ', $conditions);
        $joins = " FROM payments payment
            LEFT JOIN members member ON member.id = payment.member_id
            LEFT JOIN sunday_school child ON child.id = payment.sundayschool_id
            LEFT JOIN payment_types type ON type.id = payment.payment_type_id
            LEFT JOIN churches church ON church.id = payment.church_id
            LEFT JOIN users recorder ON recorder.id = CAST(payment.recorded_by AS UNSIGNED)
            LEFT JOIN users verifier ON verifier.id = payment.cheque_verified_by";

        $countStmt = $this->conn->prepare("SELECT COUNT(*) AS total_rows{$joins} WHERE {$where}");
        if ($types !== '') $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $totalRows = (int) ($countStmt->get_result()->fetch_assoc()['total_rows'] ?? 0);
        $countStmt->close();

        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;
        $dataSql = "SELECT payment.id, payment.amount, payment.payment_date,
                payment.payment_period_description, payment.reporting_period_label,
                payment.bank_name, payment.cheque_number, payment.manual_batch_reference,
                payment.description, payment.recorded_by, payment.cheque_verification_status,
                payment.cheque_verified_at, payment.cheque_verification_notes,
                type.name AS payment_type, church.name AS church_name,
                COALESCE(member.crn, child.srn, '') AS registration_number,
                TRIM(CONCAT_WS(' ', COALESCE(member.first_name, child.first_name),
                    COALESCE(member.middle_name, child.middle_name),
                    COALESCE(member.last_name, child.last_name))) AS payer_name,
                recorder.name AS recorded_by_name, verifier.name AS verified_by_name
            {$joins}
            WHERE {$where}
            ORDER BY CASE payment.cheque_verification_status
                WHEN 'pending' THEN 0 WHEN 'rejected' THEN 1 ELSE 2 END,
                payment.payment_date DESC, payment.id DESC
            LIMIT ? OFFSET ?";
        $dataTypes = $types . 'ii';
        $dataParams = array_merge($params, [$perPage, $offset]);
        $dataStmt = $this->conn->prepare($dataSql);
        $dataStmt->bind_param($dataTypes, ...$dataParams);
        $dataStmt->execute();
        $rows = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $dataStmt->close();

        return [
            'rows' => $rows,
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
            'page' => $page,
            'per_page' => $perPage,
            'filters' => compact('status', 'search', 'dateFrom', 'dateTo'),
        ];
    }

    public function summary(int $churchId = 0): array {
        $conditions = ["LOWER(TRIM(payment.mode)) IN ('cheque', 'check')"];
        $types = '';
        $params = [];
        $this->appendChurchScope($conditions, $types, $params, $churchId);
        $where = implode(' AND ', $conditions);
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS total_count,
                    COALESCE(SUM(payment.amount), 0) AS total_value,
                    SUM(CASE WHEN payment.cheque_verification_status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                    COALESCE(SUM(CASE WHEN payment.cheque_verification_status = 'pending' THEN payment.amount ELSE 0 END), 0) AS pending_value,
                    SUM(CASE WHEN payment.cheque_verification_status = 'verified' THEN 1 ELSE 0 END) AS verified_count,
                    SUM(CASE WHEN payment.cheque_verification_status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count
             FROM payments payment WHERE {$where}"
        );
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $summary = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        return $summary;
    }

    public function listChurches(): array {
        if (!$this->superAdmin) return [];
        $result = $this->conn->query('SELECT id, name FROM churches ORDER BY name');
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function review(int $paymentId, string $decision, string $notes): void {
        if (!in_array($decision, ['verified', 'rejected'], true)) {
            throw new InvalidArgumentException('Choose Verify or Reject.');
        }
        $notes = mb_substr(trim($notes), 0, 500);
        if ($notes === '') throw new RuntimeException('Enter a review note for this cheque decision.');

        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare(
                "SELECT id, church_id, mode, bank_name, cheque_number, cheque_verification_status
                   FROM payments WHERE id = ? FOR UPDATE"
            );
            $stmt->bind_param('i', $paymentId);
            $stmt->execute();
            $payment = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$payment || $payment['cheque_verification_status'] !== 'pending') {
                throw new RuntimeException('The cheque is no longer pending verification.');
            }
            if (!in_array(strtolower(trim((string) $payment['mode'])), ['cheque', 'check'], true)) {
                throw new RuntimeException('Only cheque payments can be reviewed here.');
            }
            if (!$this->superAdmin && ($this->churchId === null || $this->churchId !== (int) $payment['church_id'])) {
                throw new RuntimeException('The cheque is outside your authorized church.');
            }
            if ($decision === 'verified'
                && (trim((string) $payment['bank_name']) === '' || trim((string) $payment['cheque_number']) === '')) {
                throw new RuntimeException('Add the bank name and cheque number before verification.');
            }

            $completedStatus = $decision === 'verified' ? 'Completed' : 'Rejected';
            $isVerified = $decision === 'verified' ? 1 : 0;
            $stmt = $this->conn->prepare(
                'UPDATE payments
                    SET status = ?, is_cheque_verified = ?, cheque_verification_status = ?,
                        cheque_verified_by = ?, cheque_verified_at = NOW(),
                        cheque_verification_notes = ?
                  WHERE id = ? AND cheque_verification_status = \'pending\''
            );
            $stmt->bind_param(
                'sisisi', $completedStatus, $isVerified, $decision,
                $this->actorUserId, $notes, $paymentId
            );
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                throw new RuntimeException('The cheque decision changed before your review was saved. Refresh and try again.');
            }
            $stmt->close();

            $action = $decision;
            $fromStatus = 'pending';
            $stmt = $this->conn->prepare(
                'INSERT INTO payment_cheque_verification_audit
                    (payment_id, action, from_status, to_status, notes, performed_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'issssi', $paymentId, $action, $fromStatus, $decision,
                $notes, $this->actorUserId
            );
            $stmt->execute();
            $stmt->close();
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    public function correctEvidence(
        int $paymentId,
        string $bankName,
        string $chequeNumber,
        string $reason
    ): void {
        $bankName = mb_substr(trim($bankName), 0, 120);
        $chequeNumber = mb_substr(trim($chequeNumber), 0, 100);
        $reason = mb_substr(trim($reason), 0, 500);
        if ($paymentId <= 0) throw new InvalidArgumentException('Choose a valid cheque payment.');
        if ($bankName === '' || $chequeNumber === '') {
            throw new InvalidArgumentException('Bank name and cheque number are required.');
        }
        if ($reason === '') {
            throw new InvalidArgumentException('Explain why the cheque evidence is being corrected.');
        }

        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare(
                "SELECT id, church_id, mode, bank_name, cheque_number, cheque_verification_status
                   FROM payments WHERE id = ? FOR UPDATE"
            );
            $stmt->bind_param('i', $paymentId);
            $stmt->execute();
            $payment = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$payment) throw new RuntimeException('The cheque payment was not found.');
            if ($payment['cheque_verification_status'] !== 'pending') {
                throw new RuntimeException('Only a pending cheque can have its evidence corrected.');
            }
            if (!in_array(strtolower(trim((string) $payment['mode'])), ['cheque', 'check'], true)) {
                throw new RuntimeException('Only cheque payments can have cheque evidence.');
            }
            if (!$this->superAdmin && ($this->churchId === null || $this->churchId !== (int) $payment['church_id'])) {
                throw new RuntimeException('The cheque is outside your authorized church.');
            }

            $previousBankName = trim((string) ($payment['bank_name'] ?? ''));
            $previousChequeNumber = trim((string) ($payment['cheque_number'] ?? ''));
            if ($previousBankName === $bankName && $previousChequeNumber === $chequeNumber) {
                throw new RuntimeException('Change the bank name or cheque number before saving.');
            }

            $stmt = $this->conn->prepare(
                "UPDATE payments
                    SET bank_name = ?, cheque_number = ?
                  WHERE id = ? AND cheque_verification_status = 'pending'"
            );
            $stmt->bind_param('ssi', $bankName, $chequeNumber, $paymentId);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                throw new RuntimeException('The cheque changed before the evidence was saved. Refresh and try again.');
            }
            $stmt->close();

            $stmt = $this->conn->prepare(
                "INSERT INTO payment_cheque_verification_audit
                    (payment_id, action, from_status, to_status, notes,
                     performed_by_user_id, previous_bank_name, new_bank_name,
                     previous_cheque_number, new_cheque_number)
                 VALUES (?, 'evidence_corrected', 'pending', 'pending', ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                'isissss',
                $paymentId,
                $reason,
                $this->actorUserId,
                $previousBankName,
                $bankName,
                $previousChequeNumber,
                $chequeNumber
            );
            $stmt->execute();
            $stmt->close();
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    private function appendChurchScope(array &$conditions, string &$types, array &$params, int $requestedChurchId): void {
        if (!$this->superAdmin) {
            if ($this->churchId === null) {
                $conditions[] = '1 = 0';
                return;
            }
            $conditions[] = 'payment.church_id = ?';
            $types .= 'i';
            $params[] = $this->churchId;
            return;
        }
        if ($requestedChurchId > 0) {
            $conditions[] = 'payment.church_id = ?';
            $types .= 'i';
            $params[] = $requestedChurchId;
        }
    }

    private function validDate(string $value): string {
        $value = trim($value);
        if ($value === '') return '';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }
}
