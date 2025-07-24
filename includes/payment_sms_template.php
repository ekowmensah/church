<?php
// Default payment SMS template for MyFreeman
function get_payment_sms_message($member_name, $amount, $desc = '', $date = null) {
    $amount = number_format($amount, 2);
    $date = $date ? date('j M Y', strtotime($date)) : date('j M Y');
    $desc_part = $desc ? " for $desc" : '';
    return "Dear $member_name, your payment of ₵$amount$desc_part has been received by Freeman Methodist Church - KM. Thank you.";
}
