<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/sms.php';
require_once __DIR__.'/../includes/sms_templates.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/csrf.php';
require_once __DIR__.'/../helpers/church_helper.php';

// Canonical permission check for Bulk SMS (send)
if (!is_logged_in() || (!is_super_admin() && !has_permission('send_bulk_sms'))) {
    http_response_code(403);
    exit('Forbidden: You do not have permission to access this resource.');
}

header('Content-Type: text/plain');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    exit('Your session token is invalid. Refresh the page and try again.');
}

$type = $_POST['recipient_type'] ?? '';
$class_ids = $_POST['class_ids'] ?? [];
$organization_ids = $_POST['organization_ids'] ?? [];
$church_ids = $_POST['church_ids'] ?? [];
$custom_phones = $_POST['custom_phones'] ?? '';
$template_id = $_POST['template_id'] ?? '';
$message = trim($_POST['message'] ?? '');

$recipients = [];
$recipient_names = [];
$isSuperAdmin = is_super_admin();
$currentChurchId = $isSuperAdmin ? 0 : (int) get_user_church_id($conn);

$class_ids = array_values(array_unique(array_filter(array_map('intval', (array) $class_ids))));
$organization_ids = array_values(array_unique(array_filter(array_map('intval', (array) $organization_ids))));
$church_ids = array_values(array_unique(array_filter(array_map('intval', (array) $church_ids))));
if (!$isSuperAdmin) {
    $church_ids = [$currentChurchId];
}

// 1. Get recipients by type
if ($type === 'class' && !empty($class_ids)) {
    $in = implode(',', $class_ids);
    $scope = $isSuperAdmin ? '' : ' AND church_id = ' . $currentChurchId;
    $q = $conn->query("SELECT phone, first_name, last_name, crn, church_id FROM members WHERE class_id IN ($in){$scope} AND phone IS NOT NULL AND phone != ''");
    while($m = $q->fetch_assoc()) {
        $recipients[] = $m['phone'];
        $recipient_names[$m['phone']] = $m;
    }
} elseif ($type === 'organization' && !empty($organization_ids)) {
    $in = implode(',', $organization_ids);
    $scope = $isSuperAdmin ? '' : ' AND member.church_id = ' . $currentChurchId;
    $q = $conn->query("SELECT DISTINCT member.phone, member.first_name, member.last_name, member.crn, member.church_id
                       FROM members member
                       JOIN member_organizations membership ON membership.member_id = member.id
                       WHERE membership.organization_id IN ($in){$scope}
                         AND member.phone IS NOT NULL AND member.phone != ''");
    while($m = $q->fetch_assoc()) {
        $recipients[] = $m['phone'];
        $recipient_names[$m['phone']] = $m;
    }
} elseif ($type === 'all' && !empty($church_ids)) {
    $in = implode(',', $church_ids);
    $q = $conn->query("SELECT phone, first_name, last_name, crn, church_id FROM members WHERE church_id IN ($in) AND phone IS NOT NULL AND phone != ''");
    while($m = $q->fetch_assoc()) {
        $recipients[] = $m['phone'];
        $recipient_names[$m['phone']] = $m;
    }
} elseif ($type === 'custom' && trim($custom_phones)) {
    $lines = preg_split('/[\r\n,]+/', $custom_phones);
    foreach($lines as $ph) {
        $ph = trim($ph);
        if ($ph) $recipients[] = $ph;
    }
}
if (empty($recipients)) {
    http_response_code(400);
    exit('No recipients found.');
}
$recipients = array_unique($recipients);

// 2. Prepare message using template if selected
if ($template_id) {
    $tpl = $conn->query("SELECT * FROM sms_templates WHERE id=".intval($template_id))->fetch_assoc();
    if ($tpl) {
        $body = $tpl['body'];
        // If recipient is a member, fill template
        $msgs = [];
        foreach($recipients as $ph) {
            $vars = [];
            if (isset($recipient_names[$ph])) {
                $m = $recipient_names[$ph];
                $vars = [
                    'name' => $m['first_name'],
                    'first_name' => $m['first_name'],
                    'last_name' => $m['last_name'] ?? '',
                    'other_name' => $m['other_name'] ?? '',
                    'crn' => $m['crn'] ?? '',
                    'phone' => $ph,
                ];
                // Optionally fetch class and church names
                $member_id = $m['crn'] ?? null;
                if ($member_id) {
                    $class_row = $conn->query("SELECT c.name FROM bible_classes c JOIN members m ON m.class_id = c.id WHERE m.crn = '".$conn->real_escape_string($member_id)."' LIMIT 1")->fetch_assoc();
                    $vars['class'] = $class_row ? $class_row['name'] : '';
                    $church_row = $conn->query("SELECT ch.name FROM churches ch JOIN members m ON m.church_id = ch.id WHERE m.crn = '".$conn->real_escape_string($member_id)."' LIMIT 1")->fetch_assoc();
                    $vars['church'] = $church_row ? $church_row['name'] : '';
                } else {
                    $vars['class'] = '';
                    $vars['church'] = '';
                }
            }
            $msgs[$ph] = fill_sms_template($body, $vars);
        }
    } else {
        $msgs = array_fill_keys($recipients, $message);
    }
} else {
    $msgs = array_fill_keys($recipients, $message);
}

// 3. Send SMS (one by one for personalization)
$success = 0; $fail = 0;
foreach($msgs as $ph => $msg) {
    $recipientChurchId = (int) ($recipient_names[$ph]['church_id'] ?? $currentChurchId);
    $resp = log_sms(
        $ph,
        $msg,
        null,
        'bulk',
        null,
        [
            'church_id' => $recipientChurchId > 0 ? $recipientChurchId : null,
            'sent_by' => (int) ($_SESSION['user_id'] ?? 0),
        ]
    );
    // Handle Arkesel v2 API response format
    $status = 'fail';
    if (isset($resp['status']) && $resp['status'] === 'success') {
        $status = 'sent';
    } elseif (isset($resp['data']['status']) && $resp['data']['status'] === 'Sent') {
        $status = 'sent';
    } elseif (isset($resp['code']) && ($resp['code'] == 0 || $resp['code'] === '2000')) {
        // Fallback for other API versions
        $status = 'sent';
    }
    if ($status === 'sent') $success++; else $fail++;
}

exit("SMS sent: $success, Failed: $fail");
