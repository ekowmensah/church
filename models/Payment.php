<?php
// Model for payments table
class Payment {
    public function add($conn, $data) {
        $fields = ['member_id', 'amount', 'description', 'payment_date', 'client_reference', 'status', 'church_id', 'payment_type_id', 'payment_period', 'payment_period_description', 'recorded_by', 'mode'];
        $columns = [];
        $placeholders = [];
        $values = [];
        $types = '';
        foreach ($fields as $field) {
            $columns[] = $field;
            $placeholders[] = '?';
            if (isset($data[$field])) {
                $values[] = $data[$field];
            } else {
                $values[] = null;
            }
            // type guessing
            if (in_array($field, ['member_id', 'church_id', 'payment_type_id'])) {
                $types .= 'i';
            } elseif ($field === 'recorded_by' || $field === 'mode') {
                $types .= 's';
            } elseif ($field === 'amount') {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }
        $sql = 'INSERT INTO payments (' . implode(',', $columns) . ') VALUES (' . implode(',', $placeholders) . ')';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        if ($stmt->execute()) {
            return $conn->insert_id;
        }
        return false;
    }
}
