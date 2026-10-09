<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../helpers/report_pagination.php';
require_once __DIR__ . '/../includes/asset_workspace_nav.php';

$isSuper = asset_is_super_admin();
$canViewRegisterApprovals = $isSuper
    || has_permission('approve_asset_request')
    || has_permission('request_asset_approval');
$canApproveBorrowing = $isSuper || has_permission('approve_asset_use_request');
if (!$canViewRegisterApprovals && !$canApproveBorrowing) {
    asset_require_permission('approve_asset_request');
}

$registerApprovalAvailable = asset_table_exists($conn, 'asset_approval_requests');
$canApprove = $canViewRegisterApprovals && asset_user_can_approve_requests();
$churchId = $isSuper ? (isset($_GET['church_id']) && (int) $_GET['church_id'] > 0 ? (int) $_GET['church_id'] : null) : asset_current_church_id($conn);
$status = trim((string) ($_GET['status'] ?? 'pending'));
$requestType = trim((string) ($_GET['request_type'] ?? ''));
$borrowingRequestId = max(0, (int) ($_GET['borrowing_id'] ?? 0));

$churches = [];
if ($isSuper) {
    $resChurches = $conn->query('SELECT id, name FROM churches ORDER BY name ASC');
    while ($row = $resChurches->fetch_assoc()) {
        $churches[] = $row;
    }
}

$rows = [];
$pageData = null;
$departmentNames = [];
$itemNumbers = [];
if ($canViewRegisterApprovals && $registerApprovalAvailable) {
    // aar.* already includes snapshot columns when they exist. Supply NULL
    // aliases only for missing columns so the pagination wrapper stays valid.
    $snapshotSelect = '';
    if (!asset_column_exists($conn, 'asset_approval_requests', 'asset_code_snapshot')) {
        $snapshotSelect .= ', NULL AS asset_code_snapshot';
    }
    if (!asset_column_exists($conn, 'asset_approval_requests', 'asset_name_snapshot')) {
        $snapshotSelect .= ', NULL AS asset_name_snapshot';
    }

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

    if ($departmentIds) {
        $ids = implode(',', array_map('intval', array_values($departmentIds)));
        $result = $conn->query("SELECT id, church_id, name FROM asset_departments WHERE id IN ({$ids})");
        while ($department = $result->fetch_assoc()) {
            $departmentNames[(int) $department['church_id'] . ':' . (int) $department['id']] = (string) $department['name'];
        }
    }
    if ($itemIds && asset_table_exists($conn, 'asset_items')) {
        $ids = implode(',', array_map('intval', array_values($itemIds)));
        $result = $conn->query("SELECT id, church_id, item_number FROM asset_items WHERE id IN ({$ids})");
        while ($item = $result->fetch_assoc()) {
            $itemNumbers[(int) $item['church_id'] . ':' . (int) $item['id']] = (string) $item['item_number'];
        }
    }
}

