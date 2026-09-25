<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!asset_use_requests_available($conn) || !asset_request_lines_available($conn)) {
    http_response_code(503);
    exit('Multi-item asset requests are not available. Run Phase 0024 first.');
}

$isSuper = asset_is_super_admin();
$churchId = $isSuper
    ? (isset($_GET['church_id']) && (int) $_GET['church_id'] > 0 ? (int) $_GET['church_id'] : asset_current_church_id($conn))
    : asset_current_church_id($conn);
$error = '';
$actor = asset_use_request_actor($conn);
$purpose = trim((string) ($_POST['purpose'] ?? ''));
$requestNote = trim((string) ($_POST['request_note'] ?? ''));
$borrowStartDate = trim((string) ($_POST['borrow_start_date'] ?? date('Y-m-d')));
$expectedReturnDate = trim((string) ($_POST['expected_return_date'] ?? date('Y-m-d', strtotime('+7 days'))));
$selectedAssetIds = array_values(array_filter(array_map('intval', (array) ($_POST['asset_ids'] ?? [($_GET['asset_id'] ?? 0)]))));
if (!$selectedAssetIds) $selectedAssetIds = [0];

$assetSql = "
    SELECT asset.id, asset.asset_code, asset.item_name, asset.church_id,
           department.name AS department_name, category.name AS asset_group_name,
           COUNT(item.id) AS available_units
    FROM assets asset
    JOIN asset_items item ON item.asset_id = asset.id AND item.status = 'active'
    LEFT JOIN asset_departments department ON department.id = asset.department_id
    LEFT JOIN asset_groups category ON category.id = asset.asset_group_id
    WHERE asset.status = 'active'
      AND NOT EXISTS (
          SELECT 1 FROM asset_use_request_items used
          WHERE used.asset_item_id = item.id AND used.line_status = 'checked_out'
      )
";
$assetTypes = '';
$assetParams = [];
if (!$isSuper || $churchId) {
    $assetSql .= ' AND asset.church_id = ?';
    $assetTypes = 'i';
    $assetParams[] = (int) $churchId;
}
$assetSql .= ' GROUP BY asset.id, asset.asset_code, asset.item_name, asset.church_id,
                       department.name, category.name
               HAVING COUNT(item.id) > 0 ORDER BY asset.item_name, asset.asset_code';
$stmt = $conn->prepare($assetSql);
if ($assetTypes !== '') $stmt->bind_param($assetTypes, ...$assetParams);
$stmt->execute();
$assets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$assetMap = [];
foreach ($assets as $asset) $assetMap[(int) $asset['id']] = $asset;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedAssetIds = array_values(array_filter(array_map('intval', (array) ($_POST['asset_ids'] ?? []))));
    if (count($selectedAssetIds) > 50) {
        $error = 'A request can contain at most 50 selected items.';
    } elseif (!$selectedAssetIds) {
        $error = 'Select at least one asset item.';
    } elseif ($purpose === '') {
        $error = 'Purpose is required.';
    } elseif ($borrowStartDate === '' || $expectedReturnDate === '') {
        $error = 'Borrowing dates are required.';
    } elseif ($expectedReturnDate < $borrowStartDate) {
        $error = 'Expected return date cannot be earlier than the borrowing start date.';
    }

    $selectionCounts = array_count_values($selectedAssetIds);
    if ($error === '') {
        foreach ($selectionCounts as $assetId => $count) {
            $asset = $assetMap[(int) $assetId] ?? null;
            if (!$asset) {
                $error = 'One of the selected assets is unavailable or outside your church.';
                break;
            }
            if ($count > (int) $asset['available_units']) {
                $error = $asset['item_name'] . ' has only ' . (int) $asset['available_units'] . ' active physical item(s).';
                break;
            }
        }
    }

    if ($error === '') {
        $conn->begin_transaction();
        try {
            $firstAsset = $assetMap[$selectedAssetIds[0]];
            $requestChurchId = (int) $firstAsset['church_id'];
            foreach ($selectedAssetIds as $assetId) {
                if ((int) $assetMap[$assetId]['church_id'] !== $requestChurchId) {
                    throw new RuntimeException('All requested assets must belong to the same church.');
                }
            }
            $requestedByUserId = $actor['user_id'];
            $requestedByMemberId = $actor['member_id'];
            $requesterName = (string) $actor['name'];
            $requesterPhone = (string) ($actor['phone'] ?? '');
            $quantityRequested = count($selectedAssetIds);
            $firstAssetId = $selectedAssetIds[0];
            $stmt = $conn->prepare(
                'INSERT INTO asset_use_requests
                    (request_model_version, church_id, asset_id, requested_by_user_id,
                     requested_by_member_id, requester_name, requester_phone, purpose,
                     request_note, quantity_requested, borrow_start_date,
                     expected_return_date, status)
                 VALUES (2, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")'
            );
            $stmt->bind_param(
                'iiiissssiss',
                $requestChurchId, $firstAssetId, $requestedByUserId, $requestedByMemberId,
                $requesterName, $requesterPhone, $purpose, $requestNote,
                $quantityRequested, $borrowStartDate, $expectedReturnDate
            );
            $stmt->execute();
            $requestId = (int) $conn->insert_id;
            $stmt->close();

            $lineStmt = $conn->prepare(
                'INSERT INTO asset_use_request_items (request_id, asset_id, line_status)
                 VALUES (?, ?, "pending")'
            );
            foreach ($selectedAssetIds as $assetId) {
                $lineStmt->bind_param('ii', $requestId, $assetId);
                $lineStmt->execute();
                $lineId = (int) $conn->insert_id;
                asset_request_line_audit($conn, $requestId, $lineId, 'created', null, [
                    'asset_id' => $assetId, 'line_status' => 'pending',
                ], $requestedByUserId);
            }
            $lineStmt->close();
            $conn->commit();

            asset_log_action('asset_use_request_create', 'asset_use_request', $requestId, [
                'asset_id' => $firstAssetId, 'church_id' => $requestChurchId,
                'selected_item_count' => $quantityRequested, 'asset_ids' => $selectedAssetIds,
            ], [], [
                'purpose' => $purpose, 'borrow_start_date' => $borrowStartDate,
                'expected_return_date' => $expectedReturnDate,
            ]);
            header('Location: asset_request_list.php?created=1');
            exit;
        } catch (Throwable $exception) {
            $conn->rollback();
            $error = $exception->getMessage();
        }
    }
}

