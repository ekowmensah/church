<?php
// Model for payments table
class Payment {
    public function add($conn, $data) {
        $stmt = $conn->prepare('INSERT INTO payments (member_id, amount, type, payment_date) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('idss', $data['member_id'], $data['amount'], $data['type'], $data['payment_date']);
        if ($stmt->execute()) {
            return $conn->insert_id;
        }
        return false;
    }
}
