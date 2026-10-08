<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../helpers/report_pagination.php';
require_once __DIR__ . '/../includes/asset_workspace_nav.php';

if (!asset_is_super_admin()
    && !has_permission('approve_asset_request')
    && !has_permission('request_asset_approval')
    && !has_permission('view_asset_requests')
    && !has_permission('approve_asset_use_request')) {
    asset_require_permission('approve_asset_request');
}

if (!asset_table_exists($conn, 'asset_approval_requests')) {
    http_response_code(404);
    exit('Asset approval workflow not available.');
}

$isSuper = asset_is_super_admin();
$canApprove = asset_user_can_approve_requests();
$canApproveUseRequests = $isSuper || has_permission('approve_asset_use_request');
$canViewUseRequests = $isSuper || has_permission('view_asset_requests') || $canApproveUseRequests;
$churchId = $isSuper ? (isset($_GET['church_id']) && (int) $_GET['church_id'] > 0 ? (int) $_GET['church_id'] : null) : asset_current_church_id($conn);
$status = trim((string) ($_GET['status'] ?? 'pending'));
$requestType = trim((string) ($_GET['request_type'] ?? ''));
$useRequestStatus = trim((string) ($_GET['use_status'] ?? 'pending'));

$churches = [];
if ($isSuper) {
    $resChurches = $conn->query('SELECT id, name FROM churches ORDER BY name ASC');
    while ($row = $resChurches->fetch_assoc()) {
        $churches[] = $row;
    }
}

$hasAssetSnapshots = asset_column_exists($conn, 'asset_approval_requests', 'asset_code_snapshot')
    && asset_column_exists($conn, 'asset_approval_requests', 'asset_name_snapshot');
$snapshotSelect = $hasAssetSnapshots
    ? ', aar.asset_code_snapshot, aar.asset_name_snapshot'
    : ', NULL AS asset_code_snapshot, NULL AS asset_name_snapshot';

$sql = "
    SELECT aar.*, a.id AS linked_asset_id, a.asset_code, a.item_name,
           u1.name AS requested_by_name, u2.name AS reviewed_by_name
           {$snapshotSelect}
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

$pageData = report_paginate_query($conn, $sql . ' ORDER BY aar.requested_at DESC', $types, $params, 25);
$rows = $pageData['result']->fetch_all(MYSQLI_ASSOC);
$pageData['statement']->close();

$departmentIds = [];
$itemIds = [];
foreach ($rows as &$approvalRow) {
    $payload = json_decode((string) ($approvalRow['payload_json'] ?? ''), true);
    $approvalRow['_payload_data'] = is_array($payload) ? $payload : null;
    if (!is_array($payload)) continue;
    foreach (['from_department_id', 'to_department_id'] as $field) {
        $id = (int) ($payload[$field] ?? 0);
        if ($id > 0) $departmentIds[$id] = $id;
    }
    $itemId = (int) ($payload['asset_item_id'] ?? 0);
    if ($itemId > 0) $itemIds[$itemId] = $itemId;
}
unset($approvalRow);

$departmentNames = [];
if ($departmentIds) {
    $ids = implode(',', array_map('intval', array_values($departmentIds)));
    $result = $conn->query("SELECT id, church_id, name FROM asset_departments WHERE id IN ({$ids})");
    while ($department = $result->fetch_assoc()) {
        $departmentNames[(int) $department['church_id'] . ':' . (int) $department['id']] = (string) $department['name'];
    }
}
$itemNumbers = [];
if ($itemIds && asset_table_exists($conn, 'asset_items')) {
    $ids = implode(',', array_map('intval', array_values($itemIds)));
    $result = $conn->query("SELECT id, church_id, item_number FROM asset_items WHERE id IN ({$ids})");
    while ($item = $result->fetch_assoc()) {
        $itemNumbers[(int) $item['church_id'] . ':' . (int) $item['id']] = (string) $item['item_number'];
    }
}

