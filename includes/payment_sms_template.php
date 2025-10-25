<?php
// Default payment SMS template for MyFreeman
function get_payment_sms_message($member_name, $amount, $payment_type = '', $date = null, $description = '') {
    $amount = number_format($amount, 2);
    $payment_date = $date ? strtotime($date) : time();
    $month = date('F', $payment_date); // Full month name (e.g., July)
    $year = date('Y', $payment_date);
    
    // Prefer description if provided
    if (!empty($description)) {
        $payment_part = " for $description";
    } else if (!empty($payment_type) && !empty($month)) {
        $payment_part = " for $month $year $payment_type";
    } else if (!empty($month)) {
        $payment_part = " for $month $year";
    } else if (!empty($payment_type)) {
        $payment_part = " for $payment_type";
    } else {
        $payment_part = '';
    }
    
    return "Dear $member_name, your payment of GHS $amount$payment_part has been received by Freeman Methodist Church - KM. Thank You, and God bless you more!!";
}

// Special SMS template for harvest payments
function get_harvest_payment_sms_message($member_name, $amount, $church_name, $description, $yearly_total) {
    $amount = number_format($amount, 2);
    $yearly_total = number_format($yearly_total, 2);
    $year = date('Y');
    $description = $description ?: 'Harvest Payment';
    
    return "Hi $member_name, your payment of GHS $amount for $description has been recieved by Freeman Methodist Church - KM. Your Total Harvest contribution for $year is now GHS $yearly_total. Thank you, and God bless you more!";
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
