<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/member_auth.php';
require_once __DIR__ . '/../helpers/hubtel_status.php';

$memberId = (int) ($_SESSION['member_id'] ?? 0);
$reference = trim((string) ($_GET['reference'] ?? ''));
$notice = 'invalid_reference';

if ($memberId > 0
    && $reference !== ''
    && strlen($reference) <= 100
    && preg_match('/^[A-Za-z0-9._:-]+$/', $reference)) {
    $stmt = $conn->prepare(
        "SELECT intent.*,
                (SELECT COUNT(*)
                   FROM payments payment
                  WHERE payment.client_reference = intent.client_reference) AS posted_rows
           FROM payment_intents intent
          WHERE intent.client_reference = ?
            AND intent.member_id = ?
            AND intent.payment_source IN ('online_checkout', 'paystack')
          LIMIT 1"
    );
    $stmt->bind_param('si', $reference, $memberId);
    $stmt->execute();
    $intent = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$intent) {
        $notice = 'not_found';
    } elseif ((int) $intent['posted_rows'] > 0) {
        $notice = 'posted';
    } elseif ((string) $intent['payment_source'] !== 'online_checkout') {
        // Paystack performs its own verification in paystack_callback.php.
        $notice = 'pending';
    } else {
        try {
            $check = check_transaction_by_reference(
                $conn,
                $reference,
                $intent['hubtel_transaction_id'] ?? $intent['transaction_id'] ?? null,
                null
            );
            $currentStatus = strtolower(trim((string) ($check['current_status'] ?? $intent['status'] ?? 'pending')));
            $approvalStatus = strtolower(trim((string) ($check['approval_status'] ?? $intent['approval_status'] ?? 'not_required')));

            if (($check['gateway_verification_status'] ?? $check['verification_status'] ?? '') === 'verified'
                && $currentStatus === 'completed') {
                // A success discovered from the browser return remains subject
                // to authorized posting. Only the definitive server callback
                // is eligible for automatic ledger posting.
                $notice = $approvalStatus === 'approved' ? 'posted' : 'awaiting_posting';
            } elseif (in_array($currentStatus, ['failed', 'rejected', 'cancelled', 'canceled'], true)) {
                $notice = 'failed';
            } elseif (($check['verification_status'] ?? 'not_checked') === 'not_checked') {
                $notice = 'verification_unavailable';
            } else {
                $notice = 'pending';
            }
        } catch (Throwable $error) {
            error_log('Member payment return reconciliation failed for ' . $reference . ': ' . $error->getMessage());
            $notice = 'verification_unavailable';
        }
    }
}

header(
    'Location: ' . BASE_URL . '/views/payment_history.php?gateway_notice=' . rawurlencode($notice)
);
exit;

