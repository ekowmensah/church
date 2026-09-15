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
        $where = "payment.cheque_verification_status = 'pending'";
        $types = '';
        $params = [];
        if (!$this->superAdmin) {
            if ($this->churchId === null) return [];
            $where .= ' AND payment.church_id = ?';
            $types = 'i';
            $params[] = $this->churchId;
        }
        $stmt = $this->conn->prepare(
            "SELECT payment.id, payment.amount, payment.payment_date,
                    payment.payment_period_description, payment.bank_name,
                    payment.cheque_number, payment.manual_batch_reference,
                    payment.recorded_by, type.name AS payment_type,
                    church.name AS church_name,
                    COALESCE(member.crn, child.srn, '') AS registration_number,
                    TRIM(CONCAT_WS(' ', COALESCE(member.first_name, child.first_name),
                        COALESCE(member.middle_name, child.middle_name),
                        COALESCE(member.last_name, child.last_name))) AS payer_name,
                    recorder.name AS recorded_by_name
               FROM payments payment
               LEFT JOIN members member ON member.id = payment.member_id
               LEFT JOIN sunday_school child ON child.id = payment.sundayschool_id
               LEFT JOIN payment_types type ON type.id = payment.payment_type_id
               LEFT JOIN churches church ON church.id = payment.church_id
               LEFT JOIN users recorder ON recorder.id = CAST(payment.recorded_by AS UNSIGNED)
              WHERE {$where}
              ORDER BY payment.payment_date, payment.id
              LIMIT 500"
        );
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
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
                "SELECT id, church_id, bank_name, cheque_number, cheque_verification_status
                   FROM payments WHERE id = ? FOR UPDATE"
            );
            $stmt->bind_param('i', $paymentId);
            $stmt->execute();
            $payment = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$payment || $payment['cheque_verification_status'] !== 'pending') {
                throw new RuntimeException('The cheque is no longer pending verification.');
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
                  WHERE id = ?'
            );
            $stmt->bind_param(
                'sisisi', $completedStatus, $isVerified, $decision,
                $this->actorUserId, $notes, $paymentId
            );
            $stmt->execute();
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
}
