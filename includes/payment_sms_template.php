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

function normalize_payment_sms_value($value) {
    return trim(preg_replace('/\s+/', ' ', (string) $value));
}

function get_payment_period_text($payment_period_description = null, $payment_period = null, $fallback_date = null) {
    $period_text = normalize_payment_sms_value($payment_period_description);
    if ($period_text !== '') {
        return $period_text;
    }

    foreach ([$payment_period, $fallback_date] as $candidate) {
        if (empty($candidate)) {
            continue;
        }

        $timestamp = strtotime((string) $candidate);
        if ($timestamp !== false) {
            return date('F Y', $timestamp);
        }
    }

    return '';
}

function get_payment_type_label($payment_type_name = '') {
    $payment_type_name = normalize_payment_sms_value($payment_type_name);
    return $payment_type_name === '' ? '' : strtoupper($payment_type_name);
}

function get_payment_reference_text($payment_period_description = null, $payment_period = null, $payment_type_name = '', $fallback_date = null) {
    $parts = [];
    $period_text = get_payment_period_text($payment_period_description, $payment_period, $fallback_date);
    $payment_type_label = get_payment_type_label($payment_type_name);

    if ($period_text !== '') {
        $parts[] = $period_text;
    }

    if ($payment_type_label !== '') {
        $parts[] = $payment_type_label;
    }

    return implode(' ', $parts);
}

function get_payment_period_year($payment_period = null, $payment_period_description = null, $fallback_date = null) {
    foreach ([$payment_period, $payment_period_description, $fallback_date] as $candidate) {
        if (empty($candidate)) {
            continue;
        }

        $timestamp = strtotime((string) $candidate);
        if ($timestamp !== false) {
            return (int) date('Y', $timestamp);
        }
    }

    return (int) date('Y');
}

function is_harvest_payment_type($payment_type_name = '') {
    return get_payment_type_label($payment_type_name) === 'HARVEST';
}

function build_hubtel_portal_payment_sms(
    $full_name,
    $amount,
    $payment_period_description,
    $payment_type_name,
    $crn,
    $church_name = 'Freeman Methodist Church - KM',
    $harvest_year = null,
    $harvest_total = null,
    $payment_period = null,
    $fallback_date = null
) {
    $full_name = normalize_payment_sms_value($full_name);
    $crn = normalize_payment_sms_value($crn);
    $church_name = normalize_payment_sms_value($church_name) ?: 'Freeman Methodist Church - KM';
    $reference_text = get_payment_reference_text($payment_period_description, $payment_period, $payment_type_name, $fallback_date);
    $formatted_amount = number_format((float) $amount, 2);

    $message = "Hi $full_name, your payment of GHS $formatted_amount";
    if ($reference_text !== '') {
        $message .= " for $reference_text";
    }
    $message .= " by $crn has been paid to $church_name.";

    if ($harvest_year !== null && $harvest_total !== null) {
        $formatted_total = number_format((float) $harvest_total, 2);
        $message .= " Your total HARVEST contribution for $harvest_year is now GHS $formatted_total.";
    }

    return $message . " Thank you, and God bless you more!";
}

function build_hubtel_ussd_member_payment_sms(
    $full_name,
    $amount,
    $payment_period_description,
    $payment_type_name,
    $sender_name,
    $church_name = 'Freeman Methodist Church - KM',
    $harvest_year = null,
    $harvest_total = null,
    $payment_period = null,
    $fallback_date = null
) {
    $full_name = normalize_payment_sms_value($full_name);
    $sender_name = normalize_payment_sms_value($sender_name) ?: $full_name;
    $church_name = normalize_payment_sms_value($church_name) ?: 'Freeman Methodist Church - KM';
    $reference_text = get_payment_reference_text($payment_period_description, $payment_period, $payment_type_name, $fallback_date);
    $formatted_amount = number_format((float) $amount, 2);

    $message = "Hello $full_name, your payment of GHS $formatted_amount";
    if ($reference_text !== '') {
        $message .= " for $reference_text";
    }
    $message .= " by $sender_name has been received by $church_name.";

    if ($harvest_year !== null && $harvest_total !== null) {
        $formatted_total = number_format((float) $harvest_total, 2);
        $message .= " Your total HARVEST contribution for $harvest_year is now GHS $formatted_total.";
    }

    return $message . " Thank you, and God bless you more!";
}

function build_hubtel_ussd_payer_confirmation_sms(
    $payer_name,
    $amount,
    $payment_period_description,
    $payment_type_name,
    $target_name,
    $church_name = 'Freeman Methodist Church - KM',
    $payment_period = null,
    $fallback_date = null
) {
    $payer_name = normalize_payment_sms_value($payer_name);
    $target_name = normalize_payment_sms_value($target_name);
    $church_name = normalize_payment_sms_value($church_name) ?: 'Freeman Methodist Church - KM';
    $reference_text = get_payment_reference_text($payment_period_description, $payment_period, $payment_type_name, $fallback_date);
    $formatted_amount = number_format((float) $amount, 2);

    $message = "Hello $payer_name, your payment of GHS $formatted_amount";
    if ($reference_text !== '') {
        $message .= " for $reference_text";
    }
    if ($target_name !== '') {
        $message .= " for $target_name";
    }
    $message .= " has been received by $church_name.";

    return $message . " Thank you, and God bless you more!";
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
function get_member_yearly_harvest_total($conn, $member_id, $year = null, $payment_type_id = 4) {
    $year = $year ?: date('Y');
    $payment_type_id = $payment_type_id ?: 4;
    
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as total 
        FROM payments 
        WHERE member_id = ? 
        AND payment_type_id = ?
        AND YEAR(COALESCE(NULLIF(payment_period, ''), payment_date)) = ?
        AND ((reversal_approved_at IS NULL) OR (reversal_undone_at IS NOT NULL))
    ");
    $stmt->bind_param('iii', $member_id, $payment_type_id, $year);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    return floatval($result['total']);
}
