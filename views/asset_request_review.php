<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/AssetCustodyService.php';
require_once __DIR__ . '/../includes/asset_workspace_nav.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!(asset_is_super_admin() || has_permission('approve_asset_use_request'))) {
    http_response_code(403);
    exit('You do not have permission to review asset requests.');
}
if (!(new AssetCustodyService($conn))->isAvailable()) {
    http_response_code(503);
    exit('Run Phase 0092 before reviewing custody requests.');
}

$requestId = (int) ($_GET['id'] ?? 0);
$returnToApprovalQueue = (string) ($_GET['return_to'] ?? '') === 'approval_queue';
$isSuper = asset_is_super_admin();
$churchId = $isSuper ? null : asset_current_church_id($conn);
$sql = 'SELECT request.*, church.name AS church_name
          FROM asset_use_requests request
          LEFT JOIN churches church ON church.id = request.church_id
         WHERE request.id = ?';
if (!$isSuper) $sql .= ' AND request.church_id = ?';
$sql .= ' LIMIT 1';
$stmt = $conn->prepare($sql);
if ($isSuper) $stmt->bind_param('i', $requestId); else $stmt->bind_param('ii', $requestId, $churchId);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request || (string) $request['status'] !== 'pending') {
    $target = $returnToApprovalQueue ? 'asset_approval_list.php' : 'asset_request_list.php';
    header('Location: ' . $target . '?err=' . urlencode('Only a pending request can be reviewed.'));
    exit;
}

$lines = asset_fetch_request_lines($conn, $requestId);
$requestChurchId = (int) $request['church_id'];
$stmt = $conn->prepare(
    "SELECT item.id, item.asset_id, asset.asset_group_id AS category_id,
            item.item_number, item.serial_number,
            item.condition_status, asset.item_name,
            category.name AS category_name, category.group_code AS category_code,
            department.name AS department_name
       FROM asset_items item
       JOIN assets asset ON asset.id = item.asset_id
       LEFT JOIN asset_groups category ON category.id = asset.asset_group_id
       LEFT JOIN asset_departments department ON department.id = item.department_id
      WHERE item.church_id = ?
        AND asset.status = 'active'
        AND item.status = 'active'
        AND item.custody_status = 'available'
        AND item.lifecycle_status NOT IN ('under_maintenance','retired','disposed')
        AND item.condition_status <> 'Disposed'
      ORDER BY COALESCE(category.name, asset.item_group), asset.item_name,
               item.item_number, item.id"
);
$stmt->bind_param('i', $requestChurchId);
$stmt->execute();
$availableItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$availableItemMap = [];
$availableByParent = [];
foreach ($availableItems as $availableItem) {
    $itemId = (int) $availableItem['id'];
    $parentId = (int) $availableItem['asset_id'];
    $availableItemMap[$itemId] = $availableItem;
    $availableByParent[$parentId][] = $availableItem;
}

