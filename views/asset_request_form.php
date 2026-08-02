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

$isSuper = asset_is_super_admin();
$churchId = $isSuper
    ? (isset($_GET['church_id']) && (int) $_GET['church_id'] > 0 ? (int) $_GET['church_id'] : asset_current_church_id($conn))
    : asset_current_church_id($conn);
$assetId = isset($_GET['asset_id']) ? (int) $_GET['asset_id'] : 0;
$error = '';

$actor = asset_use_request_actor($conn);
$purpose = '';
$requestNote = '';
$quantityRequested = 1;
$borrowStartDate = date('Y-m-d');
$expectedReturnDate = date('Y-m-d', strtotime('+7 days'));

$assetSql = "
    SELECT a.id, a.asset_code, a.item_name, a.quantity, a.status,
           d.name AS department_name,
           " . (asset_can_use_groups($conn) ? "g.name AS asset_group_name," : "NULL AS asset_group_name,") . "
           c.name AS church_name
    FROM assets a
    LEFT JOIN asset_departments d ON d.id = a.department_id
    " . (asset_can_use_groups($conn) ? "LEFT JOIN asset_groups g ON g.id = a.asset_group_id" : "") . "
    LEFT JOIN churches c ON c.id = a.church_id
    WHERE a.status = 'active'
";
$assetTypes = '';
$assetParams = [];
if (!$isSuper || $churchId) {
    $assetSql .= ' AND a.church_id = ?';
    $assetTypes .= 'i';
    $assetParams[] = $churchId;
}
$assetSql .= ' ORDER BY a.item_name ASC';
$stmt = $conn->prepare($assetSql);
if ($assetTypes !== '') {
    $stmt->bind_param($assetTypes, ...$assetParams);
}
$stmt->execute();
$res = $stmt->get_result();
$assets = [];
while ($row = $res->fetch_assoc()) {
    $assets[] = $row;
}
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $assetId = (int) ($_POST['asset_id'] ?? 0);
    $purpose = trim((string) ($_POST['purpose'] ?? ''));
    $requestNote = trim((string) ($_POST['request_note'] ?? ''));
    $quantityRequested = max(1, (int) ($_POST['quantity_requested'] ?? 1));
    $borrowStartDate = trim((string) ($_POST['borrow_start_date'] ?? ''));
    $expectedReturnDate = trim((string) ($_POST['expected_return_date'] ?? ''));

    $assetLookupSql = 'SELECT id, church_id, asset_code, item_name, quantity, status FROM assets WHERE id = ? LIMIT 1';
    $assetStmt = $conn->prepare($assetLookupSql);
    $assetStmt->bind_param('i', $assetId);
    $assetStmt->execute();
    $asset = $assetStmt->get_result()->fetch_assoc();
    $assetStmt->close();

    if (!$asset) {
        $error = 'Please select a valid asset.';
    } elseif (!$isSuper && (int) $asset['church_id'] !== (int) $churchId) {
        $error = 'You cannot request assets outside your church.';
    } elseif ((string) ($asset['status'] ?? '') !== 'active') {
        $error = 'Only active assets can be requested.';
    } elseif ($purpose === '') {
        $error = 'Purpose is required.';
    } elseif ($borrowStartDate === '' || $expectedReturnDate === '') {
        $error = 'Borrowing dates are required.';
    } elseif ($expectedReturnDate < $borrowStartDate) {
        $error = 'Expected return date cannot be earlier than the borrowing start date.';
    } elseif ($quantityRequested > (int) ($asset['quantity'] ?? 1)) {
        $error = 'Requested quantity cannot exceed the recorded asset quantity.';
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO asset_use_requests (church_id, asset_id, requested_by_user_id, requested_by_member_id, requester_name, requester_phone, purpose, request_note, quantity_requested, borrow_start_date, expected_return_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")'
        );
        $requesterPhone = (string) ($actor['phone'] ?? '');
        $requestedByUserId = $actor['user_id'];
        $requestedByMemberId = $actor['member_id'];
        $requestChurchId = (int) $asset['church_id'];
        $requesterName = (string) $actor['name'];
        $stmt->bind_param(
            'iiiissssiss',
            $requestChurchId,
            $assetId,
            $requestedByUserId,
            $requestedByMemberId,
            $requesterName,
            $requesterPhone,
            $purpose,
            $requestNote,
            $quantityRequested,
            $borrowStartDate,
            $expectedReturnDate
        );
        $ok = $stmt->execute();
        $requestId = (int) $conn->insert_id;
        $stmt->close();

        if ($ok) {
            asset_log_action('asset_use_request_create', 'asset_use_request', $requestId, [
                'asset_id' => $assetId,
                'asset_code' => (string) $asset['asset_code'],
                'church_id' => $requestChurchId,
                'quantity_requested' => $quantityRequested,
                'borrow_start_date' => $borrowStartDate,
                'expected_return_date' => $expectedReturnDate,
            ], [], [
                'purpose' => $purpose,
                'request_note' => $requestNote,
                'requester_name' => $requesterName,
            ]);
            header('Location: asset_request_list.php?created=1');
            exit;
        }
        $error = 'Failed to submit asset request.';
    }
}

