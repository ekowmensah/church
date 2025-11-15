<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
// Permission check
if (!has_permission('view_payment_list')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}


$error = '';
$success = '';
$name = '';
$description = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    if (!$name) {
        $error = 'Payment type name is required.';
    } else {
        $stmt = $conn->prepare("INSERT INTO payment_types (name, description) VALUES (?, ?)");
        $stmt->bind_param('ss', $name, $description);
        if ($stmt->execute()) {
            header('Location: paymenttype_list.php?added=1');
            exit;
        } else {
            $error = 'Database error. Please try again.';
        }
    }
}

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Add Payment Type</h1>
    <a href="paymenttype_list.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to List</a>
</div>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow mb-4">
            <div class="card-header py-3 bg-primary text-white">
                <h6 class="m-0 font-weight-bold">New Payment Type</h6>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger mb-4"> <?= htmlspecialchars($error) ?> </div>
                <?php elseif ($success): ?>
                    <div class="alert alert-success mb-4"> <?= htmlspecialchars($success) ?> </div>
                <?php endif; ?>
                <form method="post" autocomplete="off">
                    <div class="form-group">
                        <label for="name">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($name) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="2"><?= htmlspecialchars($description) ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-success">Add Payment Type</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php $page_content = ob_get_clean(); include '../includes/layout.php'; ?>
