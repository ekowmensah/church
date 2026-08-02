<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

if (!asset_use_requests_available($conn)) {
    http_response_code(404);
    exit('Asset request workflow not available. Run the latest assets migration first.');
}

$isMemberPortal = isset($_SESSION['member_id']);
$isSuper = asset_is_super_admin();
$canApprove = $isSuper || has_permission('approve_asset_use_request');
$canViewAll = $isSuper || has_permission('view_asset_requests') || $canApprove;

if (!$isMemberPortal && !$canViewAll) {
    asset_require_permission('view_asset_requests');
}

$churchId = $isSuper
    ? (isset($_GET['church_id']) && (int) $_GET['church_id'] > 0 ? (int) $_GET['church_id'] : null)
    : asset_current_church_id($conn);
$status = trim((string) ($_GET['status'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));

if ($canApprove) {
    $conn->query("UPDATE asset_use_requests SET status = 'overdue' WHERE status = 'checked_out' AND expected_return_date < CURDATE()");
}

$churches = [];
if ($isSuper) {
    $resChurches = $conn->query('SELECT id, name FROM churches ORDER BY name ASC');
    while ($row = $resChurches->fetch_assoc()) {
        $churches[] = $row;
    }
}

$sql = "
    SELECT aur.*, a.asset_code, a.item_name, c.name AS church_name,
           d.name AS department_name
    FROM asset_use_requests aur
    INNER JOIN assets a ON a.id = aur.asset_id
    LEFT JOIN churches c ON c.id = aur.church_id
    LEFT JOIN asset_departments d ON d.id = a.department_id
    WHERE 1
";
$types = '';
$params = [];

if ($churchId !== null) {
    $sql .= ' AND aur.church_id = ?';
    $types .= 'i';
    $params[] = $churchId;
}
if ($status !== '' && in_array($status, asset_request_statuses(), true)) {
    $sql .= ' AND aur.status = ?';
    $types .= 's';
    $params[] = $status;
}
if ($q !== '') {
    $sql .= ' AND (a.asset_code LIKE ? OR a.item_name LIKE ? OR aur.requester_name LIKE ? OR aur.purpose LIKE ?)';
    $types .= 'ssss';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($isMemberPortal) {
    $sql .= ' AND aur.requested_by_member_id = ?';
    $types .= 'i';
    $params[] = (int) $_SESSION['member_id'];
} elseif (!$canApprove) {
    $sql .= ' AND aur.requested_by_user_id = ?';
    $types .= 'i';
    $params[] = (int) ($_SESSION['user_id'] ?? 0);
}

$sql .= ' ORDER BY aur.created_at DESC';

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

ob_start();
?>
<div class="container-fluid mt-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h2 class="mb-1"><i class="fas fa-hand-holding mr-2"></i>Asset Requests</h2>
            <small class="text-muted"><?= $isMemberPortal ? 'Track your borrowing requests and returns.' : 'Review borrowing, issue, and return activity.' ?></small>
        </div>
        <div>
            <a href="asset_request_form.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>" class="btn btn-primary"><i class="fas fa-plus mr-1"></i> New Request</a>
            <?php if (!$isMemberPortal): ?>
                <a href="asset_list.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>" class="btn btn-outline-secondary ml-2"><i class="fas fa-arrow-left mr-1"></i> Assets</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($_GET['created'])): ?><div class="alert alert-success">Asset request submitted successfully.</div><?php endif; ?>
    <?php if (isset($_GET['done'])): ?><div class="alert alert-success">Asset request updated successfully.</div><?php endif; ?>
    <?php if (isset($_GET['err'])): ?><div class="alert alert-danger"><?= htmlspecialchars((string) $_GET['err']) ?></div><?php endif; ?>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <form method="get" class="form-row align-items-end">
                <?php if ($isSuper): ?>
                <div class="form-group col-md-3">
                    <label>Church</label>
                    <select name="church_id" class="form-control">
                        <option value="">All Churches</option>
                        <?php foreach ($churches as $church): ?>
                            <option value="<?= (int) $church['id'] ?>" <?= $churchId === (int) $church['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $church['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group col-md-3">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="">All</option>
                        <?php foreach (asset_request_statuses() as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>" <?= $status === $opt ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $opt))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-4">
                    <label>Search</label>
                    <input type="text" name="q" class="form-control" value="<?= htmlspecialchars($q) ?>" placeholder="Asset code, requester, purpose...">
                </div>
                <div class="form-group col-md-2">
                    <button type="submit" class="btn btn-outline-primary btn-block">Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover" id="assetRequestTable">
                <thead class="thead-light">
                    <tr>
                        <th>When</th>
                        <?php if ($isSuper): ?><th>Church</th><?php endif; ?>
                        <th>Asset</th>
                        <th>Requester</th>
                        <th>Purpose</th>
                        <th>Qty</th>
                        <th>Borrowing Period</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($row['created_at'] ?? '')) ?></td>
                            <?php if ($isSuper): ?><td><?= htmlspecialchars((string) ($row['church_name'] ?? '-')) ?></td><?php endif; ?>
                            <td><?= htmlspecialchars((string) (($row['asset_code'] ?? '-') . ' - ' . ($row['item_name'] ?? ''))) ?></td>
                            <td><?= htmlspecialchars((string) ($row['requester_name'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string) ($row['purpose'] ?? '')) ?></td>
                            <td><?= (int) ($row['approved_quantity'] ?? $row['quantity_requested'] ?? 1) ?></td>
                            <td>
                                <?= htmlspecialchars((string) ($row['borrow_start_date'] ?? '')) ?>
                                <br>
                                <small class="text-muted">Return: <?= htmlspecialchars((string) ($row['expected_return_date'] ?? '')) ?></small>
                            </td>
                            <td><span class="badge badge-<?= asset_request_status_badge_class((string) ($row['status'] ?? 'pending')) ?>"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string) ($row['status'] ?? 'pending')))) ?></span></td>
                            <td class="text-nowrap">
                                <?php if ((string) ($row['status'] ?? '') === 'pending' && (($isMemberPortal && (int) ($row['requested_by_member_id'] ?? 0) === (int) ($_SESSION['member_id'] ?? 0)) || (!$isMemberPortal && !$canApprove && (int) ($row['requested_by_user_id'] ?? 0) === (int) ($_SESSION['user_id'] ?? 0)))): ?>
                                    <form method="post" action="asset_request_action.php" class="d-inline">
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <input type="hidden" name="request_action" value="cancel">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary" onclick="return confirm('Cancel this request?');">Cancel</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($canApprove): ?>
                                    <?php if ((string) ($row['status'] ?? '') === 'pending'): ?>
                                        <form method="post" action="asset_request_action.php" class="d-inline">
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <input type="hidden" name="request_action" value="approve">
                                            <input type="hidden" name="approved_quantity" value="<?= (int) ($row['quantity_requested'] ?? 1) ?>">
                                            <input type="hidden" name="borrow_start_date" value="<?= htmlspecialchars((string) ($row['borrow_start_date'] ?? '')) ?>">
                                            <input type="hidden" name="expected_return_date" value="<?= htmlspecialchars((string) ($row['expected_return_date'] ?? '')) ?>">
                                            <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Approve this request?');">Approve</button>
                                        </form>
                                        <form method="post" action="asset_request_action.php" class="d-inline">
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <input type="hidden" name="request_action" value="reject">
                                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Reject this request?');">Reject</button>
                                        </form>
                                    <?php elseif ((string) ($row['status'] ?? '') === 'approved'): ?>
                                        <form method="post" action="asset_request_action.php" class="d-inline">
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <input type="hidden" name="request_action" value="checkout">
                                            <button type="submit" class="btn btn-sm btn-primary" onclick="return confirm('Mark this asset as checked out?');">Check Out</button>
                                        </form>
                                    <?php elseif (in_array((string) ($row['status'] ?? ''), ['checked_out', 'overdue'], true)): ?>
                                        <form method="post" action="asset_request_action.php" class="d-inline">
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <input type="hidden" name="request_action" value="return">
                                            <input type="hidden" name="actual_return_date" value="<?= htmlspecialchars(date('Y-m-d')) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success" onclick="return confirm('Mark this asset as returned?');">Return</button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="<?= $isSuper ? 9 : 8 ?>" class="text-center">No asset requests found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
$(function(){
  if ($.fn.DataTable) {
    $('#assetRequestTable').DataTable({pageLength: 25, order:[[0,'desc']]});
  }
});
</script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