$renderAssetOptions = static function (int $categoryId, int $selectedItemId = 0) use ($availableItems): void {
    echo '<option value="">-- Select an available asset --</option>';
    $openCategory = null;
    foreach ($availableItems as $item) {
        if ((int) ($item['category_id'] ?? 0) !== $categoryId) continue;
        $category = trim((string) ($item['category_name'] ?? '')) ?: 'Unclassified';
        if ($category !== $openCategory) {
            if ($openCategory !== null) echo '</optgroup>';
            echo '<optgroup label="' . htmlspecialchars($category, ENT_QUOTES, 'UTF-8') . '">';
            $openCategory = $category;
        }
        $label = (string) $item['item_number'] . ' — ' . (string) $item['item_name'];
        if (!empty($item['serial_number'])) $label .= ' · S/N ' . (string) $item['serial_number'];
        if (!empty($item['department_name'])) $label .= ' · ' . (string) $item['department_name'];
        $label .= ' · ' . (string) $item['condition_status'];
        echo '<option value="' . (int) $item['id'] . '" data-asset-id="' . (int) $item['asset_id'] . '"'
            . ($selectedItemId === (int) $item['id'] ? ' selected' : '') . '>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    if ($openCategory !== null) echo '</optgroup>';
};

ob_start();
?>
<link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/css/asset-workspace.css">
<style>
.request-review-grid{display:grid;gap:.85rem}.request-review-line{border:1px solid #dce5ed;border-radius:14px;background:#fff;overflow:hidden}
.request-review-line-header{display:flex;justify-content:space-between;gap:1rem;padding:.85rem 1rem;background:#f7fafc;border-bottom:1px solid #e5edf3}
.request-review-line-body{padding:1rem}.request-review-identity{font-weight:800;color:#17384f}.request-review-meta{display:block;color:#6b7f8e;font-size:.78rem;margin-top:.2rem}
.request-review-number{display:grid;place-items:center;flex:0 0 32px;height:32px;border-radius:10px;background:#e8f3fa;color:#12628f;font-weight:800}
.request-review-footer{position:sticky;bottom:0;z-index:2;background:rgba(255,255,255,.96);border-top:1px solid #e5edf3;padding:1rem;margin:1rem -1rem -1rem;backdrop-filter:blur(8px)}
@media(max-width:767.98px){.request-review-line-header{align-items:flex-start}.request-review-footer .btn{width:100%;margin-top:.75rem}}
</style>
<div class="container-fluid mt-4 asset-workspace">
    <section class="asset-hero p-4 mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div>
                <div class="eyebrow">Lending decision</div>
                <h2 class="mb-1">Review request #<?= $requestId ?></h2>
                <p class="mb-0"><?= htmlspecialchars((string) $request['requester_name'], ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars((string) $request['church_name'], ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <a href="<?= $returnToApprovalQueue ? 'asset_approval_list.php' : 'asset_request_list.php' ?>" class="btn btn-light mt-2 mt-md-0"><i class="fas fa-arrow-left mr-1"></i><?= $returnToApprovalQueue ? 'Back to Approval Queue' : 'Back to Lending &amp; Returns' ?></a>
        </div>
    </section>
    <?php render_asset_workspace_nav('custody', $isSuper ? $requestChurchId : $churchId); ?>

    <div class="alert alert-info border-0 shadow-sm">
        <strong>Review flow:</strong> confirm one exact asset for each submitted line. Approval reserves it immediately; Issue Asset later records the physical handover.
    </div>

    <div class="row">
        <div class="col-xl-8 mb-3">
            <div class="card asset-panel">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
                    <div><strong>Requested assets</strong><small class="d-block text-muted">The requester’s line count is fixed. Substitute an asset only when necessary and explain it in the review note.</small></div>
                    <span class="badge badge-primary p-2"><?= count($lines) ?> submitted line<?= count($lines) === 1 ? '' : 's' ?></span>
                </div>
                <div class="card-body">
                    <form method="post" action="asset_request_action.php" id="reviewForm">
                        <?= csrf_input() ?>
                        <input type="hidden" name="id" value="<?= $requestId ?>">
                        <input type="hidden" name="request_action" value="approve">
                        <?php if ($returnToApprovalQueue): ?><input type="hidden" name="return_to" value="approval_queue"><?php endif; ?>

                        <div class="request-review-grid">
                            <?php foreach ($lines as $index => $line):
                                $lineAssetId = (int) $line['asset_id'];
                                $lineCategoryId = (int) ($line['category_id'] ?? 0);
                                $requestedItemId = (int) ($line['asset_item_id'] ?? 0);
                                $selectedItemId = isset($availableItemMap[$requestedItemId])
                                    ? $requestedItemId
                                    : (int) ($availableByParent[$lineAssetId][0]['id'] ?? 0);
                                $selectedParentId = (int) ($availableItemMap[$selectedItemId]['asset_id'] ?? $lineAssetId);
                                $requestedNumber = trim((string) ($line['item_number'] ?? ''));
                                $requestedUnavailable = $requestedItemId > 0 && !isset($availableItemMap[$requestedItemId]);
                            ?>
                            <section class="request-review-line">
                                <div class="request-review-line-header">
                                    <div class="d-flex align-items-start">
                                        <span class="request-review-number mr-3"><?= $index + 1 ?></span>
                                        <div>
                                            <span class="request-review-identity"><?= htmlspecialchars((string) ($line['item_name'] ?? 'Asset'), ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="request-review-meta">Requested <?= $requestedNumber !== '' ? htmlspecialchars($requestedNumber, ENT_QUOTES, 'UTF-8') : 'from this category' ?><?= !empty($line['category_name']) ? ' · ' . htmlspecialchars((string) $line['category_name'], ENT_QUOTES, 'UTF-8') : '' ?></span>
                                        </div>
                                    </div>
                                    <span class="badge badge-light border align-self-start">Pending</span>
                                </div>
                                <div class="request-review-line-body">
                                    <?php if ($requestedUnavailable): ?><div class="alert alert-warning py-2"><strong>Requested asset unavailable.</strong> Select a substitute below or reject this line.</div><?php endif; ?>
                                    <input type="hidden" name="line_id[]" value="<?= (int) $line['id'] ?>">
                                    <input type="hidden" name="line_asset_id[]" class="line-parent-asset" data-original-asset-id="<?= $lineAssetId ?>" value="<?= $selectedParentId ?>">
                                    <div class="form-row align-items-end">
                                        <div class="form-group col-lg-8 mb-lg-0">
                                            <label>Asset to reserve</label>
                                            <select name="line_asset_item_id[]" class="form-control line-asset-item" required><?php $renderAssetOptions($lineCategoryId, $selectedItemId); ?></select>
                                            <small class="form-text text-muted">One accountable asset number from the requested category, with its department, serial and condition.</small>
                                        </div>
                                        <div class="form-group col-lg-4 mb-0">
                                            <label>Decision</label>
                                            <select name="line_decision[]" class="form-control line-decision">
                                                <option value="approved">Approve &amp; reserve</option>
                                                <option value="rejected">Reject this line</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </section>
                            <?php endforeach; ?>
                        </div>

                        <?php if (!$lines): ?><div class="alert alert-warning">This request has no submitted asset lines and cannot be approved.</div><?php endif; ?>
                        <hr>
                        <div class="form-row">
                            <div class="form-group col-md-4"><label>Reservation starts</label><input type="date" name="borrow_start_date" class="form-control" value="<?= htmlspecialchars((string) $request['borrow_start_date'], ENT_QUOTES, 'UTF-8') ?>" required></div>
                            <div class="form-group col-md-4"><label>Return due</label><input type="date" name="expected_return_date" class="form-control" value="<?= htmlspecialchars((string) $request['expected_return_date'], ENT_QUOTES, 'UTF-8') ?>" required></div>
                            <div class="form-group col-md-4"><label>Review note</label><input name="approval_note" class="form-control" maxlength="255" placeholder="Required for substitutions or rejected lines"></div>
                        </div>
                        <div class="request-review-footer d-flex flex-wrap justify-content-between align-items-center">
                            <small class="text-muted">Reservation does not mean handover. Use Issue Asset after the requester receives it.</small>
                            <button type="submit" class="btn btn-success" <?= !$lines ? 'disabled' : '' ?> onclick="return confirm('Complete this review and reserve every approved asset?');"><i class="fas fa-lock mr-1"></i>Approve &amp; Reserve Selected</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-xl-4 mb-3">
            <div class="card asset-panel mb-3">
                <div class="card-header"><strong>Request context</strong></div>
                <div class="card-body">
                    <div class="mb-3"><small class="text-muted d-block">Purpose</small><strong><?= htmlspecialchars((string) $request['purpose'], ENT_QUOTES, 'UTF-8') ?></strong></div>
                    <div class="mb-3"><small class="text-muted d-block">Requested period</small><span><?= htmlspecialchars((string) $request['borrow_start_date'], ENT_QUOTES, 'UTF-8') ?> to <?= htmlspecialchars((string) $request['expected_return_date'], ENT_QUOTES, 'UTF-8') ?></span></div>
                    <div><small class="text-muted d-block">Requester note</small><span><?= htmlspecialchars((string) (($request['request_note'] ?? '') !== '' ? $request['request_note'] : 'No note supplied'), ENT_QUOTES, 'UTF-8') ?></span></div>
                </div>
            </div>
            <div class="card asset-panel">
                <div class="card-header"><strong>Decision guide</strong></div>
                <div class="card-body small text-muted">
                    <p><strong class="text-dark">Approve &amp; reserve</strong><br>Locks the selected asset for this request.</p>
                    <p><strong class="text-dark">Reject this line</strong><br>Keeps the other lines reviewable and records the decision.</p>
                    <p class="mb-0"><strong class="text-dark">Issue Asset</strong><br>Used later in Lending &amp; Returns when handover actually occurs.</p>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var form = document.getElementById('reviewForm');
    if (!form) return;

    function syncLine(line) {
        var selector = line.querySelector('.line-asset-item');
        var parent = line.querySelector('.line-parent-asset');
        var decision = line.querySelector('.line-decision');
        var selected = selector.options[selector.selectedIndex];
        if (decision.value === 'approved' && selected && selected.dataset.assetId) {
            parent.value = selected.dataset.assetId;
        } else if (decision.value === 'rejected') {
            parent.value = parent.dataset.originalAssetId;
        }
        selector.required = decision.value === 'approved';
    }

    form.querySelectorAll('.request-review-line').forEach(syncLine);
    form.addEventListener('change', function (event) {
        var line = event.target.closest('.request-review-line');
        if (line) syncLine(line);
    });
})();
</script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
