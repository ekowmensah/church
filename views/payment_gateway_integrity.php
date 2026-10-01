<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/PaymentGatewayIntegrityService.php';
require_once __DIR__ . '/../services/OnlinePaymentApprovalService.php';

if (!is_logged_in()) { header('Location: ' . BASE_URL . '/login.php'); exit; }
$roleIds = array_map('intval', (array) ($_SESSION['role_ids'] ?? []));
if (isset($_SESSION['role_id'])) $roleIds[] = (int) $_SESSION['role_id'];
$isSuper = !empty($_SESSION['is_super_admin']) || (int)($_SESSION['user_id'] ?? 0) === 3 || in_array(1, $roleIds, true);
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
            if (isset($_POST['payment_intent_id'])) {
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
   <td>GH&#8373;<?= number_format((float) $row['amount'], 2) ?></td>
   <td><?= htmlspecialchars($row['gateway_status']) ?><br><span class="badge badge-<?= $row['gateway_verification_status'] === 'verified' ? 'success' : 'warning' ?>"><?= htmlspecialchars(str_replace('_', ' ', strtoupper($row['gateway_verification_status']))) ?></span><br><small><?= htmlspecialchars($row['approval_requested_at'] ?: $row['created_at']) ?></small></td>
   <td><form method="post"><?= csrf_input() ?><input type="hidden" name="payment_intent_id" value="<?= (int) $row['payment_intent_id'] ?>"><textarea class="form-control form-control-sm mb-2" name="approval_notes" maxlength="500" required placeholder="State what was verified with the gateway."></textarea><button class="btn btn-sm btn-success mr-1" name="approval_decision" value="approved" <?= $row['gateway_verification_status'] === 'verified' ? '' : 'disabled title="Run a gateway status check first"' ?>>Approve &amp; post</button><button class="btn btn-sm btn-danger" name="approval_decision" value="rejected">Reject</button></form></td>
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
