<?php
// Model for payment_intents table
class PaymentIntent {
    public function add($conn, $data) {
        $fields = ['client_reference', 'hubtel_transaction_id', 'member_id', 'church_id', 'amount', 'description', 'customer_name', 'customer_phone', 'status', 'payment_type_id', 'payment_period', 'payment_period_description', 'bulk_breakdown', 'created_at'];
        $columns = [];
        $placeholders = [];
        $values = [];
        $types = '';
        foreach ($fields as $field) {
            $columns[] = $field;
            $placeholders[] = '?';
            if (isset($data[$field])) {
                $values[] = $data[$field];
            } elseif ($field === 'created_at') {
                // Set current timestamp for created_at if not provided
                $values[] = date('Y-m-d H:i:s');
            } else {
                $values[] = null;
            }
            // type guessing
            if (in_array($field, ['member_id', 'church_id', 'payment_type_id'])) {
                $types .= 'i';
            } elseif ($field === 'amount') {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }
        $sql = 'INSERT INTO payment_intents (' . implode(',', $columns) . ') VALUES (' . implode(',', $placeholders) . ')';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        if ($stmt->execute()) {
            return $conn->insert_id;
        }
        return false;
    }
    public function updateStatus($conn, $clientReference, $status) {
        $sql = 'UPDATE payment_intents SET status = ? WHERE client_reference = ?';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $status, $clientReference);
        return $stmt->execute();
    }
    public function getByReference($conn, $clientReference) {
        $sql = 'SELECT * FROM payment_intents WHERE client_reference = ?';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $clientReference);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
}
