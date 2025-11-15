<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!(isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1)) {
    if (!has_permission('edit_payment')) {
        $error = 'No permission to edit payment';
    }
}

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header('Location: payment_list.php');
    exit;
}

// Fetch payment
$stmt = $conn->prepare("SELECT * FROM payments WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
if (!$payment) {
    header('Location: payment_list.php');
    exit;
}

// Fetch dropdowns
$members = $conn->query("SELECT id, CONCAT(last_name, ' ', first_name, ' ', middle_name) AS name FROM members ORDER BY last_name, first_name");
$types = $conn->query("SELECT id, name FROM payment_types WHERE active=1 ORDER BY name");

$error = '';

$member_id = $payment['member_id'];
$payment_type_id = $payment['payment_type_id'];
$amount = $payment['amount'];
$mode = $payment['mode'] ?? '';
$payment_date = $payment['payment_date'];
$description = $payment['description'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $member_id = intval($_POST['member_id'] ?? 0);
    $payment_type_id = intval($_POST['payment_type_id'] ?? 0);
    $amount = trim($_POST['amount'] ?? '');
    $mode = trim($_POST['mode'] ?? '');
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $description = trim($_POST['description'] ?? '');
    if (!$member_id || !$payment_type_id || !$amount || !$mode || !$payment_date) {
        $error = 'Please fill in all required fields.';
    } elseif (!is_numeric($amount) || $amount <= 0) {
        $error = 'Amount must be a positive number.';
    } else {
        $stmt = $conn->prepare("UPDATE payments SET member_id=?, payment_type_id=?, amount=?, mode=?, payment_date=?, description=? WHERE id=?");
        $stmt->bind_param('iidsssi', $member_id, $payment_type_id, $amount, $mode, $payment_date, $description, $id);
        if ($stmt->execute()) {
            header('Location: payment_list.php?updated=1');
            exit;
        } else {
            $error = 'Database error. Please try again.';
        }
    }
}

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Edit Payment</h1>
    <a href="payment_list.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to List</a>
</div>
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card shadow mb-4">
            <div class="card-header py-3 bg-primary text-white">
                <h6 class="m-0 font-weight-bold">Edit Payment</h6>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger mb-4"> <?= htmlspecialchars($error) ?> </div>
                <?php endif; ?>
                <form method="post" autocomplete="off">
                    <div class="form-group">
                        <label for="member_id">Member <span class="text-danger">*</span></label>
                        <select class="form-control" id="member_id" name="member_id" required>
                            <option value="">-- Select Member --</option>
                            <?php if ($members && $members->num_rows > 0): while($m = $members->fetch_assoc()): ?>
                                <option value="<?= $m['id'] ?>" <?=($member_id==$m['id']?'selected':'')?>><?= htmlspecialchars($m['name']) ?></option>
                            <?php endwhile; endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="payment_type_id">Payment Type <span class="text-danger">*</span></label>
                        <select class="form-control" id="payment_type_id" name="payment_type_id" required>
                            <option value="">-- Select Type --</option>
                            <?php if ($types && $types->num_rows > 0): while($t = $types->fetch_assoc()): ?>
                                <option value="<?= $t['id'] ?>" <?=($payment_type_id==$t['id']?'selected':'')?>><?= htmlspecialchars($t['name']) ?></option>
                            <?php endwhile; endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="amount">Amount (₦) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0" class="form-control" id="amount" name="amount" value="<?= htmlspecialchars($amount) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="mode">Payment Mode <span class="text-danger">*</span></label>
                        <select class="form-control" id="mode" name="mode" required>
                            <option value="">-- Select Mode --</option>
                            <option value="Cash" <?=($mode=='Cash'?'selected':'')?>>Cash</option>
                            <option value="Transfer" <?=($mode=='Transfer'?'selected':'')?>>Transfer</option>
                            <option value="Cheque" <?=($mode=='Cheque'?'selected':'')?>>Cheque</option>
                            <option value="POS" <?=($mode=='POS'?'selected':'')?>>POS</option>
                            <option value="Online" <?=($mode=='Online'?'selected':'')?>>Online</option>
                            <option value="Offline" <?=($mode=='Offline'?'selected':'')?>>Offline</option>
                            <option value="Other" <?=($mode=='Other'?'selected':'')?>>Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="payment_date">Payment Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="payment_date" name="payment_date" value="<?= htmlspecialchars($payment_date) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="2"><?= htmlspecialchars($description) ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-success">Update Payment</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php $page_content = ob_get_clean(); include '../includes/layout.php'; ?>
