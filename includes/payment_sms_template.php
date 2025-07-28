<?php
// Default payment SMS template for MyFreeman
function get_payment_sms_message($member_name, $amount, $payment_type = '', $date = null) {
    $amount = number_format($amount, 2);
    $payment_date = $date ? strtotime($date) : time();
    $month = date('F', $payment_date); // Full month name (e.g., July)
    
    // Build the payment description part
    $payment_part = '';
    if (!empty($payment_type)) {
        $payment_part = " for $month $payment_type";
    } elseif (!empty($month)) {
        $payment_part = " for $month";
    }
    
    return "Dear $member_name, your payment of ₵$amount$payment_part has been received by Freeman Methodist Church - KM. Thank You!";
}

// Special SMS template for harvest payments
function get_harvest_payment_sms_message($member_name, $amount, $church_name, $description, $yearly_total) {
    $amount = number_format($amount, 2);
    $yearly_total = number_format($yearly_total, 2);
    $year = date('Y');
    $description = $description ?: 'Harvest Payment';
    
    return "Hi $member_name, your payment of ₵$amount has been paid to Freeman Methodist Church - KM as $description. Your Total Harvest amount for the year $year is ₵$yearly_total";
}

// Helper function to calculate yearly harvest total for a member
function get_member_yearly_harvest_total($conn, $member_id, $year = null) {
    $year = $year ?: date('Y');
    $start_date = "$year-01-01";
    $end_date = "$year-12-31";
    
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as total 
        FROM payments 
        WHERE member_id = ? 
        AND payment_type_id = 4 
        AND payment_date >= ? 
        AND payment_date <= ? 
        AND ((reversal_approved_at IS NULL) OR (reversal_undone_at IS NOT NULL))
    ");
    $stmt->bind_param('iss', $member_id, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    return floatval($result['total']);
}
