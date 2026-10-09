<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../helpers/report_pagination.php';
require_once __DIR__ . '/../includes/asset_workspace_nav.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!asset_use_requests_available($conn) || !asset_request_lines_available($conn)) {
    http_response_code(503);
    exit('Asset custody workflow is unavailable. Run the latest asset migrations first.');
}

// Back-office accounts are linked to a member and therefore carry both IDs.
// Portal identity must come from the authenticated mode, not member_id alone,
// otherwise staff see only their own member requests after approving one.
$isMemberPortal = (($_SESSION['portal_mode'] ?? '') === 'member')
    || (empty($_SESSION['user_id']) && !empty($_SESSION['member_id']));
$isSuper = asset_is_super_admin();
$canApprove = $isSuper || has_permission('approve_asset_use_request');
$canViewAll = $isSuper || has_permission('view_asset_requests') || $canApprove;
if (!$isMemberPortal && !$canViewAll) asset_require_permission('view_asset_requests');

$churchId = $isSuper ? ((int) ($_GET['church_id'] ?? 0) ?: null) : asset_current_church_id($conn);
$status = trim((string) ($_GET['status'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));
$requestId = max(0, (int) ($_GET['request_id'] ?? 0));
$churches = [];
if ($isSuper) {
    $result = $conn->query('SELECT id, name FROM churches ORDER BY name');
    while ($row = $result->fetch_assoc()) $churches[] = $row;
}

$effectiveStatus = "CASE WHEN aur.status = 'checked_out' AND aur.expected_return_date < CURDATE() THEN 'overdue' ELSE aur.status END";
$where = ['1=1']; $types = ''; $params = [];
if ($churchId) { $where[] = 'aur.church_id = ?'; $types .= 'i'; $params[] = $churchId; }
if ($requestId > 0) { $where[] = 'aur.id = ?'; $types .= 'i'; $params[] = $requestId; }
if ($status !== '' && in_array($status, asset_request_statuses(), true)) { $where[] = $effectiveStatus . ' = ?'; $types .= 's'; $params[] = $status; }
if ($q !== '') {
    $where[] = '(a.asset_code LIKE ? OR a.item_name LIKE ? OR aur.requester_name LIKE ? OR aur.purpose LIKE ? OR EXISTS (SELECT 1 FROM asset_use_request_items search_line LEFT JOIN asset_items search_item ON search_item.id = search_line.asset_item_id WHERE search_line.request_id = aur.id AND search_item.item_number LIKE ?))';
    $types .= 'sssss'; $like = '%' . $q . '%'; array_push($params, $like,$like,$like,$like,$like);
}
if ($isMemberPortal) { $where[] = 'aur.requested_by_member_id = ?'; $types .= 'i'; $params[] = (int) $_SESSION['member_id']; }
elseif (!$canViewAll) { $where[] = 'aur.requested_by_user_id = ?'; $types .= 'i'; $params[] = (int) ($_SESSION['user_id'] ?? 0); }
$whereSql = implode(' AND ', $where);

$summarySql = "SELECT COUNT(*) total,
                      SUM(($effectiveStatus) = 'pending') pending_count,
                      SUM(($effectiveStatus) = 'approved') reserved_count,
                      SUM(($effectiveStatus) = 'checked_out') issued_count,
                      SUM(($effectiveStatus) = 'overdue') overdue_count
                 FROM asset_use_requests aur
                 JOIN assets a ON a.id = aur.asset_id
                WHERE $whereSql";
$summaryStmt = $conn->prepare($summarySql);
if ($types !== '') $summaryStmt->bind_param($types, ...$params);
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
$summaryStmt->close();

$sql = "SELECT aur.*, ($effectiveStatus) AS effective_status,
               a.asset_code, a.item_name, church.name AS church_name,
               (SELECT COUNT(*) FROM asset_use_request_items line WHERE line.request_id = aur.id AND line.line_status <> 'cancelled') AS line_count,
               (SELECT GROUP_CONCAT(DISTINCT CONCAT(line_asset.asset_code, ' - ', line_asset.item_name) ORDER BY line.id SEPARATOR ' | ')
                  FROM asset_use_request_items line JOIN assets line_asset ON line_asset.id = line.asset_id
                 WHERE line.request_id = aur.id AND line.line_status <> 'cancelled') AS requested_items,
               (SELECT GROUP_CONCAT(item.item_number ORDER BY line.id SEPARATOR ', ')
                  FROM asset_use_request_items line JOIN asset_items item ON item.id = line.asset_item_id
                 WHERE line.request_id = aur.id AND line.line_status IN ('approved','checked_out','returned')) AS physical_item_numbers
          FROM asset_use_requests aur
          JOIN assets a ON a.id = aur.asset_id
          LEFT JOIN churches church ON church.id = aur.church_id
         WHERE $whereSql
         ORDER BY aur.created_at DESC";
$pageData = report_paginate_query($conn, $sql, $types, $params, 25);
$rows = $pageData['result']->fetch_all(MYSQLI_ASSOC);
$pageData['statement']->close();

ob_start();
?>
<link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/css/asset-workspace.css">
<div class="container-fluid mt-4 asset-workspace">
    <section class="asset-hero p-4 mb-3"><div class="d-flex flex-wrap justify-content-between align-items-center"><div><div class="eyebrow">Borrowing, handover and return</div><h2 class="mb-1">Asset Lending &amp; Returns</h2><p class="mb-0"><?= $isMemberPortal ? 'Request a church asset and track it from review through handover and return.' : 'Review borrowing requests, reserve exact assets, record handover and receive every return.' ?></p></div><a href="asset_request_form.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>" class="btn btn-warning mt-2 mt-md-0"><i class="fas fa-plus mr-1"></i>New borrowing request</a></div></section>
    <?php render_asset_workspace_nav('custody', $churchId); ?>
    <?php if (isset($_GET['created'])): ?><div class="alert alert-success">Asset borrowing request submitted.</div><?php endif; ?>
    <?php if (($_GET['done'] ?? '') === 'reserved'): ?><div class="alert alert-success"><strong>Request approved and asset reserved.</strong> The asset has not been handed over yet. Use <strong>Issue Asset</strong> below when the requester collects it.</div>
    <?php elseif (isset($_GET['done'])): ?><div class="alert alert-success">Asset lending workflow updated successfully.</div><?php endif; ?>
    <?php if (isset($_GET['err'])): ?><div class="alert alert-danger"><?= htmlspecialchars((string) $_GET['err'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($requestId > 0): ?><div class="alert alert-info d-flex justify-content-between align-items-center"><span>Showing lending request <strong>#<?= $requestId ?></strong>.</span><a href="asset_request_list.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>" class="btn btn-sm btn-outline-info">Show all requests</a></div><?php endif; ?>

    <div class="asset-lifecycle-steps mb-3">
        <div class="asset-lifecycle-step"><strong>1. Request</strong><small>Purpose and dates</small></div><div class="asset-lifecycle-step"><strong>2. Reserve</strong><small>Exact asset selected</small></div><div class="asset-lifecycle-step"><strong>3. Issue</strong><small>Handover recorded</small></div><div class="asset-lifecycle-step"><strong>4. Custody</strong><small>Holder accountable</small></div><div class="asset-lifecycle-step"><strong>5. Return</strong><small>Condition inspected</small></div><div class="asset-lifecycle-step"><strong>6. Close</strong><small>Evidence retained</small></div>
    </div>
    <div class="asset-kpi-grid mb-3">
        <div class="asset-kpi-card"><div class="asset-kpi-label">Pending review</div><div class="asset-kpi-value"><?= number_format((int) ($summary['pending_count'] ?? 0)) ?></div></div>
        <div class="asset-kpi-card"><div class="asset-kpi-label">Reserved / Awaiting Handover</div><div class="asset-kpi-value"><?= number_format((int) ($summary['reserved_count'] ?? 0)) ?></div></div>
        <div class="asset-kpi-card"><div class="asset-kpi-label">Issued</div><div class="asset-kpi-value"><?= number_format((int) ($summary['issued_count'] ?? 0)) ?></div></div>
        <div class="asset-kpi-card"><div class="asset-kpi-label">Overdue</div><div class="asset-kpi-value text-danger"><?= number_format((int) ($summary['overdue_count'] ?? 0)) ?></div></div>
    </div>

    <div class="card asset-panel mb-3 asset-no-print"><div class="card-body"><form method="get" class="form-row align-items-end">
        <?php if ($requestId > 0): ?><input type="hidden" name="request_id" value="<?= $requestId ?>"><?php endif; ?>
        <?php if ($isSuper): ?><div class="form-group col-lg-3"><label>Church</label><select name="church_id" class="form-control"><option value="">All churches</option><?php foreach ($churches as $church): ?><option value="<?= (int) $church['id'] ?>" <?= $churchId === (int) $church['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $church['name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="form-group col-lg-3"><label>Workflow state</label><select name="status" class="form-control"><option value="">All states</option><?php foreach (asset_request_statuses() as $value): ?><option value="<?= $value ?>" <?= $status === $value ? 'selected' : '' ?>><?= htmlspecialchars(asset_request_status_label($value), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
        <div class="form-group col-lg-4"><label>Search</label><input name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="Requester, purpose, asset name or number"></div>
        <div class="form-group col-lg-2"><button class="btn btn-outline-primary btn-block"><i class="fas fa-filter mr-1"></i>Apply</button></div>
    </form></div></div>

    <div class="card asset-panel"><div class="card-header d-flex flex-wrap justify-content-between align-items-center"><div><strong>Lending workflow</strong><small class="d-block text-muted">Borrowing approval, reservation, issue and return are completed here. Asset register changes use Change Approvals.</small></div><span class="badge badge-primary p-2"><?= number_format($pageData['total_rows']) ?> request(s)</span></div><div class="card-body table-responsive">
        <table class="table table-hover mb-0"><thead><tr><th>Request</th><?php if ($isSuper): ?><th>Church</th><?php endif; ?><th>Requester / purpose</th><th>Requested assets</th><th>Reserved / issued assets</th><th>Custody period</th><th>State</th><th>Next action</th></tr></thead><tbody>
        <?php foreach ($rows as $row): $rowStatus = (string) $row['effective_status']; ?>
            <tr>
                <td><strong>#<?= (int) $row['id'] ?></strong><small class="d-block text-muted"><?= htmlspecialchars((string) $row['created_at'], ENT_QUOTES, 'UTF-8') ?></small></td>
                <?php if ($isSuper): ?><td><?= htmlspecialchars((string) ($row['church_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td><?php endif; ?>
                <td><strong><?= htmlspecialchars((string) $row['requester_name'], ENT_QUOTES, 'UTF-8') ?></strong><small class="d-block text-muted"><?= htmlspecialchars((string) $row['purpose'], ENT_QUOTES, 'UTF-8') ?></small></td>
                <td style="min-width:220px"><?= htmlspecialchars((string) ($row['requested_items'] ?: $row['asset_code'] . ' - ' . $row['item_name']), ENT_QUOTES, 'UTF-8') ?><small class="d-block text-muted"><?= (int) ($row['line_count'] ?: $row['quantity_requested']) ?> line(s)</small></td>
                <td><?= $row['physical_item_numbers'] ? htmlspecialchars((string) $row['physical_item_numbers'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">Not assigned</span>' ?></td>
                <td><?= htmlspecialchars((string) $row['borrow_start_date'], ENT_QUOTES, 'UTF-8') ?><small class="d-block <?= $rowStatus === 'overdue' ? 'text-danger font-weight-bold' : 'text-muted' ?>">Due <?= htmlspecialchars((string) $row['expected_return_date'], ENT_QUOTES, 'UTF-8') ?></small></td>
                <td><span class="badge badge-<?= asset_request_status_badge_class($rowStatus) ?>"><?= htmlspecialchars(asset_request_status_label($rowStatus), ENT_QUOTES, 'UTF-8') ?></span><?php if ($rowStatus === 'approved'): ?><small class="d-block text-muted mt-1">Approved, but not yet handed over</small><?php endif; ?></td>
                <td class="text-nowrap">
                    <?php $owns = ($isMemberPortal && (int) $row['requested_by_member_id'] === (int) ($_SESSION['member_id'] ?? 0)) || (!$isMemberPortal && (int) $row['requested_by_user_id'] === (int) ($_SESSION['user_id'] ?? 0)); ?>
                    <?php if ($rowStatus === 'pending' && $owns): ?><form method="post" action="asset_request_action.php" class="d-inline"><?= csrf_input() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="request_action" value="cancel"><button class="btn btn-sm btn-outline-secondary" onclick="return confirm('Cancel this request?')">Cancel</button></form><?php endif; ?>
                    <?php if ($canApprove && $rowStatus === 'pending'): ?><a href="asset_request_review.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-success"><i class="fas fa-clipboard-check mr-1"></i>Review</a><details class="d-inline-block ml-1"><summary class="btn btn-sm btn-outline-danger">Reject</summary><form method="post" action="asset_request_action.php" class="position-absolute bg-white border rounded shadow p-3" style="right:1rem;z-index:20;min-width:280px"><?= csrf_input() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="request_action" value="reject"><textarea name="approval_note" required maxlength="255" class="form-control form-control-sm mb-2" placeholder="Required rejection reason"></textarea><button class="btn btn-danger btn-sm btn-block">Confirm rejection</button></form></details><?php endif; ?>
                    <?php if ($canApprove && $rowStatus === 'approved'): ?><form method="post" action="asset_request_action.php" class="d-inline"><?= csrf_input() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="request_action" value="checkout"><button class="btn btn-sm btn-primary" onclick="return confirm('Confirm handover of the reserved asset(s)?')"><i class="fas fa-handshake mr-1"></i>Issue Asset</button></form><?php endif; ?>
                    <?php if ($canApprove && in_array($rowStatus, ['checked_out','overdue'], true)): ?><details class="d-inline-block"><summary class="btn btn-sm btn-outline-success">Receive return</summary><form method="post" action="asset_request_action.php" class="position-absolute bg-white border rounded shadow p-3" style="right:1rem;z-index:20;min-width:300px"><?= csrf_input() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="request_action" value="return"><label class="small">Actual return date</label><input type="date" name="actual_return_date" value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-sm mb-2" required><label class="small">Condition observed</label><select name="return_condition" class="form-control form-control-sm mb-2"><?php foreach (asset_condition_options() as $condition): if ($condition === 'Disposed') continue; ?><option value="<?= htmlspecialchars($condition, ENT_QUOTES, 'UTF-8') ?>" <?= $condition === 'Good' ? 'selected' : '' ?>><?= htmlspecialchars($condition, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><textarea name="return_note" class="form-control form-control-sm mb-2" placeholder="Return inspection notes"></textarea><button class="btn btn-success btn-sm btn-block">Confirm receipt</button></form></details><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="<?= $isSuper ? 8 : 7 ?>" class="text-center text-muted py-5">No custody requests match these filters.</td></tr><?php endif; ?>
        </tbody></table>
        <?php report_render_server_pagination($pageData['total_rows'], $pageData['page'], $pageData['per_page'], 'Asset custody request pages'); ?>
    </div></div>
</div>
<?php $page_content = ob_get_clean(); include __DIR__ . '/../includes/layout.php'; ?>

