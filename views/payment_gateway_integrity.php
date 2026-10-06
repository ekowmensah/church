<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../helpers/hubtel_status.php';
require_once __DIR__ . '/../services/PaymentGatewayIntegrityService.php';
require_once __DIR__ . '/../services/OnlinePaymentApprovalService.php';

if (!is_logged_in()) { header('Location: ' . BASE_URL . '/login.php'); exit; }
$isSuper = is_super_admin();
if (!$isSuper && !has_permission('review_payment_gateway_integrity')) {
    http_response_code(403); include __DIR__ . '/errors/403.php'; exit;
}

$service = new PaymentGatewayIntegrityService($conn, (int) $_SESSION['user_id'], $isSuper);
$approvalService = new OnlinePaymentApprovalService($conn, (int) $_SESSION['user_id'], $isSuper);
$error = '';
$success = trim((string) ($_GET['message'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(419); $error = 'Your form expired. Refresh and retry.'; }
    else {
        try {
            if (($_POST['action'] ?? '') === 'verify_gateway') {
                $intentId = (int) ($_POST['payment_intent_id'] ?? 0);
                $scopeSql = "SELECT intent.client_reference, intent.payment_source
                               FROM payment_intents intent
                              WHERE intent.id = ? AND intent.approval_status = 'pending'
                                AND intent.status_check_state <> 'archived'";
                if (!$isSuper) {
                    $scopeSql .= ' AND intent.church_id = (SELECT church_id FROM users WHERE id = ? LIMIT 1)';
                }
                $scopeStmt = $conn->prepare($scopeSql);
                if ($isSuper) {
                    $scopeStmt->bind_param('i', $intentId);
                } else {
                    $actorId = (int) $_SESSION['user_id'];
                    $scopeStmt->bind_param('ii', $intentId, $actorId);
                }
                $scopeStmt->execute();
                $intentToVerify = $scopeStmt->get_result()->fetch_assoc();
                $scopeStmt->close();
                if (!$intentToVerify) {
                    throw new RuntimeException('This pending payment is unavailable or outside your church scope.');
                }
                if (!in_array($intentToVerify['payment_source'], ['online_checkout', 'ussd', 'legacy_callback'], true)) {
                    throw new RuntimeException('This payment source cannot be verified through Hubtel Status Check.');
                }

                $verification = check_transaction_by_reference(
                    $conn,
                    (string) $intentToVerify['client_reference'],
                    null,
                    (int) $_SESSION['user_id']
                );
                if (empty($verification['success'])) {
                    throw new RuntimeException('Gateway verification failed: ' . ($verification['error'] ?? 'Unknown gateway response.'));
                }
                $freshStatus = strtolower(trim((string) ($verification['gateway_status_checked'] ?? '')));
                if (!in_array($freshStatus, ['completed', 'paid', 'success', 'successful', 'approved'], true)) {
                    $verificationState = in_array($freshStatus, ['failed', 'cancelled', 'canceled', 'declined', 'error'], true)
                        ? 'failed'
                        : 'not_checked';
                    $verificationUpdate = $conn->prepare(
                        'UPDATE payment_intents
                            SET gateway_verification_status = ?, gateway_verified_at = NULL
                          WHERE id = ?'
                    );
                    $verificationUpdate->bind_param('si', $verificationState, $intentId);
                    $verificationUpdate->execute();
                    $verificationUpdate->close();
                    throw new RuntimeException(
                        'Hubtel returned ' . ($freshStatus !== '' ? strtoupper($freshStatus) : 'UNKNOWN')
                        . '. Approval remains locked until Hubtel confirms success.'
                    );
                }
                header('Location: payment_gateway_integrity.php?message=' . rawurlencode(
                    'Gateway reference, amount, and successful status verified. Add a decision note, then approve and post.'
                ));
                exit;
            } elseif (isset($_POST['payment_intent_id'])) {
                $result = $approvalService->decide(
                    (int) $_POST['payment_intent_id'],
                    (string) ($_POST['approval_decision'] ?? ''),
                    (string) ($_POST['approval_notes'] ?? '')
                );
                $message = $result['decision'] === 'approved'
                    ? count($result['payment_ids']) . ' online payment line(s) approved and posted.'
                    : 'Online payment rejected without posting income.';
            } else {
                $service->review((int)($_POST['review_id'] ?? 0), (string)($_POST['decision'] ?? ''), (string)($_POST['review_notes'] ?? ''));
                $message = 'Gateway review decision recorded.';
            }
            header('Location: payment_gateway_integrity.php?message=' . rawurlencode($message)); exit;
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
}
$service->refresh();
$pendingApprovals = $approvalService->listPending();
$reviews = $service->listOpen();
$total = array_sum(array_map(static fn(array $row): float => (float)$row['expected_amount'], $reviews));
ob_start();
?>
<div class="container-fluid py-4">
 <div class="d-flex flex-wrap justify-content-between align-items-center mb-4"><div><h1 class="h3 mb-1"><i class="fas fa-shield-alt mr-2"></i>Payment Gateway Integrity</h1><p class="text-muted mb-0">Reconcile anomalies without turning an unverified intent into income.</p></div><div class="mt-3 mt-md-0"><a href="hubtel_status_archive.php" class="btn btn-outline-secondary mr-2"><i class="fas fa-archive mr-1"></i>Archived Checks</a><a href="hubtel_status_check.php" class="btn btn-info">Hubtel Status Check</a></div></div>
 <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
 <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
 <div class="alert alert-info"><i class="fas fa-info-circle mr-1"></i>Transactions archived after three status-check failures are retained under <a class="alert-link" href="hubtel_status_archive.php">Archived Checks</a> and are excluded from the open integrity count until restored.</div>
 <div class="card shadow-sm mb-4">
  <div class="card-header bg-primary text-white"><strong>Online payments awaiting approval (<?= number_format(count($pendingApprovals)) ?>)</strong></div>
  <div class="table-responsive"><table class="table table-bordered table-hover mb-0"><thead class="thead-light"><tr><th>Reference</th><th>Beneficiary</th><th>Source</th><th>Amount</th><th>Gateway</th><th style="min-width:300px">Decision</th></tr></thead><tbody>
  <?php foreach ($pendingApprovals as $row): ?><tr>
   <td><code><?= htmlspecialchars($row['client_reference']) ?></code><?php if ($isSuper): ?><br><small><?= htmlspecialchars($row['church_name'] ?: 'No church') ?></small><?php endif; ?></td>
   <td><?= htmlspecialchars($row['beneficiary_name'] ?: 'Needs beneficiary review') ?><br><small><?= htmlspecialchars($row['beneficiary_reference'] ?: 'No CRN/SRN') ?><?= $row['payment_type_name'] ? ' · ' . htmlspecialchars($row['payment_type_name']) : '' ?></small></td>
   <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $row['payment_source']))) ?></td>
   <td><strong>Credit: GH&#8373;<?= number_format((float) $row['amount'], 2) ?></strong><?php if ($row['gateway_customer_paid_amount'] !== null): ?><br><small>Customer paid: GH&#8373;<?= number_format((float) $row['gateway_customer_paid_amount'], 2) ?><br>Gateway charge: GH&#8373;<?= number_format((float) ($row['gateway_charge_amount'] ?? 0), 2) ?></small><?php endif; ?></td>
   <td><?= htmlspecialchars($row['gateway_status']) ?><br><span class="badge badge-<?= $row['gateway_verification_status'] === 'verified' ? 'success' : 'warning' ?>"><?= htmlspecialchars(str_replace('_', ' ', strtoupper($row['gateway_verification_status']))) ?></span><br><small><?= htmlspecialchars($row['approval_requested_at'] ?: $row['created_at']) ?></small></td>
   <td><form method="post"><?= csrf_input() ?><input type="hidden" name="payment_intent_id" value="<?= (int) $row['payment_intent_id'] ?>"><label class="small font-weight-bold mb-1">Decision note (required for approval/rejection)</label><textarea class="form-control form-control-sm mb-2" name="approval_notes" maxlength="500" required placeholder="State what was verified with the gateway."></textarea><?php if ($row['gateway_verification_status'] === 'verified'): ?><button class="btn btn-sm btn-success mr-1" name="approval_decision" value="approved">Approve &amp; post</button><?php else: ?><button class="btn btn-sm btn-warning mr-1" name="action" value="verify_gateway" formnovalidate><i class="fas fa-sync-alt mr-1"></i>Verify with Gateway</button><?php endif; ?><button class="btn btn-sm btn-danger" name="approval_decision" value="rejected">Reject</button><?php if ($row['gateway_verification_status'] !== 'verified'): ?><small class="form-text text-muted">Approval unlocks only after a fresh successful gateway verification.</small><?php endif; ?></form></td>
  </tr><?php endforeach; ?>
  <?php if (!$pendingApprovals): ?><tr><td colspan="6" class="text-center text-muted py-4">No successful online payments are awaiting approval.</td></tr><?php endif; ?>
  </tbody></table></div>
 </div>
 <div class="alert alert-warning"><strong><?= number_format(count($reviews)) ?></strong> open item(s), expected value <strong>GH&#8373;<?= number_format($total,2) ?></strong>. “Resolved” means the underlying gateway/payment data was corrected. “Accepted” documents a legitimate exception.</div>
 <div class="card shadow-sm"><div class="table-responsive"><table class="table table-bordered table-hover mb-0"><thead class="thead-light"><tr><th>Issue</th><th>Reference</th><th>Customer</th><th>Expected / observed</th><th>Intent</th><th style="min-width:280px">Review</th></tr></thead><tbody>
 <?php foreach ($reviews as $row): ?><tr><td><span class="badge badge-warning"><?= htmlspecialchars(str_replace('_',' ',strtoupper($row['issue_type']))) ?></span><br><small><?= htmlspecialchars($row['details']) ?></small></td><td><code><?= htmlspecialchars($row['client_reference']) ?></code><?php if ($isSuper): ?><br><small><?= htmlspecialchars($row['church_name'] ?: 'No church') ?></small><?php endif; ?></td><td><?= htmlspecialchars($row['customer_name']) ?><br><small><?= htmlspecialchars($row['customer_phone']) ?></small></td><td>GH&#8373;<?= number_format((float)$row['expected_amount'],2) ?><br><small>Observed: <?= $row['observed_amount'] === null ? '-' : 'GH₵'.number_format((float)$row['observed_amount'],2) ?></small></td><td><?= htmlspecialchars($row['intent_status']) ?><br><small><?= htmlspecialchars($row['intent_created_at']) ?></small></td><td><form method="post"><?= csrf_input() ?><input type="hidden" name="review_id" value="<?= (int)$row['id'] ?>"><textarea class="form-control form-control-sm mb-2" name="review_notes" maxlength="500" required placeholder="What was checked or corrected?"></textarea><button class="btn btn-sm btn-success mr-1" name="decision" value="resolved">Resolved</button><button class="btn btn-sm btn-secondary" name="decision" value="accepted">Accept exception</button></form></td></tr><?php endforeach; ?>
 <?php if (!$reviews): ?><tr><td colspan="6" class="text-center text-muted py-5">No open gateway integrity items in your scope.</td></tr><?php endif; ?>
 </tbody></table></div></div>
</div>
<?php $page_content=ob_get_clean(); $page_title='Payment Gateway Integrity'; include __DIR__ . '/../includes/layout.php';
