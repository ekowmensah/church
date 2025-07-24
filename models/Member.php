<?php
// Model for members table
class Member {
    public function findById($conn, $id) {
        $stmt = $conn->prepare('SELECT * FROM members WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $member = $result->fetch_assoc();
        if (!$member) return null;
        // SMS opt-out (default to enabled if field missing)
        if (!isset($member['sms_notifications_enabled'])) {
            $member['sms_notifications_enabled'] = 1;
        }
        // Calculate balance
        $stmt2 = $conn->prepare('SELECT SUM(amount) as balance FROM payments WHERE member_id = ?');
        $stmt2->bind_param('i', $id);
        $stmt2->execute();
        $result2 = $stmt2->get_result();
        $row = $result2->fetch_assoc();
        $member['balance'] = $row && $row['balance'] ? $row['balance'] : 0.00;
        return $member;
    }
}
