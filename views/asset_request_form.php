<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../includes/asset_workspace_nav.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!asset_use_requests_available($conn) || !asset_request_lines_available($conn)
    || !asset_column_exists($conn, 'asset_items', 'custody_status')) {
    http_response_code(503);
    exit('Asset custody requests are not available. Run Phase 0092 first.');
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
$selectedAssetItemIds = array_values(array_filter(array_map('intval', (array) ($_POST['asset_item_ids'] ?? []))));
$requestedParentAssetId = max(0, (int) ($_GET['asset_id'] ?? 0));
$selectedCategoryIds = array_values(array_map('intval', (array) ($_POST['asset_category_ids'] ?? [])));

$assetSql = "
    SELECT item.id AS asset_item_id, item.asset_id, item.item_number,
           item.serial_number, item.condition_status, asset.item_name, asset.church_id,
           asset.asset_group_id, department.name AS department_name,
           category.name AS asset_group_name, category.group_code AS asset_group_code
    FROM assets asset
    JOIN asset_items item ON item.asset_id = asset.id AND item.status = 'active'
        AND item.custody_status = 'available'
        AND item.lifecycle_status NOT IN ('under_maintenance','retired','disposed')
        AND item.condition_status <> 'Disposed'
    LEFT JOIN asset_departments department ON department.id = item.department_id
    LEFT JOIN asset_groups category ON category.id = asset.asset_group_id
    WHERE asset.status = 'active'
";
$assetTypes = '';
$assetParams = [];
if (!$isSuper || $churchId) {
    $assetSql .= ' AND asset.church_id = ?';
    $assetTypes = 'i';
    $assetParams[] = (int) $churchId;
}
$assetSql .= ' ORDER BY category.name, asset.item_name, item.item_number';
$stmt = $conn->prepare($assetSql);
if ($assetTypes !== '') $stmt->bind_param($assetTypes, ...$assetParams);
$stmt->execute();
$assets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$assetMap = [];
foreach ($assets as $asset) $assetMap[(int) $asset['asset_item_id']] = $asset;
if (!$selectedAssetItemIds && $requestedParentAssetId > 0) {
    foreach ($assets as $asset) {
        if ((int) $asset['asset_id'] === $requestedParentAssetId) {
            $selectedAssetItemIds[] = (int) $asset['asset_item_id'];
            break;
        }
    }
}
if (!$selectedAssetItemIds) $selectedAssetItemIds = [0];
$categories = [];
foreach ($assets as $asset) {
    $categoryId = (int) ($asset['asset_group_id'] ?? 0);
    if ($categoryId < 1) continue;
    if (!isset($categories[$categoryId])) {
        $categories[$categoryId] = [
            'id' => $categoryId,
            'name' => (string) ($asset['asset_group_name'] ?? 'Unclassified'),
            'code' => (string) ($asset['asset_group_code'] ?? ''),
            'available_assets' => 0,
        ];
    }
    $categories[$categoryId]['available_assets']++;
}
uasort($categories, static fn(array $left, array $right): int => strcasecmp($left['name'], $right['name']));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Your form expired. Refresh the page and try again.');
    }
    $selectedAssetItemIds = array_values(array_filter(array_map('intval', (array) ($_POST['asset_item_ids'] ?? []))));
    $selectedCategoryIds = array_values(array_map('intval', (array) ($_POST['asset_category_ids'] ?? [])));
    if (count($selectedAssetItemIds) > 50) {
        $error = 'A request can contain at most 50 selected assets.';
    } elseif (count($selectedCategoryIds) !== count($selectedAssetItemIds)) {
        $error = 'Choose a category and an asset for every request row.';
    } elseif (!$selectedAssetItemIds) {
        $error = 'Select at least one asset.';
    } elseif (count(array_unique($selectedAssetItemIds)) !== count($selectedAssetItemIds)) {
        $error = 'The same asset cannot be requested more than once.';
    } elseif ($purpose === '') {
        $error = 'Purpose is required.';
    } elseif ($borrowStartDate === '' || $expectedReturnDate === '') {
        $error = 'Borrowing dates are required.';
    } elseif ($expectedReturnDate < $borrowStartDate) {
        $error = 'Expected return date cannot be earlier than the borrowing start date.';
    }

    if ($error === '') {
        foreach ($selectedAssetItemIds as $index => $assetItemId) {
            $asset = $assetMap[(int) $assetItemId] ?? null;
            if (!$asset) {
                $error = 'One of the selected assets is unavailable or outside your church.';
                break;
            }
            $categoryId = (int) ($selectedCategoryIds[$index] ?? 0);
            if ($categoryId < 1 || $categoryId !== (int) ($asset['asset_group_id'] ?? 0)) {
                $error = 'One selected asset does not belong to its chosen category. Select the category again.';
                break;
            }
        }
    }

    if ($error === '') {
        $conn->begin_transaction();
        try {
            $firstAsset = $assetMap[$selectedAssetItemIds[0]];
            $requestChurchId = (int) $firstAsset['church_id'];
            foreach ($selectedAssetItemIds as $assetItemId) {
                if ((int) $assetMap[$assetItemId]['church_id'] !== $requestChurchId) {
                    throw new RuntimeException('All requested assets must belong to the same church.');
                }
            }
            $requestedByUserId = $actor['user_id'];
            $requestedByMemberId = $actor['member_id'];
            $requesterName = (string) $actor['name'];
            $requesterPhone = (string) ($actor['phone'] ?? '');
            $quantityRequested = count($selectedAssetItemIds);
            $firstAssetId = (int) $firstAsset['asset_id'];
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
                'INSERT INTO asset_use_request_items (request_id, asset_id, asset_item_id, line_status)
                 VALUES (?, ?, ?, "pending")'
            );
            $requestedParentIds = [];
            foreach ($selectedAssetItemIds as $assetItemId) {
                $assetId = (int) $assetMap[$assetItemId]['asset_id'];
                $requestedParentIds[] = $assetId;
                $lineStmt->bind_param('iii', $requestId, $assetId, $assetItemId);
                $lineStmt->execute();
                $lineId = (int) $conn->insert_id;
                asset_request_line_audit($conn, $requestId, $lineId, 'created', null, [
                    'asset_id' => $assetId, 'asset_item_id' => $assetItemId, 'line_status' => 'pending',
                ], $requestedByUserId);
            }
            $lineStmt->close();
            $conn->commit();

            asset_log_action('asset_use_request_create', 'asset_use_request', $requestId, [
                'asset_id' => $firstAssetId, 'church_id' => $requestChurchId,
                'selected_asset_count' => $quantityRequested,
                'asset_ids' => $requestedParentIds,
                'asset_item_ids' => $selectedAssetItemIds,
            ], [], [
                'purpose' => $purpose, 'borrow_start_date' => $borrowStartDate,
                'expected_return_date' => $expectedReturnDate,
            ]);
            header('Location: asset_request_list.php?created=1');
            exit;
        } catch (Throwable $exception) {
            $conn->rollback();
            error_log('Asset custody request creation failed: ' . $exception->getMessage());
            $error = $exception instanceof mysqli_sql_exception
                ? 'The request could not be created. Please retry.'
                : $exception->getMessage();
        }
    }
}

