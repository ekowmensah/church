<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/hubtel_status.php';
require_once __DIR__ . '/../helpers/csrf.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$roleIds = array_map('intval', (array) ($_SESSION['role_ids'] ?? []));
if (isset($_SESSION['role_id'])) {
    $roleIds[] = (int) $_SESSION['role_id'];
}
$userId = (int) ($_SESSION['user_id'] ?? 0);
$isSuperAdmin = !empty($_SESSION['is_super_admin'])
    || $userId === 3
    || in_array(1, $roleIds, true);

if (!$isSuperAdmin && !has_permission('manage_payments')) {
    http_response_code(403);
    if (file_exists(__DIR__ . '/errors/403.php')) {
        include __DIR__ . '/errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    }
    exit;
}

$churchId = 0;
if (!$isSuperAdmin) {
    $churchStmt = $conn->prepare('SELECT church_id FROM users WHERE id = ? LIMIT 1');
    $churchStmt->bind_param('i', $userId);
    $churchStmt->execute();
    $churchId = (int) ($churchStmt->get_result()->fetch_assoc()['church_id'] ?? 0);
    $churchStmt->close();
}

$error = '';
$success = trim((string) ($_GET['message'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $error = 'Your form expired. Refresh the page and try again.';
    } elseif (($_POST['action'] ?? '') === 'restore_archived') {
        try {
            $intentId = (int) ($_POST['payment_intent_id'] ?? 0);
            $scopeSql = "SELECT id FROM payment_intents WHERE id = ? AND status_check_state = 'archived'";
            if (!$isSuperAdmin) {
                $scopeSql .= ' AND church_id = ?';
            }
            $scopeStmt = $conn->prepare($scopeSql);
            if ($isSuperAdmin) {
                $scopeStmt->bind_param('i', $intentId);
            } else {
                $scopeStmt->bind_param('ii', $intentId, $churchId);
            }
            $scopeStmt->execute();
            $allowed = (bool) $scopeStmt->get_result()->fetch_assoc();
            $scopeStmt->close();
            if (!$allowed) {
                throw new RuntimeException('The archived transaction was not found in your authorized church.');
            }

            restore_archived_hubtel_status_check($conn, $intentId, $userId);
            header('Location: hubtel_status_archive.php?message=' . rawurlencode(
                'The transaction was restored to the active status-check queue with a fresh three-check allowance.'
            ));
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$search = trim((string) ($_GET['search'] ?? ''));
$like = '%' . $search . '%';
$listSql = "
    SELECT pi.id, pi.client_reference, pi.payment_source, pi.status,
           pi.customer_name, pi.customer_phone, pi.amount,
           pi.status_check_attempts, pi.status_check_archive_reason,
           pi.status_check_archived_at, pi.last_status_checked_at,
           c.name AS church_name,
           COALESCE(
               NULLIF(TRIM(CONCAT_WS(' ', m.first_name, m.middle_name, m.last_name)), ''),
               NULLIF(TRIM(CONCAT_WS(' ', child.first_name, child.middle_name, child.last_name)), ''),
               NULLIF(TRIM(pi.customer_name), ''),
               'Unknown beneficiary'
           ) AS beneficiary_name,
           COALESCE(NULLIF(m.crn, ''), NULLIF(child.srn, ''), '') AS beneficiary_reference
      FROM payment_intents pi
      LEFT JOIN members m ON m.id = pi.member_id
      LEFT JOIN sunday_school child ON child.id = pi.sundayschool_id
      LEFT JOIN churches c ON c.id = pi.church_id
     WHERE pi.status_check_state = 'archived'";
if (!$isSuperAdmin) {
    $listSql .= ' AND pi.church_id = ?';
}
if ($search !== '') {
    $listSql .= " AND (
        pi.client_reference LIKE ? OR pi.customer_name LIKE ? OR
        m.crn LIKE ? OR child.srn LIKE ? OR
        CONCAT_WS(' ', m.first_name, m.middle_name, m.last_name) LIKE ? OR
        CONCAT_WS(' ', child.first_name, child.middle_name, child.last_name) LIKE ?
    )";
}
$listSql .= ' ORDER BY pi.status_check_archived_at DESC, pi.id DESC LIMIT 100';

$listStmt = $conn->prepare($listSql);
if (!$isSuperAdmin && $search !== '') {
    $listStmt->bind_param('issssss', $churchId, $like, $like, $like, $like, $like, $like);
} elseif (!$isSuperAdmin) {
    $listStmt->bind_param('i', $churchId);
} elseif ($search !== '') {
    $listStmt->bind_param('ssssss', $like, $like, $like, $like, $like, $like);
}
$listStmt->execute();
$archivedIntents = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$listStmt->close();

$selectedIntent = null;
$auditRows = [];
$selectedId = (int) ($_GET['intent_id'] ?? 0);
if ($selectedId > 0) {
    $detailSql = "
        SELECT pi.id, pi.client_reference, pi.amount, pi.status_check_attempts,
               pi.status_check_archive_reason, pi.status_check_archived_at,
               c.name AS church_name
          FROM payment_intents pi
          LEFT JOIN churches c ON c.id = pi.church_id
         WHERE pi.id = ? AND pi.status_check_state = 'archived'";
    if (!$isSuperAdmin) {
        $detailSql .= ' AND pi.church_id = ?';
    }
    $detailStmt = $conn->prepare($detailSql);
    if ($isSuperAdmin) {
        $detailStmt->bind_param('i', $selectedId);
    } else {
        $detailStmt->bind_param('ii', $selectedId, $churchId);
    }
    $detailStmt->execute();
    $selectedIntent = $detailStmt->get_result()->fetch_assoc();
    $detailStmt->close();

    if ($selectedIntent) {
        $auditStmt = $conn->prepare(
            "SELECT audit.*, user_account.name AS checked_by_name
               FROM payment_status_check_audit audit
               LEFT JOIN users user_account ON user_account.id = audit.checked_by_user_id
              WHERE audit.payment_intent_id = ?
              ORDER BY audit.created_at DESC, audit.id DESC"
        );
        $auditStmt->bind_param('i', $selectedId);
        $auditStmt->execute();
        $auditRows = $auditStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $auditStmt->close();
    }
}

ob_start();
?>
<div class="container-fluid py-4">
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center">
            <div>
                <h1 class="h3 mb-1"><i class="fas fa-archive mr-2 text-secondary"></i>Archived Hubtel Checks</h1>
                <p class="text-muted mb-0">Transactions retained after three transaction-specific verification failures.</p>
            </div>
            <a href="hubtel_status_check.php" class="btn btn-primary mt-3 mt-md-0">
                <i class="fas fa-arrow-left mr-1"></i>Active Status Checks
            </a>
        </div>
    </div>

    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="get" class="form-row align-items-end">
                <div class="col-md-8 col-lg-6">
                    <label for="archive-search">Search archived checks</label>
                    <input id="archive-search" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>"
                           placeholder="Client reference, CRN/SRN, customer or beneficiary name">
                </div>
                <div class="col-auto mt-3 mt-md-0">
                    <button class="btn btn-secondary" type="submit"><i class="fas fa-search mr-1"></i>Search</button>
                    <?php if ($search !== ''): ?><a class="btn btn-link" href="hubtel_status_archive.php">Clear</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <?php if ($selectedIntent): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <strong><i class="fas fa-history mr-2"></i>Check History: <?= htmlspecialchars($selectedIntent['client_reference']) ?></strong>
            <a class="btn btn-sm btn-outline-light" href="hubtel_status_archive.php<?= $search !== '' ? '?search=' . rawurlencode($search) : '' ?>">Close history</a>
        </div>
        <div class="card-body border-bottom">
            <div class="row">
                <div class="col-md-3"><small class="text-muted d-block">Expected amount</small><strong>GH&#8373;<?= number_format((float) $selectedIntent['amount'], 2) ?></strong></div>
                <div class="col-md-3"><small class="text-muted d-block">Counted failures</small><strong><?= (int) $selectedIntent['status_check_attempts'] ?> / 3</strong></div>
                <div class="col-md-3"><small class="text-muted d-block">Archived</small><strong><?= htmlspecialchars($selectedIntent['status_check_archived_at'] ?: '-') ?></strong></div>
                <?php if ($isSuperAdmin): ?><div class="col-md-3"><small class="text-muted d-block">Church</small><strong><?= htmlspecialchars($selectedIntent['church_name'] ?: 'No church') ?></strong></div><?php endif; ?>
            </div>
            <small class="text-muted d-block mt-3"><?= htmlspecialchars($selectedIntent['status_check_archive_reason'] ?: 'Archived after three failed verification attempts.') ?></small>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
                <thead class="thead-light"><tr><th>Date</th><th>Attempt</th><th>Outcome</th><th>Counted</th><th>Reference</th><th>Amount</th><th>HTTP</th><th>Details / actor</th></tr></thead>
                <tbody>
                <?php foreach ($auditRows as $audit): ?>
                    <tr>
                        <td class="text-nowrap"><?= htmlspecialchars($audit['created_at']) ?></td>
                        <td><?= (int) $audit['attempt_number'] ?></td>
                        <td><span class="badge badge-<?= in_array($audit['outcome'], ['verified', 'restored'], true) ? 'success' : ($audit['outcome'] === 'archived' ? 'secondary' : 'warning') ?>"><?= htmlspecialchars(str_replace('_', ' ', strtoupper($audit['outcome']))) ?></span></td>
                        <td><?= (int) $audit['countable_failure'] === 1 ? 'Yes' : 'No' ?></td>
                        <td><small>Expected: <?= htmlspecialchars($audit['expected_reference'] ?: '-') ?><br>Observed: <?= htmlspecialchars($audit['observed_reference'] ?: '-') ?></small></td>
                        <td><small>Expected: <?= $audit['expected_amount'] === null ? '-' : 'GH&#8373;' . number_format((float) $audit['expected_amount'], 2) ?><br>Observed: <?= $audit['observed_amount'] === null ? '-' : 'GH&#8373;' . number_format((float) $audit['observed_amount'], 2) ?></small></td>
                        <td><?= $audit['http_code'] === null ? '-' : (int) $audit['http_code'] ?></td>
                        <td><small><?= htmlspecialchars($audit['details']) ?><br><span class="text-muted"><?= htmlspecialchars($audit['checked_by_name'] ?: 'System') ?></span></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$auditRows): ?><tr><td colspan="8" class="text-center text-muted py-4">No check history was recorded.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php elseif ($selectedId > 0): ?>
        <div class="alert alert-warning">That archived transaction was not found in your authorized scope.</div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
            <strong>Archived transactions</strong>
            <span class="badge badge-light"><?= number_format(count($archivedIntents)) ?> shown</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light"><tr><th>Reference</th><th>Beneficiary</th><th>Source/status</th><th>Amount</th><th>Archive reason</th><th>Archived</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($archivedIntents as $intent): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($intent['client_reference']) ?></code><?php if ($isSuperAdmin): ?><br><small><?= htmlspecialchars($intent['church_name'] ?: 'No church') ?></small><?php endif; ?></td>
                        <td><?= htmlspecialchars($intent['beneficiary_name']) ?><br><small><?= htmlspecialchars($intent['beneficiary_reference'] ?: 'No CRN/SRN') ?></small></td>
                        <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $intent['payment_source']))) ?><br><span class="badge badge-light"><?= htmlspecialchars($intent['status']) ?></span></td>
                        <td class="text-nowrap">GH&#8373;<?= number_format((float) $intent['amount'], 2) ?></td>
                        <td style="min-width:260px"><small><?= htmlspecialchars($intent['status_check_archive_reason'] ?: 'Three failed verification attempts.') ?></small><br><span class="badge badge-secondary mt-1"><?= (int) $intent['status_check_attempts'] ?> counted checks</span></td>
                        <td class="text-nowrap"><?= htmlspecialchars($intent['status_check_archived_at'] ?: '-') ?></td>
                        <td class="text-nowrap">
                            <a class="btn btn-sm btn-info mb-1" href="hubtel_status_archive.php?intent_id=<?= (int) $intent['id'] ?><?= $search !== '' ? '&amp;search=' . rawurlencode($search) : '' ?>"><i class="fas fa-history mr-1"></i>History</a>
                            <form method="post" class="d-inline">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="restore_archived">
                                <input type="hidden" name="payment_intent_id" value="<?= (int) $intent['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary mb-1" onclick="return confirm('Restore this transaction for a fresh three-check cycle?');"><i class="fas fa-undo mr-1"></i>Restore</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$archivedIntents): ?><tr><td colspan="7" class="text-center text-muted py-5">No archived status-check transactions match this view.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
$page_title = 'Archived Hubtel Checks';
include __DIR__ . '/../includes/layout.php';
?>