// Member-portal borrowing requests use the governed asset_use_requests
// workflow rather than asset_approval_requests. Surface both queues in this
// workspace while leaving their distinct review/action services intact.
$useRequestRows = [];
$useRequestCount = 0;
if ($canViewUseRequests && asset_use_requests_available($conn) && asset_request_lines_available($conn)) {
    $useWhere = ['1=1'];
    $useTypes = '';
    $useParams = [];
    if ($churchId !== null) {
        $useWhere[] = 'request.church_id = ?';
        $useTypes .= 'i';
        $useParams[] = $churchId;
    }
    if ($useRequestStatus !== '' && in_array($useRequestStatus, asset_request_statuses(), true)) {
        $useWhere[] = 'request.status = ?';
        $useTypes .= 's';
        $useParams[] = $useRequestStatus;
    }
    $useWhereSql = implode(' AND ', $useWhere);

    $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM asset_use_requests request WHERE ' . $useWhereSql);
    if ($useTypes !== '') $countStmt->bind_param($useTypes, ...$useParams);
    $countStmt->execute();
    $useRequestCount = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $useSql = "SELECT request.*, church.name AS church_name, member.crn,
                      asset.asset_code, asset.item_name,
                      (SELECT COUNT(*) FROM asset_use_request_items line
                        WHERE line.request_id = request.id AND line.line_status <> 'cancelled') AS line_count,
                      (SELECT GROUP_CONCAT(CONCAT(line_asset.asset_code, ' - ', line_asset.item_name)
                               ORDER BY line.id SEPARATOR ' | ')
                         FROM asset_use_request_items line
                         JOIN assets line_asset ON line_asset.id = line.asset_id
                        WHERE line.request_id = request.id AND line.line_status <> 'cancelled') AS requested_items
                 FROM asset_use_requests request
                 LEFT JOIN churches church ON church.id = request.church_id
                 LEFT JOIN members member ON member.id = request.requested_by_member_id
                 LEFT JOIN assets asset ON asset.id = request.asset_id
                WHERE {$useWhereSql}
                ORDER BY request.created_at DESC
                LIMIT 50";
    $useStmt = $conn->prepare($useSql);
    if ($useTypes !== '') $useStmt->bind_param($useTypes, ...$useParams);
    $useStmt->execute();
    $useRequestRows = $useStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $useStmt->close();
}

