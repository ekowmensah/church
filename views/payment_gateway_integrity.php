<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/PaymentGatewayIntegrityService.php';

if (!is_logged_in()) { header('Location: ' . BASE_URL . '/login.php'); exit; }
$roleIds = array_map('intval', (array) ($_SESSION['role_ids'] ?? []));
if (isset($_SESSION['role_id'])) $roleIds[] = (int) $_SESSION['role_id'];
$isSuper = !empty($_SESSION['is_super_admin']) || (int)($_SESSION['user_id'] ?? 0) === 3 || in_array(1, $roleIds, true);
if (!$isSuper && !has_permission('review_payment_gateway_integrity')) {
    http_response_code(403); include __DIR__ . '/errors/403.php'; exit;
}

$service = new PaymentGatewayIntegrityService($conn, (int) $_SESSION['user_id'], $isSuper);
$error = '';
$success = trim((string) ($_GET['message'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(419); $error = 'Your form expired. Refresh and retry.'; }
    else {
        try {
            $service->review((int)($_POST['review_id'] ?? 0), (string)($_POST['decision'] ?? ''), (string)($_POST['review_notes'] ?? ''));
            header('Location: payment_gateway_integrity.php?message=' . rawurlencode('Gateway review decision recorded.')); exit;
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
}
$service->refresh();
$reviews = $service->listOpen();
$total = array_sum(array_map(static fn(array $row): float => (float)$row['expected_amount'], $reviews));
ob_start();
?>
<div class="container-fluid py-4">
 <div class="d-flex justify-content-between align-items-center mb-4"><div><h1 class="h3 mb-1"><i class="fas fa-shield-alt mr-2"></i>Payment Gateway Integrity</h1><p class="text-muted mb-0">Reconcile anomalies without turning an unverified intent into income.</p></div><a href="hubtel_status_check.php" class="btn btn-info">Hubtel Status Check</a></div>
 <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
 <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
 <div class="alert alert-warning"><strong><?= number_format(count($reviews)) ?></strong> open item(s), expected value <strong>GH&#8373;<?= number_format($total,2) ?></strong>. “Resolved” means the underlying gateway/payment data was corrected. “Accepted” documents a legitimate exception.</div>
 <div class="card shadow-sm"><div class="table-responsive"><table class="table table-bordered table-hover mb-0"><thead class="thead-light"><tr><th>Issue</th><th>Reference</th><th>Customer</th><th>Expected / observed</th><th>Intent</th><th style="min-width:280px">Review</th></tr></thead><tbody>
 <?php foreach ($reviews as $row): ?><tr><td><span class="badge badge-warning"><?= htmlspecialchars(str_replace('_',' ',strtoupper($row['issue_type']))) ?></span><br><small><?= htmlspecialchars($row['details']) ?></small></td><td><code><?= htmlspecialchars($row['client_reference']) ?></code><?php if ($isSuper): ?><br><small><?= htmlspecialchars($row['church_name'] ?: 'No church') ?></small><?php endif; ?></td><td><?= htmlspecialchars($row['customer_name']) ?><br><small><?= htmlspecialchars($row['customer_phone']) ?></small></td><td>GH&#8373;<?= number_format((float)$row['expected_amount'],2) ?><br><small>Observed: <?= $row['observed_amount'] === null ? '-' : 'GH₵'.number_format((float)$row['observed_amount'],2) ?></small></td><td><?= htmlspecialchars($row['intent_status']) ?><br><small><?= htmlspecialchars($row['intent_created_at']) ?></small></td><td><form method="post"><?= csrf_input() ?><input type="hidden" name="review_id" value="<?= (int)$row['id'] ?>"><textarea class="form-control form-control-sm mb-2" name="review_notes" maxlength="500" required placeholder="What was checked or corrected?"></textarea><button class="btn btn-sm btn-success mr-1" name="decision" value="resolved">Resolved</button><button class="btn btn-sm btn-secondary" name="decision" value="accepted">Accept exception</button></form></td></tr><?php endforeach; ?>
 <?php if (!$reviews): ?><tr><td colspan="6" class="text-center text-muted py-5">No open gateway integrity items in your scope.</td></tr><?php endif; ?>
 </tbody></table></div></div>
</div>
<?php $page_content=ob_get_clean(); $page_title='Payment Gateway Integrity'; include __DIR__ . '/../includes/layout.php';