// This is intentionally an action queue, not a second custody register.
// Only pending borrowing decisions appear here; approved/reserved, issued and
// returned requests continue in Asset Lending & Returns.
$pendingBorrowingRows = [];
$pendingBorrowingCount = 0;
if ($canApproveBorrowing && asset_use_requests_available($conn) && asset_request_lines_available($conn)) {
    $borrowingWhere = ["request.status = 'pending'"];
    $borrowingTypes = '';
    $borrowingParams = [];
    if ($churchId !== null) {
        $borrowingWhere[] = 'request.church_id = ?';
        $borrowingTypes .= 'i';
        $borrowingParams[] = $churchId;
    }
    if ($borrowingRequestId > 0) {
        $borrowingWhere[] = 'request.id = ?';
        $borrowingTypes .= 'i';
        $borrowingParams[] = $borrowingRequestId;
    }
    $borrowingWhereSql = implode(' AND ', $borrowingWhere);
    $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM asset_use_requests request WHERE ' . $borrowingWhereSql);
    if ($borrowingTypes !== '') $countStmt->bind_param($borrowingTypes, ...$borrowingParams);
    $countStmt->execute();
    $pendingBorrowingCount = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $borrowingSql = "SELECT request.*, church.name AS church_name, member.crn,
                            asset.asset_code, asset.item_name,
                            (SELECT COUNT(*) FROM asset_use_request_items line
                              WHERE line.request_id = request.id
                                AND line.line_status <> 'cancelled') AS line_count,
                            (SELECT GROUP_CONCAT(CONCAT(line_asset.asset_code, ' - ', line_asset.item_name)
                                     ORDER BY line.id SEPARATOR ' | ')
                               FROM asset_use_request_items line
                               JOIN assets line_asset ON line_asset.id = line.asset_id
                              WHERE line.request_id = request.id
                                AND line.line_status <> 'cancelled') AS requested_items
                       FROM asset_use_requests request
                       LEFT JOIN churches church ON church.id = request.church_id
                       LEFT JOIN members member ON member.id = request.requested_by_member_id
                       LEFT JOIN assets asset ON asset.id = request.asset_id
                      WHERE {$borrowingWhereSql}
                      ORDER BY request.created_at ASC, request.id ASC
                      LIMIT 100";
    $borrowingStmt = $conn->prepare($borrowingSql);
    if ($borrowingTypes !== '') $borrowingStmt->bind_param($borrowingTypes, ...$borrowingParams);
    $borrowingStmt->execute();
    $pendingBorrowingRows = $borrowingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $borrowingStmt->close();
}

