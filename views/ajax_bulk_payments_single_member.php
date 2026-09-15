<?php

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/PaymentEntryService.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'msg' => 'Authentication required.']);
    exit;
}

$roleIds = array_map('intval', (array) ($_SESSION['role_ids'] ?? []));
if (isset($_SESSION['role_id'])) $roleIds[] = (int) $_SESSION['role_id'];
$isSuperAdmin = !empty($_SESSION['is_super_admin'])
    || (int) ($_SESSION['user_id'] ?? 0) === 3
    || in_array(1, $roleIds, true);
if (!$isSuperAdmin && !has_permission('create_payment')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'msg' => 'Permission denied.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'msg' => 'Invalid JSON request.']);
    exit;
}
if (!csrf_is_valid($input['csrf_token'] ?? null)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'msg' => 'Security token expired. Refresh the page and try again.']);
    exit;
}

try {
    $service = new PaymentEntryService($conn, (int) $_SESSION['user_id'], $isSuperAdmin);
    $result = $service->recordForPerson(
        (int) ($input['member_id'] ?? 0),
        (int) ($input['sundayschool_id'] ?? 0),
        (array) ($input['payments'] ?? []),
        !empty($input['cheque_entry_confirmed'])
    );

    $smsSent = false;
    try {
        if (!empty($result['payment_ids'])) {
            require_once __DIR__ . '/../includes/payment_sms_template.php';
            require_once __DIR__ . '/../includes/sms.php';
            $lookup = $conn->prepare(
            "SELECT payment.id, payment.member_id, payment.amount, payment.mode,
                    payment.payment_date, payment.payment_period_description,
                    payment.description, payment.payment_type_id,
                    type.name AS payment_type, church.name AS church_name,
                    COALESCE(member.first_name, child.first_name) AS first_name,
                    COALESCE(member.last_name, child.last_name) AS last_name,
                    COALESCE(member.phone, child.contact) AS phone
               FROM payments payment
               LEFT JOIN members member ON member.id = payment.member_id
               LEFT JOIN sunday_school child ON child.id = payment.sundayschool_id
               LEFT JOIN payment_types type ON type.id = payment.payment_type_id
               LEFT JOIN churches church ON church.id = payment.church_id
              WHERE payment.id = ?"
            );
            foreach ($result['payment_ids'] as $paymentId) {
                $lookup->bind_param('i', $paymentId);
                $lookup->execute();
                $payment = $lookup->get_result()->fetch_assoc();
                if (!$payment || $payment['mode'] !== 'Cash' || trim((string) $payment['phone']) === '') continue;
                $name = trim($payment['first_name'] . ' ' . $payment['last_name']);
                if ((int) $payment['payment_type_id'] === 4 && (int) $payment['member_id'] > 0) {
                    $message = get_harvest_payment_sms_message(
                        $name, (float) $payment['amount'],
                        $payment['church_name'] ?: 'Freeman Methodist Church',
                        (string) $payment['description'],
                        get_member_yearly_harvest_total($conn, (int) $payment['member_id'])
                    );
                    $smsType = 'harvest_payment';
                } else {
                    $message = get_payment_sms_message(
                        $name, (float) $payment['amount'],
                        $payment['payment_type'] ?: 'Payment',
                        $payment['payment_period_description'] ?: date('F Y', strtotime($payment['payment_date'])),
                        (string) $payment['description']
                    );
                    $smsType = 'payment';
                }
                $delivery = log_sms((string) $payment['phone'], $message, (int) $payment['id'], $smsType);
                $smsSent = $smsSent || (($delivery['status'] ?? '') === 'success');
            }
            $lookup->close();
        }
    } catch (Throwable $smsException) {
        // The committed financial transaction must not be reported as failed
        // merely because the optional receipt notification was unavailable.
        error_log('Payment saved but receipt SMS failed: ' . $smsException->getMessage());
    }

    echo json_encode([
        'success' => true,
        'msg' => $result['cheque_count'] > 0
            ? 'Payment lines saved. Cheques remain pending until authorized verification.'
            : 'Payments recorded.',
        'batch_reference' => $result['batch_reference'],
        'payment_ids' => $result['payment_ids'],
        'cash_count' => $result['cash_count'],
        'cheque_count' => $result['cheque_count'],
        'sms_sent' => $smsSent,
    ]);
} catch (InvalidArgumentException|RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'msg' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Payment entry failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'msg' => 'The payment could not be recorded.']);
}
