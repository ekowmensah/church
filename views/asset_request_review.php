<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!(asset_is_super_admin() || has_permission('approve_asset_use_request'))) {
    http_response_code(403);
    exit('You do not have permission to review asset requests.');
}
if (!asset_request_lines_available($conn)) {
    http_response_code(503);
    exit('Run Phase 0024 before reviewing multi-item requests.');
}

$requestId = (int) ($_GET['id'] ?? 0);
$churchId = asset_is_super_admin() ? null : asset_current_church_id($conn);
$sql = 'SELECT request.*, church.name AS church_name FROM asset_use_requests request LEFT JOIN churches church ON church.id = request.church_id WHERE request.id = ?';
if (!asset_is_super_admin()) $sql .= ' AND request.church_id = ?';
$sql .= ' LIMIT 1';
$stmt = $conn->prepare($sql);
if (asset_is_super_admin()) $stmt->bind_param('i', $requestId); else $stmt->bind_param('ii', $requestId, $churchId);
$stmt->execute(); $request = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$request || (string) $request['status'] !== 'pending') {
    header('Location: asset_request_list.php?err=' . urlencode('Only a pending request can be edited.'));
    exit;
}
$lines = asset_fetch_request_lines($conn, $requestId);
$stmt = $conn->prepare("SELECT asset.id, asset.asset_code, asset.item_name, COUNT(item.id) AS available_units
    FROM assets asset JOIN asset_items item ON item.asset_id = asset.id AND item.status = 'active'
    WHERE asset.church_id = ? AND asset.status = 'active'
      AND NOT EXISTS (
          SELECT 1 FROM asset_use_request_items used
          WHERE used.asset_item_id = item.id AND used.line_status = 'checked_out'
      )
    GROUP BY asset.id, asset.asset_code, asset.item_name HAVING COUNT(item.id) > 0
    ORDER BY asset.item_name, asset.asset_code");
$requestChurchId = (int) $request['church_id'];
$stmt->bind_param('i', $requestChurchId); $stmt->execute();
$assets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

ob_start();
?>
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div><h2 class="mb-1"><i class="fas fa-edit mr-2"></i>Review Asset Request #<?= $requestId ?></h2><small class="text-muted"><?= htmlspecialchars((string) $request['requester_name']) ?> · <?= htmlspecialchars((string) $request['church_name']) ?></small></div>
        <a href="asset_request_list.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left mr-1"></i>Back</a>
    </div>
    <div class="card shadow-sm"><div class="card-body">
        <div class="mb-3"><strong>Purpose:</strong> <?= htmlspecialchars((string) $request['purpose']) ?><?php if (!empty($request['request_note'])): ?><br><strong>Note:</strong> <?= htmlspecialchars((string) $request['request_note']) ?><?php endif; ?></div>
        <form method="post" action="asset_request_action.php" id="reviewForm">
            <input type="hidden" name="id" value="<?= $requestId ?>">
            <input type="hidden" name="request_action" value="approve">
            <div class="d-flex justify-content-between mb-2"><strong>Request Lines</strong><span class="badge badge-primary p-2">Reviewed lines: <span id="reviewLineCount">0</span></span></div>
            <div id="reviewLines">
                <?php foreach ($lines as $line): ?>
                <div class="form-row review-line align-items-end mb-2">
                    <input type="hidden" name="line_id[]" value="<?= (int) $line['id'] ?>">
                    <div class="form-group col-md-7 mb-0"><label>Requested Asset</label><select name="line_asset_id[]" class="form-control" required><?php foreach ($assets as $asset): ?><option value="<?= (int) $asset['id'] ?>" <?= (int) $line['asset_id'] === (int) $asset['id'] ? 'selected' : '' ?>><?= htmlspecialchars($asset['asset_code'] . ' - ' . $asset['item_name']) ?> (<?= (int) $asset['available_units'] ?> active)</option><?php endforeach; ?></select></div>
                    <div class="form-group col-md-3 mb-0"><label>Decision</label><select name="line_decision[]" class="form-control"><option value="approved">Approve item</option><option value="rejected">Reject item</option></select></div>
                    <div class="form-group col-md-2 mb-0"><button type="button" class="btn btn-outline-danger btn-block remove-review-line">Remove</button></div>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="addReviewLine"><i class="fas fa-plus mr-1"></i>Add item</button>
            <hr>
            <div class="form-row">
                <div class="form-group col-md-6"><label>Borrow Start</label><input type="date" name="borrow_start_date" class="form-control" value="<?= htmlspecialchars((string) $request['borrow_start_date']) ?>" required></div>
                <div class="form-group col-md-6"><label>Expected Return</label><input type="date" name="expected_return_date" class="form-control" value="<?= htmlspecialchars((string) $request['expected_return_date']) ?>" required></div>
            </div>
            <div class="form-group"><label>Approval / edit note</label><textarea name="approval_note" class="form-control" rows="3" maxlength="255" placeholder="Explain material changes or rejected lines"></textarea></div>
            <button type="submit" class="btn btn-success" onclick="return confirm('Save these edits and approve the accepted lines?');"><i class="fas fa-check mr-1"></i>Save Edits &amp; Approve</button>
        </form>
    </div></div>
</div>
<template id="reviewLineTemplate"><div class="form-row review-line align-items-end mb-2"><input type="hidden" name="line_id[]" value="0"><div class="form-group col-md-7 mb-0"><label>Requested Asset</label><select name="line_asset_id[]" class="form-control" required><option value="">-- Select --</option><?php foreach ($assets as $asset): ?><option value="<?= (int) $asset['id'] ?>"><?= htmlspecialchars($asset['asset_code'] . ' - ' . $asset['item_name']) ?> (<?= (int) $asset['available_units'] ?> active)</option><?php endforeach; ?></select></div><div class="form-group col-md-3 mb-0"><label>Decision</label><select name="line_decision[]" class="form-control"><option value="approved">Approve item</option><option value="rejected">Reject item</option></select></div><div class="form-group col-md-2 mb-0"><button type="button" class="btn btn-outline-danger btn-block remove-review-line">Remove</button></div></div></template>
<script>
(function(){var lines=document.getElementById('reviewLines'),count=document.getElementById('reviewLineCount');function sync(){count.textContent=lines.querySelectorAll('.review-line').length;}document.getElementById('addReviewLine').addEventListener('click',function(){lines.appendChild(document.getElementById('reviewLineTemplate').content.cloneNode(true));sync();});lines.addEventListener('click',function(e){var b=e.target.closest('.remove-review-line');if(b){b.closest('.review-line').remove();sync();}});sync();})();
</script>
<?php $page_content = ob_get_clean(); include __DIR__ . '/../includes/layout.php'; ?>
