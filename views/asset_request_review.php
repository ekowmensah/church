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
$sql = 'SELECT request.*, church.name AS church_name FROM asset_use_requests request LEFT JOIN churches church ON church.id = request.church_id WHERE request.id = ?';
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
$stmt = $conn->prepare("SELECT asset.id, asset.asset_code, asset.item_name,
                              COUNT(item.id) AS available_units
                         FROM assets asset
                         JOIN asset_items item ON item.asset_id = asset.id
                          AND item.status = 'active'
                          AND item.custody_status = 'available'
                          AND item.lifecycle_status NOT IN ('under_maintenance','retired','disposed')
                        WHERE asset.church_id = ? AND asset.status = 'active'
                        GROUP BY asset.id, asset.asset_code, asset.item_name
                        HAVING COUNT(item.id) > 0
                        ORDER BY asset.item_name, asset.asset_code");
$stmt->bind_param('i', $requestChurchId);
$stmt->execute();
$assets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("SELECT item.id, item.asset_id, item.item_number, item.serial_number,
                              item.condition_status, department.name AS department_name
                         FROM asset_items item
                         JOIN assets asset ON asset.id = item.asset_id
                         LEFT JOIN asset_departments department ON department.id = item.department_id
                        WHERE item.church_id = ? AND asset.status = 'active'
                          AND item.status = 'active' AND item.custody_status = 'available'
                          AND item.lifecycle_status NOT IN ('under_maintenance','retired','disposed')
                        ORDER BY asset.item_name, item.item_number");
$stmt->bind_param('i', $requestChurchId);
$stmt->execute();
$availableItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$renderAssetOptions = static function (int $selected = 0) use ($assets): void {
    foreach ($assets as $asset) {
        $id = (int) $asset['id'];
        echo '<option value="' . $id . '"' . ($selected === $id ? ' selected' : '') . '>'
            . htmlspecialchars((string) $asset['asset_code'] . ' - ' . (string) $asset['item_name'], ENT_QUOTES, 'UTF-8')
            . ' (' . (int) $asset['available_units'] . ' available)</option>';
    }
};
$renderItemOptions = static function (int $selectedAsset = 0) use ($availableItems): void {
    echo '<option value="">Automatically select first available unit</option>';
    foreach ($availableItems as $item) {
        $assetId = (int) $item['asset_id'];
        $label = (string) $item['item_number'];
        if (!empty($item['serial_number'])) $label .= ' / S/N ' . (string) $item['serial_number'];
        if (!empty($item['department_name'])) $label .= ' / ' . (string) $item['department_name'];
        $label .= ' / ' . (string) $item['condition_status'];
        echo '<option class="asset-unit-option" data-asset-id="' . $assetId . '" value="' . (int) $item['id'] . '"'
            . ($selectedAsset !== 0 && $selectedAsset !== $assetId ? ' hidden disabled' : '') . '>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
};

ob_start();
?>
<link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/css/asset-workspace.css">
<div class="container-fluid mt-4 asset-workspace">
    <section class="asset-hero p-4 mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div>
                <div class="eyebrow">Custody control</div>
                <h2 class="mb-1">Review request #<?= $requestId ?></h2>
                <p class="mb-0"><?= htmlspecialchars((string) $request['requester_name'], ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars((string) $request['church_name'], ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <a href="<?= $returnToApprovalQueue ? 'asset_approval_list.php' : 'asset_request_list.php' ?>" class="btn btn-light mt-2 mt-md-0"><i class="fas fa-arrow-left mr-1"></i><?= $returnToApprovalQueue ? 'Back to Approval Queue' : 'Back to Lending &amp; Returns' ?></a>
        </div>
    </section>
    <?php render_asset_workspace_nav('custody', $isSuper ? $requestChurchId : $churchId); ?>

    <div class="alert alert-info border-0 shadow-sm">
        <strong>Reservation rule:</strong> each approved line reserves one exact physical unit immediately. It cannot be approved for another request until this reservation is issued, rejected, or cancelled.
    </div>

    <div class="card asset-panel">
        <div class="card-header">
            <strong><?= htmlspecialchars((string) $request['purpose'], ENT_QUOTES, 'UTF-8') ?></strong>
            <?php if (!empty($request['request_note'])): ?><small class="d-block text-muted mt-1"><?= htmlspecialchars((string) $request['request_note'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
        </div>
        <div class="card-body">
            <form method="post" action="asset_request_action.php" id="reviewForm">
                <?= csrf_input() ?>
                <input type="hidden" name="id" value="<?= $requestId ?>">
                <input type="hidden" name="request_action" value="approve">
                <?php if ($returnToApprovalQueue): ?><input type="hidden" name="return_to" value="approval_queue"><?php endif; ?>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div><h3 class="h6 font-weight-bold mb-0">Requested physical units</h3><small class="text-muted">Confirm the category and reserve a traceable unit number.</small></div>
                    <span class="badge badge-primary p-2"><span id="reviewLineCount">0</span> line(s)</span>
                </div>
                <div id="reviewLines">
                    <?php foreach ($lines as $index => $line): $selectedAsset = (int) $line['asset_id']; ?>
                    <div class="review-line border rounded p-3 mb-3 bg-light">
                        <input type="hidden" name="line_id[]" value="<?= (int) $line['id'] ?>">
                        <div class="form-row align-items-end">
                            <div class="form-group col-lg-4"><label>Asset name / shared details</label><select name="line_asset_id[]" class="form-control line-asset" required><?php $renderAssetOptions($selectedAsset); ?></select></div>
                            <div class="form-group col-lg-4"><label>Physical unit to reserve</label><select name="line_asset_item_id[]" class="form-control line-item"><?php $renderItemOptions($selectedAsset); ?></select><small class="form-text text-muted">Unit number, serial, location and condition.</small></div>
                            <div class="form-group col-lg-2"><label>Decision</label><select name="line_decision[]" class="form-control line-decision"><option value="approved">Approve &amp; reserve</option><option value="rejected">Reject line</option></select></div>
                            <div class="form-group col-lg-2"><button type="button" class="btn btn-outline-danger btn-block remove-review-line">Remove</button></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-outline-primary btn-sm" id="addReviewLine"><i class="fas fa-plus mr-1"></i>Add request line</button>
                <hr>
                <div class="form-row">
                    <div class="form-group col-md-4"><label>Custody starts</label><input type="date" name="borrow_start_date" class="form-control" value="<?= htmlspecialchars((string) $request['borrow_start_date'], ENT_QUOTES, 'UTF-8') ?>" required></div>
                    <div class="form-group col-md-4"><label>Return due</label><input type="date" name="expected_return_date" class="form-control" value="<?= htmlspecialchars((string) $request['expected_return_date'], ENT_QUOTES, 'UTF-8') ?>" required></div>
                    <div class="form-group col-md-4"><label>Review note</label><input name="approval_note" class="form-control" maxlength="255" placeholder="Explain substitutions or rejected lines"></div>
                </div>
                <div class="d-flex flex-wrap justify-content-between align-items-center">
                    <small class="text-muted">Approval reserves units; the separate Issue action confirms physical handover.</small>
                    <button type="submit" class="btn btn-success" onclick="return confirm('Approve the accepted lines and reserve these physical units?');"><i class="fas fa-lock mr-1"></i>Approve &amp; Reserve</button>
                </div>
            </form>
        </div>
    </div>
</div>
<template id="reviewLineTemplate">
    <div class="review-line border rounded p-3 mb-3 bg-light">
        <input type="hidden" name="line_id[]" value="0">
        <div class="form-row align-items-end">
            <div class="form-group col-lg-4"><label>Asset name / shared details</label><select name="line_asset_id[]" class="form-control line-asset" required><option value="">-- Select --</option><?php $renderAssetOptions(); ?></select></div>
            <div class="form-group col-lg-4"><label>Physical unit to reserve</label><select name="line_asset_item_id[]" class="form-control line-item"><?php $renderItemOptions(-1); ?></select></div>
            <div class="form-group col-lg-2"><label>Decision</label><select name="line_decision[]" class="form-control line-decision"><option value="approved">Approve &amp; reserve</option><option value="rejected">Reject line</option></select></div>
            <div class="form-group col-lg-2"><button type="button" class="btn btn-outline-danger btn-block remove-review-line">Remove</button></div>
        </div>
    </div>
</template>
<script>
(function(){
    var lines=document.getElementById('reviewLines'), count=document.getElementById('reviewLineCount');
    function filterUnits(row){
        var asset=row.querySelector('.line-asset').value, unit=row.querySelector('.line-item');
        Array.prototype.forEach.call(unit.querySelectorAll('option[data-asset-id]'),function(option){
            var show=asset!=='' && option.getAttribute('data-asset-id')===asset;
            option.hidden=!show; option.disabled=!show;
            if(!show && option.selected) unit.value='';
        });
    }
    function sync(){ Array.prototype.forEach.call(lines.querySelectorAll('.review-line'),filterUnits); count.textContent=lines.querySelectorAll('.review-line').length; }
    document.getElementById('addReviewLine').addEventListener('click',function(){ lines.appendChild(document.getElementById('reviewLineTemplate').content.cloneNode(true)); sync(); });
    lines.addEventListener('change',function(e){ if(e.target.classList.contains('line-asset')) filterUnits(e.target.closest('.review-line')); });
    lines.addEventListener('click',function(e){ var button=e.target.closest('.remove-review-line'); if(button){ button.closest('.review-line').remove(); sync(); } });
    sync();
})();
</script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>