ob_start();
?>
<link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/css/asset-workspace.css">
<div class="container-fluid mt-4 asset-workspace">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div><h2 class="mb-1"><i class="fas fa-hand-holding mr-2"></i>Request Asset Use</h2><small class="text-muted">Choose a category first, then select the available asset you need.</small></div>
        <a href="asset_request_list.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left mr-1"></i> Back</a>
    </div>
    <?php render_asset_workspace_nav('custody', $churchId); ?>
    <div class="alert alert-info border-0 shadow-sm"><strong>What happens next?</strong> An authorized reviewer confirms the exact assets you selected and reserves each approved asset. They are not in your custody until an officer confirms Issue.</div>
    <div class="card asset-panel asset-form-shell"><div class="card-header"><strong>Custody request</strong><small class="d-block text-muted">Each row represents one required asset. Category selection narrows the asset list.</small></div><div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="off" id="assetRequestForm">
            <?= csrf_input() ?>
            <div class="form-row">
                <div class="form-group col-md-6"><label>Requester</label><input class="form-control" value="<?= htmlspecialchars((string) $actor['name']) ?>" readonly></div>
                <div class="form-group col-md-6"><label>Requester Phone</label><input class="form-control" value="<?= htmlspecialchars((string) ($actor['phone'] ?? '')) ?>" readonly></div>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-2"><label class="mb-0">Requested Assets <span class="text-danger">*</span></label><span class="badge badge-primary p-2">Quantity: <span id="requestQuantity">0</span></span></div>
            <div id="requestLines">
                <?php foreach ($selectedAssetItemIds as $lineIndex => $selectedAssetItemId):
                    $selectedCategoryId = (int) ($selectedCategoryIds[$lineIndex] ?? ($assetMap[(int) $selectedAssetItemId]['asset_group_id'] ?? 0));
                ?>
                <div class="form-row request-line align-items-end mb-2">
                    <div class="form-group col-md-4 mb-0">
                        <label class="small font-weight-bold">1. Category</label>
                        <select class="form-control asset-category-selection" name="asset_category_ids[]" required>
                            <option value="">-- Select category --</option>
                            <?php foreach ($categories as $category): ?>
                            <option value="<?= (int) $category['id'] ?>" <?= $selectedCategoryId === (int) $category['id'] ? 'selected' : '' ?>><?= htmlspecialchars($category['name'] . ($category['code'] !== '' ? ' (' . $category['code'] . ')' : '')) ?> &mdash; <?= (int) $category['available_assets'] ?> available</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-6 mb-0">
                        <label class="small font-weight-bold">2. Asset</label>
                        <select class="form-control asset-selection" name="asset_item_ids[]" required>
                            <option value="">-- Select asset --</option>
                            <?php foreach ($assets as $asset): ?>
                            <option value="<?= (int) $asset['asset_item_id'] ?>" data-category-id="<?= (int) $asset['asset_group_id'] ?>" <?= (int) $selectedAssetItemId === (int) $asset['asset_item_id'] ? 'selected' : '' ?>><?= htmlspecialchars($asset['item_number'] . ' - ' . $asset['item_name']) ?><?= !empty($asset['serial_number']) ? ' / S/N ' . htmlspecialchars($asset['serial_number']) : '' ?><?= !empty($asset['department_name']) ? ' / ' . htmlspecialchars($asset['department_name']) : '' ?> / <?= htmlspecialchars($asset['condition_status']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-2 mb-0"><button type="button" class="btn btn-outline-danger btn-block remove-line"><i class="fas fa-times"></i> Remove</button></div>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="addRequestLine"><i class="fas fa-plus mr-1"></i>Add another asset</button>
            <hr>
            <div class="form-group"><label>Purpose <span class="text-danger">*</span></label><input name="purpose" class="form-control" value="<?= htmlspecialchars($purpose) ?>" required maxlength="255"></div>
            <div class="form-row">
                <div class="form-group col-md-6"><label>Borrow Start <span class="text-danger">*</span></label><input type="date" name="borrow_start_date" class="form-control" value="<?= htmlspecialchars($borrowStartDate) ?>" required></div>
                <div class="form-group col-md-6"><label>Expected Return <span class="text-danger">*</span></label><input type="date" name="expected_return_date" class="form-control" value="<?= htmlspecialchars($expectedReturnDate) ?>" required></div>
            </div>
            <div class="form-group"><label>Request Note</label><textarea name="request_note" class="form-control" rows="3" maxlength="255"><?= htmlspecialchars($requestNote) ?></textarea></div>
            <div class="asset-action-bar"><a href="asset_request_list.php" class="btn btn-outline-secondary">Cancel</a><button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane mr-1"></i>Submit custody request</button></div>
        </form>
    </div></div>
</div>
<script>
(function () {
    var lines = document.getElementById('requestLines'), add = document.getElementById('addRequestLine'), quantity = document.getElementById('requestQuantity');
    function filterItems(row, resetItem) {
        var category = row.querySelector('.asset-category-selection').value;
        var item = row.querySelector('.asset-selection');
        if (resetItem) item.value = '';
        Array.prototype.forEach.call(item.querySelectorAll('option[data-category-id]'), function (option) {
            var visible = category !== '' && option.getAttribute('data-category-id') === category;
            option.hidden = !visible;
            option.disabled = !visible;
            if (!visible && option.selected) item.value = '';
        });
        item.disabled = category === '';
    }
    function updateQuantity() {
        var count = lines.querySelectorAll('.asset-selection').length;
        quantity.textContent = count;
        lines.querySelectorAll('.remove-line').forEach(function (button) { button.disabled = count === 1; });
        lines.querySelectorAll('.request-line').forEach(function (row) { filterItems(row, false); });
    }
    add.addEventListener('click', function () {
        var clone = lines.querySelector('.request-line').cloneNode(true);
        clone.querySelector('.asset-category-selection').value = '';
        clone.querySelector('.asset-selection').value = '';
        lines.appendChild(clone); updateQuantity();
    });
    lines.addEventListener('change', function (event) {
        if (event.target.classList.contains('asset-category-selection')) {
            filterItems(event.target.closest('.request-line'), true);
        }
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