ob_start();
?>
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div><h2 class="mb-1"><i class="fas fa-hand-holding mr-2"></i>Request Asset Use</h2><small class="text-muted">Add one row for each physical item needed. Quantity is counted automatically.</small></div>
        <a href="asset_request_list.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left mr-1"></i> Back</a>
    </div>
    <div class="card shadow-sm"><div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="off" id="assetRequestForm">
            <div class="form-row">
                <div class="form-group col-md-6"><label>Requester</label><input class="form-control" value="<?= htmlspecialchars((string) $actor['name']) ?>" readonly></div>
                <div class="form-group col-md-6"><label>Requester Phone</label><input class="form-control" value="<?= htmlspecialchars((string) ($actor['phone'] ?? '')) ?>" readonly></div>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-2"><label class="mb-0">Requested Items <span class="text-danger">*</span></label><span class="badge badge-primary p-2">Quantity: <span id="requestQuantity">0</span></span></div>
            <div id="requestLines">
                <?php foreach ($selectedAssetIds as $selectedAssetId): ?>
                <div class="form-row request-line align-items-end mb-2">
                    <div class="form-group col-md-10 mb-0">
                        <select class="form-control asset-selection" name="asset_ids[]" required>
                            <option value="">-- Select an asset --</option>
                            <?php foreach ($assets as $asset): ?>
                            <option value="<?= (int) $asset['id'] ?>" data-available="<?= (int) $asset['available_units'] ?>" <?= (int) $selectedAssetId === (int) $asset['id'] ? 'selected' : '' ?>><?= htmlspecialchars($asset['asset_code'] . ' - ' . $asset['item_name']) ?> (<?= (int) $asset['available_units'] ?> available<?= !empty($asset['department_name']) ? ', ' . htmlspecialchars($asset['department_name']) : '' ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-2 mb-0"><button type="button" class="btn btn-outline-danger btn-block remove-line"><i class="fas fa-times"></i> Remove</button></div>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="addRequestLine"><i class="fas fa-plus mr-1"></i>Add another item</button>
            <hr>
            <div class="form-group"><label>Purpose <span class="text-danger">*</span></label><input name="purpose" class="form-control" value="<?= htmlspecialchars($purpose) ?>" required maxlength="255"></div>
            <div class="form-row">
                <div class="form-group col-md-6"><label>Borrow Start <span class="text-danger">*</span></label><input type="date" name="borrow_start_date" class="form-control" value="<?= htmlspecialchars($borrowStartDate) ?>" required></div>
                <div class="form-group col-md-6"><label>Expected Return <span class="text-danger">*</span></label><input type="date" name="expected_return_date" class="form-control" value="<?= htmlspecialchars($expectedReturnDate) ?>" required></div>
            </div>
            <div class="form-group"><label>Request Note</label><textarea name="request_note" class="form-control" rows="3" maxlength="255"><?= htmlspecialchars($requestNote) ?></textarea></div>
            <button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane mr-1"></i>Submit Request</button>
        </form>
    </div></div>
</div>
<script>
(function () {
    var lines = document.getElementById('requestLines'), add = document.getElementById('addRequestLine'), quantity = document.getElementById('requestQuantity');
    function updateQuantity() {
        var count = lines.querySelectorAll('.asset-selection').length;
        quantity.textContent = count;
        lines.querySelectorAll('.remove-line').forEach(function (button) { button.disabled = count === 1; });
    }
    add.addEventListener('click', function () {
        var clone = lines.querySelector('.request-line').cloneNode(true);
        clone.querySelector('.asset-selection').value = '';
        lines.appendChild(clone); updateQuantity();
    });
    lines.addEventListener('click', function (event) {
        var button = event.target.closest('.remove-line');
        if (!button || lines.querySelectorAll('.request-line').length === 1) return;
        button.closest('.request-line').remove(); updateQuantity();
    });
    updateQuantity();
})();
</script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
