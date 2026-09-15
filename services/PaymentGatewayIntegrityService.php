<?php

final class PaymentGatewayIntegrityService {
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

    public function refresh(): void {
        $this->conn->query(
            "INSERT INTO payment_gateway_integrity_reviews
                (payment_intent_id, issue_type, expected_amount, observed_amount, details)
             SELECT intent.id, 'completed_without_payment', intent.amount, 0,
                    'Hubtel intent is Completed but no payment row exists for its client reference.'
               FROM payment_intents intent
               LEFT JOIN payments payment ON payment.client_reference = intent.client_reference
              WHERE LOWER(intent.status) = 'completed'
              GROUP BY intent.id, intent.amount HAVING COUNT(payment.id) = 0
             ON DUPLICATE KEY UPDATE expected_amount=VALUES(expected_amount), observed_amount=VALUES(observed_amount), details=VALUES(details)"
        );
        $this->conn->query(
            "INSERT INTO payment_gateway_integrity_reviews
                (payment_intent_id, issue_type, expected_amount, observed_amount, details)
             SELECT intent.id, 'amount_mismatch', intent.amount, COALESCE(SUM(payment.amount),0),
                    'Completed Hubtel intent amount differs from the linked payment-line total.'
               FROM payment_intents intent
               JOIN payments payment ON payment.client_reference = intent.client_reference
              WHERE LOWER(intent.status) = 'completed'
              GROUP BY intent.id, intent.amount
             HAVING ABS(intent.amount-COALESCE(SUM(payment.amount),0)) > 0.01
             ON DUPLICATE KEY UPDATE expected_amount=VALUES(expected_amount), observed_amount=VALUES(observed_amount), details=VALUES(details)"
        );
        $this->conn->query(
            "INSERT INTO payment_gateway_integrity_reviews
                (payment_intent_id, issue_type, expected_amount, observed_amount, details)
             SELECT intent.id, 'stale_pending', intent.amount, NULL,
                    'Payment intent has remained Pending for more than 24 hours; reconcile it with Hubtel.'
               FROM payment_intents intent
              WHERE LOWER(intent.status)='pending' AND intent.created_at < NOW()-INTERVAL 1 DAY
             ON DUPLICATE KEY UPDATE expected_amount=VALUES(expected_amount), details=VALUES(details)"
        );
    }

    public function listOpen(): array {
        $where = "review.status = 'open'";
        $types = '';
        $params = [];
        if (!$this->superAdmin) {
            if ($this->churchId === null) return [];
            $where .= ' AND intent.church_id = ?';
            $types = 'i';
            $params[] = $this->churchId;
        }
        $stmt = $this->conn->prepare(
            "SELECT review.*, intent.client_reference, intent.status AS intent_status,
                    intent.customer_name, intent.customer_phone, intent.created_at AS intent_created_at,
                    church.name AS church_name
               FROM payment_gateway_integrity_reviews review
               JOIN payment_intents intent ON intent.id = review.payment_intent_id
               LEFT JOIN churches church ON church.id = intent.church_id
              WHERE {$where} ORDER BY review.created_at, review.id LIMIT 500"
        );
        if ($types) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function review(int $reviewId, string $decision, string $notes): void {
        if (!in_array($decision, ['resolved', 'accepted'], true)) {
            throw new InvalidArgumentException('Choose Resolved or Accepted exception.');
        }
        $notes = mb_substr(trim($notes), 0, 500);
        if ($notes === '') throw new RuntimeException('A review note is required.');
        $sql = "UPDATE payment_gateway_integrity_reviews review
                JOIN payment_intents intent ON intent.id = review.payment_intent_id
                   SET review.status=?, review.reviewed_by_user_id=?, review.reviewed_at=NOW(),
                       review.review_notes=?
                 WHERE review.id=? AND review.status='open'";
        $types = 'sisi';
        $params = [$decision, $this->actorUserId, $notes, $reviewId];
        if (!$this->superAdmin) {
            if ($this->churchId === null) throw new RuntimeException('No church scope is assigned to this reviewer.');
            $sql .= ' AND intent.church_id=?';
            $types .= 'i';
            $params[] = $this->churchId;
        }
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        if ($changed !== 1) throw new RuntimeException('The review item is unavailable or outside your scope.');
    }
}
