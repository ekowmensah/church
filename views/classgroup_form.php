<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

$error = '';
$success = '';
$editing = false;
$group = ['name'=>''];

if (!is_logged_in()) {
    $error = 'Not logged in (session or login issue)';
} elseif (!(isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1)) {
    // Determine if adding or editing
    $is_edit = (isset($_GET['id']) && is_numeric($_GET['id'])) || $editing;
    if ($is_edit) {
        if (function_exists('has_permission') && !has_permission('edit_classgroup')) {
            $error = 'No permission to edit class group';
        }
    } else {
        if (function_exists('has_permission') && !has_permission('add_classgroup')) {
            $error = 'No permission to add class group';
        }
    }
}

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $editing = true;
    $id = intval($_GET['id']);
    $stmt = $conn->prepare('SELECT * FROM class_groups WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $group = $result->fetch_assoc();
    if (!$group) {
        $error = 'Class group not found.';
        $editing = false;
        $group = ['name'=>''];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $name = trim($_POST['name'] ?? '');
    if (!$name) {
        $error = 'Name is required.';
    } else {
        // Check for duplicate name
        if ($editing) {
            // When editing, check for duplicates excluding the current record
            $duplicate_check = $conn->prepare('SELECT id FROM class_groups WHERE name = ? AND id != ?');
            $duplicate_check->bind_param('si', $name, $id);
        } else {
            // When adding, check for any existing record with the same name
            $duplicate_check = $conn->prepare('SELECT id FROM class_groups WHERE name = ?');
            $duplicate_check->bind_param('s', $name);
        }
        
        $duplicate_check->execute();
        $duplicate_check->store_result();
        
        if ($duplicate_check->num_rows > 0) {
            $error = 'A class group with this name already exists. Please choose a different name.';
            $duplicate_check->close();
        } else {
            $duplicate_check->close();
            
            if ($editing) {
                $stmt = $conn->prepare('UPDATE class_groups SET name=? WHERE id=?');
                $stmt->bind_param('si', $name, $id);
                $stmt->execute();
                if ($stmt->affected_rows >= 0) {
                    header('Location: classgroup_list.php?updated=1');
                    exit;
                } else {
                    $error = 'Database error. Please try again.';
                }
            } else {
                $stmt = $conn->prepare('INSERT INTO class_groups (name) VALUES (?)');
                $stmt->bind_param('s', $name);
                $stmt->execute();
                if ($stmt->affected_rows > 0) {
                    header('Location: classgroup_list.php?added=1');
                    exit;
                } else {
                    $error = 'Database error. Please try again.';
                }
            }
        }
    }
    $group = ['name'=>$name];
}

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800"><?= $editing ? 'Edit Class Group' : 'Add Class Group' ?></h1>
    <a href="classgroup_list.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to List</a>
</div>
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Class Group Details</h6>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"> <?= htmlspecialchars($error) ?> </div>
                <?php endif; ?>
                <form method="post" autocomplete="off">
                    <div class="form-group">
                        <label for="name">Group Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" id="name" value="<?=htmlspecialchars($group['name'])?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Save</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
