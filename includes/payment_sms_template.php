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