ob_start();
?>
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="mb-1"><i class="fas fa-hand-holding mr-2"></i>Request Asset Use</h2>
            <small class="text-muted">Submit a request to borrow or use a church asset.</small>
        </div>
        <a href="asset_request_list.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left mr-1"></i> Back</a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <form method="post" autocomplete="off">
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Requester</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars((string) $actor['name']) ?>" readonly>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Requester Phone</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars((string) ($actor['phone'] ?? '')) ?>" readonly>
                    </div>
                </div>

                <div class="form-group">
                    <label for="asset_id">Asset <span class="text-danger">*</span></label>
                    <select class="form-control" id="asset_id" name="asset_id" required>
                        <option value="">-- Select Asset --</option>
                        <?php foreach ($assets as $row): ?>
                            <option value="<?= (int) $row['id'] ?>" data-quantity="<?= (int) ($row['quantity'] ?? 1) ?>" <?= $assetId === (int) $row['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) $row['asset_code']) ?> - <?= htmlspecialchars((string) $row['item_name']) ?>
                                <?= !empty($row['asset_group_name']) ? ' - Category: ' . htmlspecialchars((string) $row['asset_group_name']) : '' ?>
                                <?= !empty($row['department_name']) ? ' - ' . htmlspecialchars((string) $row['department_name']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-3">
                        <label for="quantity_requested">Quantity <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="quantity_requested" name="quantity_requested" value="<?= (int) $quantityRequested ?>" min="1" required>
                    </div>
                    <div class="form-group col-md-3">
                        <label for="borrow_start_date">Borrow Start Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="borrow_start_date" name="borrow_start_date" value="<?= htmlspecialchars($borrowStartDate) ?>" required>
                    </div>
                    <div class="form-group col-md-3">
                        <label for="expected_return_date">Expected Return Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="expected_return_date" name="expected_return_date" value="<?= htmlspecialchars($expectedReturnDate) ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="purpose">Purpose <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="purpose" name="purpose" value="<?= htmlspecialchars($purpose) ?>" maxlength="255" required>
                </div>

                <div class="form-group">
                    <label for="request_note">Additional Note</label>
                    <textarea class="form-control" id="request_note" name="request_note" rows="3"><?= htmlspecialchars($requestNote) ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane mr-1"></i> Submit Request</button>
            </form>
        </div>
    </div>
</div>
<script>
(function () {
    var assetSelect = document.getElementById('asset_id');
    var qtyInput = document.getElementById('quantity_requested');

    function syncQuantityLimit() {
        if (!assetSelect || !qtyInput) {
            return;
        }
        var option = assetSelect.options[assetSelect.selectedIndex];
        var maxQty = parseInt((option && option.getAttribute('data-quantity')) || '1', 10);
        qtyInput.max = maxQty > 0 ? maxQty : 1;
        if (parseInt(qtyInput.value || '0', 10) > maxQty) {
            qtyInput.value = maxQty;
        }
    }

    if (assetSelect) {
        assetSelect.addEventListener('change', syncQuantityLimit);
        syncQuantityLimit();
    }
})();
</script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