ob_start();
?>
<link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/css/asset-workspace.css">
<div class="container-fluid mt-4 asset-workspace">
    <div class="asset-hero p-4 mb-3 d-flex flex-wrap justify-content-between align-items-center">
        <div>
            <h2 class="mb-1"><i class="fas fa-user-check mr-2"></i>Asset Approval Centre</h2>
            <p class="mb-0">Review member custody requests and high-impact register changes without mixing their distinct controls.</p>
        </div>
        <a href="asset_list.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>" class="btn btn-light"><i class="fas fa-arrow-left mr-1"></i> Back to register</a>
    </div>
    <?php render_asset_workspace_nav('approvals', $churchId); ?>

    <?php if (isset($_GET['done'])): ?><div class="alert alert-success">Approval action completed.</div><?php endif; ?>
    <?php if (isset($_GET['err'])): ?><div class="alert alert-danger"><?= htmlspecialchars((string) $_GET['err']) ?></div><?php endif; ?>

    <?php if ($canViewUseRequests): ?>
    <div class="card shadow-sm mb-3 border-left-primary">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center">
            <div><h3 class="h6 font-weight-bold mb-1"><i class="fas fa-hand-holding mr-2 text-primary"></i>Member borrowing requests</h3><small class="text-muted">Requests submitted from the member portal appear here for their existing review, issue, and return workflow.</small></div>
            <a href="asset_request_list.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>" class="btn btn-sm btn-outline-primary mt-2 mt-md-0">Open full request register <i class="fas fa-arrow-right ml-1"></i></a>
        </div>
        <div class="card-body">
            <form method="get" class="form-row align-items-end mb-3">
                <?php if ($churchId): ?><input type="hidden" name="church_id" value="<?= (int) $churchId ?>"><?php endif; ?>
                <input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="request_type" value="<?= htmlspecialchars($requestType, ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-group col-md-4 mb-2"><label for="use_status">Member-request status</label><select id="use_status" name="use_status" class="form-control"><option value="">All statuses</option><?php foreach (asset_request_statuses() as $useStatus): ?><option value="<?= htmlspecialchars($useStatus, ENT_QUOTES, 'UTF-8') ?>" <?= $useRequestStatus === $useStatus ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $useStatus)), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                <div class="form-group col-md-2 mb-2"><button class="btn btn-outline-primary btn-block" type="submit"><i class="fas fa-filter mr-1"></i>Filter</button></div>
                <div class="form-group col-md-6 mb-2 text-md-right"><span class="badge badge-primary p-2"><?= number_format($useRequestCount) ?> matching request<?= $useRequestCount === 1 ? '' : 's' ?></span><?php if ($useRequestCount > 50): ?><small class="text-muted ml-2">Latest 50 shown; use the full register for complete results.</small><?php endif; ?></div>
            </form>
            <div class="table-responsive"><table class="table table-bordered table-hover mb-0" id="memberAssetRequestTable"><thead class="thead-light"><tr><th>When</th><?php if ($isSuper): ?><th>Church</th><?php endif; ?><th>Requester</th><th>Requested assets</th><th>Purpose / period</th><th>Status</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($useRequestRows as $useRequest): ?>
                <tr>
                    <td><?= htmlspecialchars((string) ($useRequest['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?><div><span class="badge badge-info">Member portal</span></div></td>
                    <?php if ($isSuper): ?><td><?= htmlspecialchars((string) ($useRequest['church_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td><?php endif; ?>
                    <td><strong><?= htmlspecialchars((string) ($useRequest['requester_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong><small class="d-block text-muted"><?= htmlspecialchars((string) ($useRequest['crn'] ?? 'No CRN'), ENT_QUOTES, 'UTF-8') ?></small></td>
                    <td style="min-width:240px"><?= htmlspecialchars((string) ($useRequest['requested_items'] ?: trim(($useRequest['asset_code'] ?? '') . ' - ' . ($useRequest['item_name'] ?? ''))), ENT_QUOTES, 'UTF-8') ?><small class="d-block text-muted"><?= (int) ($useRequest['line_count'] ?: $useRequest['quantity_requested'] ?: 1) ?> requested item(s)</small></td>
                    <td><?= htmlspecialchars((string) ($useRequest['purpose'] ?? ''), ENT_QUOTES, 'UTF-8') ?><small class="d-block text-muted"><?= htmlspecialchars((string) ($useRequest['borrow_start_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?> to <?= htmlspecialchars((string) ($useRequest['expected_return_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small></td>
                    <td><span class="badge badge-<?= asset_request_status_badge_class((string) ($useRequest['status'] ?? 'pending')) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($useRequest['status'] ?? 'pending'))), ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td class="text-nowrap"><?php if ($canApproveUseRequests && (string) ($useRequest['status'] ?? '') === 'pending'): ?><a href="asset_request_review.php?id=<?= (int) $useRequest['id'] ?>" class="btn btn-sm btn-success"><i class="fas fa-clipboard-check mr-1"></i>Review</a><?php else: ?><a href="asset_request_list.php?<?= http_build_query(array_filter(['church_id' => $churchId, 'q' => (string) ($useRequest['requester_name'] ?? '')])) ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-eye mr-1"></i>Open</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$useRequestRows): ?><tr><td colspan="<?= $isSuper ? 7 : 6 ?>" class="text-center text-muted py-4">No member borrowing requests match this filter.</td></tr><?php endif; ?>
            </tbody></table></div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-3">
        <div class="card-header bg-white"><h3 class="h6 font-weight-bold mb-0"><i class="fas fa-exchange-alt mr-2 text-primary"></i>Operational change approvals</h3></div>
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

    <div class="card shadow-sm asset-panel">
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
                        <th>Request Details</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $assetExists = (int) ($row['linked_asset_id'] ?? 0) > 0;
                        $assetCode = trim((string) ($row['asset_code'] ?? $row['asset_code_snapshot'] ?? ''));
                        $assetName = trim((string) ($row['item_name'] ?? $row['asset_name_snapshot'] ?? ''));
                        $assetDisplay = trim($assetCode . ' ' . $assetName);
                        if ($assetDisplay === '') {
                            $assetDisplay = 'Asset record #' . (int) ($row['asset_id'] ?? 0);
                        }
                        $payloadDetails = asset_approval_payload_details($row, $departmentNames, $itemNumbers);
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($row['requested_at'] ?? '')) ?></td>
                            <td><?= htmlspecialchars($assetDisplay) ?><?php if (!$assetExists): ?><br><span class="badge badge-danger">Asset unavailable</span><?php endif; ?></td>
                            <td><?= htmlspecialchars((string) ucwords(str_replace('_', ' ', (string) $row['request_type']))) ?></td>
                            <td><span class="badge badge-<?= $row['status'] === 'approved' ? 'success' : ($row['status'] === 'rejected' ? 'danger' : ($row['status'] === 'pending' ? 'warning' : 'secondary')) ?>"><?= htmlspecialchars((string) $row['status']) ?></span></td>
                            <td><?= htmlspecialchars((string) ($row['requested_by_name'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string) ($row['reviewed_by_name'] ?? '-')) ?></td>
                            <td style="min-width:260px;">
                                <?php foreach ($payloadDetails as $detail): ?>
                                    <div class="mb-1"><span class="text-muted small"><?= htmlspecialchars((string) $detail['label']) ?>:</span> <strong><?= htmlspecialchars((string) $detail['value']) ?></strong></div>
                                <?php endforeach; ?>
                                <details class="mt-2">
                                    <summary class="small text-muted" style="cursor:pointer;">Technical payload</summary>
                                    <pre class="mb-0 mt-1 p-2 bg-light rounded" style="font-size:11px;max-width:320px;white-space:pre-wrap;"><?= htmlspecialchars((string) ($row['payload_json'] ?? '')) ?></pre>
                                </details>
                            </td>
                            <td class="text-nowrap">
                                <?php if ($assetExists): ?><a class="btn btn-sm btn-outline-primary" href="asset_view.php?id=<?= (int) $row['asset_id'] ?>&tab=approvals"><i class="fas fa-eye"></i></a><?php endif; ?>
                                <?php if ($canApprove && (string) $row['status'] === 'pending'): ?>
                                    <?php if ($assetExists): ?><form method="post" action="asset_approval_action.php" class="d-inline" onsubmit="return confirm('Approve this request?');">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <input type="hidden" name="decision" value="approve">
                                        <button class="btn btn-sm btn-success" type="submit">Approve</button>
                                    </form><?php endif; ?>
                                    <form method="post" action="asset_approval_action.php" class="d-inline" onsubmit="return confirm('Reject this request?');">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <input type="hidden" name="decision" value="reject">
                                        <button class="btn btn-sm btn-danger" type="submit">Reject</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="8" class="text-center">No approval requests found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php report_render_server_pagination($pageData['total_rows'], $pageData['page'], $pageData['per_page'], 'Operational asset approval pages'); ?>
        </div>
    </div>
</div>
<script>
$(function(){
  if ($.fn.DataTable) {
    $('#memberAssetRequestTable').DataTable({pageLength: 10, order:[[0,'desc']], searching:true});
  }
});
</script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