ob_start();
?>
<link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/css/asset-workspace.css">
<div class="container-fluid mt-4 asset-workspace">
    <div class="asset-hero p-4 mb-3 d-flex flex-wrap justify-content-between align-items-center">
        <div>
            <h2 class="mb-1"><i class="fas fa-user-check mr-2"></i>Asset Approval Queue</h2>
            <p class="mb-0">Review decisions that still need action. Approved borrowing moves immediately to Lending &amp; Returns for handover.</p>
        </div>
        <div>
            <?php if (isset($_SESSION['member_id']) || asset_user_can_view_use_requests()): ?><a href="asset_request_list.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>" class="btn btn-outline-light mr-2"><i class="fas fa-hand-holding mr-1"></i>Lending &amp; Returns</a><?php endif; ?>
            <a href="asset_list.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>" class="btn btn-light"><i class="fas fa-arrow-left mr-1"></i> Back to register</a>
        </div>
    </div>
    <?php render_asset_workspace_nav('approvals', $churchId); ?>

    <?php if (($_GET['done'] ?? '') === 'borrowing_rejected'): ?><div class="alert alert-success">Borrowing request rejected and removed from the pending approval queue.</div>
    <?php elseif (isset($_GET['done'])): ?><div class="alert alert-success">Asset change approval completed.</div><?php endif; ?>
    <?php if (isset($_GET['err'])): ?><div class="alert alert-danger"><?= htmlspecialchars((string) $_GET['err']) ?></div><?php endif; ?>

    <?php if ($canApproveBorrowing): ?>
    <div class="card shadow-sm mb-3 border-left-warning">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center">
            <div><h3 class="h6 font-weight-bold mb-1"><i class="fas fa-hand-holding mr-2 text-warning"></i>Pending borrowing approvals</h3><small class="text-muted">Only requests awaiting a decision appear here. Approval reserves an exact asset and then opens its handover record.</small></div>
            <span class="badge badge-warning p-2"><?= number_format($pendingBorrowingCount) ?> awaiting decision</span>
        </div>
        <div class="card-body table-responsive">
            <?php if ($borrowingRequestId > 0): ?><div class="alert alert-info py-2 d-flex justify-content-between align-items-center"><span>Showing borrowing request <strong>#<?= $borrowingRequestId ?></strong>.</span><a href="asset_approval_list.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>" class="btn btn-sm btn-outline-info">Show full queue</a></div><?php endif; ?>
            <table class="table table-bordered table-hover mb-0">
                <thead class="thead-light"><tr><th>Request</th><?php if ($isSuper): ?><th>Church</th><?php endif; ?><th>Requester</th><th>Requested assets</th><th>Purpose / period</th><th>Decision</th></tr></thead>
                <tbody>
                <?php foreach ($pendingBorrowingRows as $borrowingRequest): ?>
                    <tr>
                        <td><strong>#<?= (int) $borrowingRequest['id'] ?></strong><small class="d-block text-muted"><?= htmlspecialchars((string) $borrowingRequest['created_at'], ENT_QUOTES, 'UTF-8') ?></small><span class="badge badge-warning mt-1">Awaiting review</span></td>
                        <?php if ($isSuper): ?><td><?= htmlspecialchars((string) ($borrowingRequest['church_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td><?php endif; ?>
                        <td><strong><?= htmlspecialchars((string) ($borrowingRequest['requester_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong><small class="d-block text-muted"><?= htmlspecialchars((string) ($borrowingRequest['crn'] ?? 'No CRN'), ENT_QUOTES, 'UTF-8') ?></small></td>
                        <td style="min-width:230px"><?= htmlspecialchars((string) ($borrowingRequest['requested_items'] ?: trim(($borrowingRequest['asset_code'] ?? '') . ' - ' . ($borrowingRequest['item_name'] ?? ''))), ENT_QUOTES, 'UTF-8') ?><small class="d-block text-muted"><?= (int) ($borrowingRequest['line_count'] ?: $borrowingRequest['quantity_requested'] ?: 1) ?> requested asset(s)</small></td>
                        <td><?= htmlspecialchars((string) ($borrowingRequest['purpose'] ?? ''), ENT_QUOTES, 'UTF-8') ?><small class="d-block text-muted"><?= htmlspecialchars((string) ($borrowingRequest['borrow_start_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?> to <?= htmlspecialchars((string) ($borrowingRequest['expected_return_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small></td>
                        <td class="text-nowrap"><a href="asset_request_review.php?id=<?= (int) $borrowingRequest['id'] ?>&return_to=approval_queue" class="btn btn-sm btn-success"><i class="fas fa-clipboard-check mr-1"></i>Review Request</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$pendingBorrowingRows): ?><tr><td colspan="<?= $isSuper ? 6 : 5 ?>" class="text-center text-muted py-4"><i class="fas fa-check-circle d-block mb-2"></i>No borrowing requests are waiting for approval.</td></tr><?php endif; ?>
                </tbody>
            </table>
            <?php if ($pendingBorrowingCount > 100): ?><small class="text-muted d-block mt-2">Oldest 100 pending decisions are shown.</small><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($canViewRegisterApprovals && $registerApprovalAvailable): ?>
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-white"><h3 class="h6 font-weight-bold mb-0"><i class="fas fa-exchange-alt mr-2 text-primary"></i>Register-change approvals</h3><small class="text-muted">Transfer, status and disposal decisions change official asset data; they do not lend an asset to a person. Pending is shown by default.</small></div>
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
                                    <details class="d-inline-block ml-1">
                                        <summary class="btn btn-sm btn-outline-danger">Reject</summary>
                                        <form method="post" action="asset_approval_action.php" class="p-3 border rounded bg-white position-absolute shadow text-left" style="right:1rem;z-index:20;min-width:300px">
                                            <?= csrf_input() ?>
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <input type="hidden" name="decision" value="reject">
                                            <label class="small font-weight-bold">Rejection reason</label>
                                            <textarea name="review_note" class="form-control form-control-sm mb-2" required maxlength="255" placeholder="Explain why this change cannot proceed."></textarea>
                                            <button class="btn btn-danger btn-sm btn-block" type="submit">Confirm rejection</button>
                                        </form>
                                    </details>
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
    <?php elseif ($canViewRegisterApprovals): ?>
    <div class="alert alert-warning">Register-change approvals are unavailable until the asset approval schema is deployed. Borrowing approvals remain available above.</div>
    <?php endif; ?>
</div>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
