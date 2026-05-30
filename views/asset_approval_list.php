<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

if (!asset_is_super_admin() && !has_permission('approve_asset_request') && !has_permission('request_asset_approval')) {
    asset_require_permission('approve_asset_request');
}

if (!asset_table_exists($conn, 'asset_approval_requests')) {
    http_response_code(404);
    exit('Asset approval workflow not available.');
}

$isSuper = asset_is_super_admin();
$canApprove = asset_user_can_approve_requests();
$churchId = $isSuper ? (isset($_GET['church_id']) && (int) $_GET['church_id'] > 0 ? (int) $_GET['church_id'] : null) : asset_current_church_id($conn);
$status = trim((string) ($_GET['status'] ?? 'pending'));
$requestType = trim((string) ($_GET['request_type'] ?? ''));

$churches = [];
if ($isSuper) {
    $resChurches = $conn->query('SELECT id, name FROM churches ORDER BY name ASC');
    while ($row = $resChurches->fetch_assoc()) {
        $churches[] = $row;
    }
}

$sql = "
    SELECT aar.*, a.asset_code, a.item_name, u1.name AS requested_by_name, u2.name AS reviewed_by_name
    FROM asset_approval_requests aar
    LEFT JOIN assets a ON a.id = aar.asset_id
    LEFT JOIN users u1 ON u1.id = aar.requested_by
    LEFT JOIN users u2 ON u2.id = aar.reviewed_by
    WHERE 1
";
$types = '';
$params = [];

if ($churchId !== null) {
    $sql .= ' AND aar.church_id = ?';
    $types .= 'i';
    $params[] = $churchId;
}
if ($status !== '' && in_array($status, ['pending', 'approved', 'rejected', 'cancelled'], true)) {
    $sql .= ' AND aar.status = ?';
    $types .= 's';
    $params[] = $status;
}
if ($requestType !== '' && in_array($requestType, ['transfer', 'status_change', 'dispose'], true)) {
    $sql .= ' AND aar.request_type = ?';
    $types .= 's';
    $params[] = $requestType;
}
if (!$canApprove) {
    $currentUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    $sql .= ' AND aar.requested_by = ?';
    $types .= 'i';
    $params[] = $currentUserId;
}

$sql .= ' ORDER BY aar.requested_at DESC';

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
            <h2 class="mb-1"><i class="fas fa-user-check mr-2"></i>Asset Approvals</h2>
            <small class="text-muted">Review or monitor high-impact asset change requests.</small>
        </div>
        <a href="asset_list.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left mr-1"></i> Back to Assets</a>
    </div>

    <?php if (isset($_GET['done'])): ?><div class="alert alert-success">Approval action completed.</div><?php endif; ?>
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
                        <?php foreach (['pending', 'approved', 'rejected', 'cancelled'] as $st): ?>
                            <option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-3">
                    <label>Type</label>
                    <select name="request_type" class="form-control">
                        <option value="">All</option>
                        <?php foreach (['transfer', 'status_change', 'dispose'] as $tp): ?>
                            <option value="<?= $tp ?>" <?= $requestType === $tp ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $tp)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-2">
                    <button type="submit" class="btn btn-outline-primary btn-block">Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover" id="assetApprovalTable">
                <thead class="thead-light">
                    <tr>
                        <th>When</th>
                        <th>Asset</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Requested By</th>
                        <th>Reviewed By</th>
                        <th>Payload</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($row['requested_at'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) (($row['asset_code'] ?? '-') . ' ' . ($row['item_name'] ?? ''))) ?></td>
                            <td><?= htmlspecialchars((string) ucwords(str_replace('_', ' ', (string) $row['request_type']))) ?></td>
                            <td><span class="badge badge-<?= $row['status'] === 'approved' ? 'success' : ($row['status'] === 'rejected' ? 'danger' : ($row['status'] === 'pending' ? 'warning' : 'secondary')) ?>"><?= htmlspecialchars((string) $row['status']) ?></span></td>
                            <td><?= htmlspecialchars((string) ($row['requested_by_name'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string) ($row['reviewed_by_name'] ?? '-')) ?></td>
                            <td><pre class="mb-0" style="font-size:11px;max-width:260px;white-space:pre-wrap;"><?= htmlspecialchars((string) ($row['payload_json'] ?? '')) ?></pre></td>
                            <td class="text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="asset_view.php?id=<?= (int) $row['asset_id'] ?>&tab=approvals"><i class="fas fa-eye"></i></a>
                                <?php if ($canApprove && (string) $row['status'] === 'pending'): ?>
                                    <a class="btn btn-sm btn-success" href="asset_approval_action.php?id=<?= (int) $row['id'] ?>&decision=approve" onclick="return confirm('Approve this request?');">Approve</a>
                                    <a class="btn btn-sm btn-danger" href="asset_approval_action.php?id=<?= (int) $row['id'] ?>&decision=reject" onclick="return confirm('Reject this request?');">Reject</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="8" class="text-center">No approval requests found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
$(function(){
  if ($.fn.DataTable) {
    $('#assetApprovalTable').DataTable({pageLength: 25, order:[[0,'desc']]});
  }
});
</script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
