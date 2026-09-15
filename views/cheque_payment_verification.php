<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/ChequePaymentVerificationService.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$roleIds = array_map('intval', (array) ($_SESSION['role_ids'] ?? []));
if (isset($_SESSION['role_id'])) $roleIds[] = (int) $_SESSION['role_id'];
$isSuperAdmin = !empty($_SESSION['is_super_admin'])
    || (int) ($_SESSION['user_id'] ?? 0) === 3
    || in_array(1, $roleIds, true);
if (!$isSuperAdmin && !has_permission('verify_cheque_payments')) {
    http_response_code(403);
    include __DIR__ . '/errors/403.php';
    exit;
}

$service = new ChequePaymentVerificationService($conn, (int) $_SESSION['user_id'], $isSuperAdmin);
$error = '';
$success = trim((string) ($_GET['message'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $error = 'Your form expired. Refresh the page and try again.';
    } else {
        try {
            $service->review(
                (int) ($_POST['payment_id'] ?? 0),
                (string) ($_POST['decision'] ?? ''),
                (string) ($_POST['review_notes'] ?? '')
            );
            $message = ($_POST['decision'] ?? '') === 'verified'
                ? 'Cheque verified and posted as a completed payment.'
                : 'Cheque rejected and retained in the audit history.';
            header('Location: cheque_payment_verification.php?message=' . rawurlencode($message));
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$pending = $service->listPending();
$pendingTotal = array_sum(array_map(static fn(array $row): float => (float) $row['amount'], $pending));

ob_start();
?>
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h3 mb-1 text-gray-800"><i class="fas fa-money-check-alt mr-2"></i>Cheque Verification</h1>
            <p class="text-muted mb-0">Pending cheques do not count as completed income until verified here.</p>
        </div>
        <a href="payment_list.php" class="btn btn-secondary mt-2 mt-md-0"><i class="fas fa-arrow-left mr-1"></i>Payment List</a>
    </div>

    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div class="row mb-3">
        <div class="col-md-6"><div class="card border-warning shadow-sm"><div class="card-body py-3"><div class="text-muted small text-uppercase">Pending cheques</div><div class="h3 mb-0"><?= number_format(count($pending)) ?></div></div></div></div>
        <div class="col-md-6"><div class="card border-info shadow-sm"><div class="card-body py-3"><div class="text-muted small text-uppercase">Pending value</div><div class="h3 mb-0">GH&#8373;<?= number_format($pendingTotal, 2) ?></div></div></div></div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white"><strong>Authorization queue</strong></div>
        <div class="table-responsive">
            <table class="table table-hover table-bordered mb-0">
                <thead class="thead-light">
                    <tr><th>Payer</th><th>Payment</th><th>Cheque</th><th>Period</th><th>Recorded</th><th style="min-width:260px">Decision</th></tr>
                </thead>
                <tbody>
                <?php foreach ($pending as $payment): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($payment['payer_name'] ?: 'Unknown payer') ?></strong><br><small><?= htmlspecialchars($payment['registration_number'] ?: 'No registration number') ?></small><?php if ($isSuperAdmin): ?><br><small class="text-muted"><?= htmlspecialchars($payment['church_name'] ?: 'No church') ?></small><?php endif; ?></td>
                        <td><?= htmlspecialchars($payment['payment_type'] ?: 'Unknown type') ?><br><strong>GH&#8373;<?= number_format((float) $payment['amount'], 2) ?></strong></td>
                        <td><strong><?= htmlspecialchars($payment['bank_name'] ?: 'Bank missing') ?></strong><br>No. <?= htmlspecialchars($payment['cheque_number'] ?: 'Missing') ?></td>
                        <td><?= htmlspecialchars($payment['payment_period_description'] ?: '-') ?></td>
                        <td><?= htmlspecialchars((string) $payment['payment_date']) ?><br><small><?= htmlspecialchars($payment['recorded_by_name'] ?: 'Legacy/unknown recorder') ?></small><?php if ($payment['manual_batch_reference']): ?><br><small class="text-muted"><?= htmlspecialchars($payment['manual_batch_reference']) ?></small><?php endif; ?></td>
                        <td>
                            <form method="post">
                                <?= csrf_input() ?>
                                <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                                <textarea class="form-control form-control-sm mb-2" name="review_notes" maxlength="500" rows="2" required placeholder="Verification or rejection note"></textarea>
                                <button class="btn btn-sm btn-success mr-1" name="decision" value="verified" onclick="return confirm('Verify and post this cheque as completed?')"><i class="fas fa-check mr-1"></i>Verify</button>
                                <button class="btn btn-sm btn-danger" name="decision" value="rejected" onclick="return confirm('Reject this cheque payment?')"><i class="fas fa-times mr-1"></i>Reject</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$pending): ?><tr><td colspan="6" class="text-center text-muted py-5"><i class="fas fa-check-circle fa-2x text-success d-block mb-2"></i>No cheques are awaiting verification in your scope.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
$page_title = 'Cheque Verification';
include __DIR__ . '/../includes/layout.php';
