<?php
//if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

$error = '';
$success = '';
$editing = false;
$bclass = ['name'=>'','code'=>'','class_group_id'=>'','leader_id'=>'','church_id'=>''];

// Fetch churches for dropdown (always at top)
$churches = $conn->query("SELECT id, name FROM churches ORDER BY name ASC");

if (!is_logged_in()) {
    $error = 'Not logged in (session or login issue)';
} elseif (!(isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1)) {
    // Determine if adding or editing
    $is_edit = (isset($_GET['id']) && is_numeric($_GET['id'])) || $editing;
    if ($is_edit) {
        if (function_exists('has_permission') && !has_permission('edit_bibleclass')) {
            $error = 'No permission to edit bible class';
        }
    } else {
        if (function_exists('has_permission') && !has_permission('add_bibleclass')) {
            $error = 'No permission to add bible class';
        }
    }
}

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $editing = true;
    $id = intval($_GET['id']);
    $stmt = $conn->prepare('SELECT * FROM bible_classes WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $bclass = $result->fetch_assoc();
    if (!$bclass) {
        $error = 'Bible class not found.';
        $editing = false;
        $bclass = ['name'=>'','code'=>'','class_group_id'=>'','leader_id'=>'','church_id'=>''];
    } else {
        if (!isset($bclass['church_id'])) $bclass['church_id'] = '';
    }
}

// Fetch class groups for dropdown
$classgroups = $conn->query("SELECT id, name FROM class_groups ORDER BY name ASC");

// Fetch members for leader dropdown if class_group_id is set
$members = [];
if (!empty($bclass['class_group_id'])) {
    $stmt = $conn->prepare("SELECT id, name FROM members WHERE class_id = ? ORDER BY name ASC");
    $stmt->bind_param('i', $bclass['class_group_id']);
    $stmt->execute();
    $members = $stmt->get_result();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $church_id = intval($_POST['church_id'] ?? 0);
    if (!$name || !$code || !$church_id) {
        $error = 'Name, Code, and Church are required.';
    } elseif (!preg_match('/^[A-Za-z0-9_-]+$/', $code)) {
        $error = 'Class Code must contain only letters, numbers, dash, or underscore.';
    } else {
        // Check uniqueness (ignore own record if editing)
        $sql = 'SELECT id FROM bible_classes WHERE code = ?';
        $params = [$code];
        $types = 's';
        if ($editing) {
            $sql .= ' AND id != ?';
            $params[] = $id;
            $types .= 'i';
        }
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        if ($exists) {
            $error = 'Class Code must be unique.';
        }
    if (!$error) {
        if ($editing) {
            $stmt = $conn->prepare('UPDATE bible_classes SET name=?, code=?, church_id=? WHERE id=?');
            $stmt->bind_param('ssii', $name, $code, $church_id, $id);
            $stmt->execute();
            if ($stmt->affected_rows >= 0) {
                header('Location: bibleclass_list.php?updated=1');
                exit;
            } else {
                $error = 'Database error. Please try again.';
            }
        } else {
            $stmt = $conn->prepare('INSERT INTO bible_classes (name, code, church_id) VALUES (?, ?, ?)');
            $stmt->bind_param('ssi', $name, $code, $church_id);
            $stmt->execute();
            if ($stmt->affected_rows > 0) {
                header('Location: bibleclass_list.php?added=1');
                exit;
            } else {
                $error = 'Database error. Please try again.';
            }
        }
    }
    $class_group_id = isset($_POST['class_group_id']) ? intval($_POST['class_group_id']) : '';
    $leader_id = isset($_POST['leader_id']) ? intval($_POST['leader_id']) : '';
    $bclass = ['name'=>$name,'code'=>$code,'class_group_id'=>$class_group_id,'leader_id'=>$leader_id,'church_id'=>$church_id];
}
}

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800"><?= $editing ? 'Edit Bible Class' : 'Add Bible Class' ?></h1>
    <a href="bibleclass_list.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to List</a>
</div>
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Bible Class Details</h6>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"> <?= htmlspecialchars($error) ?> </div>
                <?php endif; ?>
<form method="post" autocomplete="off">
    <script>
    // AJAX to reload members when class group changes
    function reloadMembers() {
        var groupId = document.getElementById('class_group_id').value;
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'get_class_members.php?class_group_id='+groupId, true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                document.getElementById('leader_id').innerHTML = xhr.responseText;
            }
        };
        xhr.send();
    }
    </script>
    <div class="form-group">
        <label for="church_id">Church <span class="text-danger">*</span></label>
        <select class="form-control" name="church_id" id="church_id" required>
            <option value="">-- Select Church --</option>
            <?php 
            if ($churches && $churches->num_rows > 0) {
                $churches->data_seek(0); // reset pointer
                while($ch = $churches->fetch_assoc()): ?>
                    <option value="<?=$ch['id']?>" <?=($bclass['church_id']==$ch['id']?'selected':'')?>><?=htmlspecialchars($ch['name'])?></option>
            <?php endwhile; 
            } ?>
        </select>
    </div>
    <div class="form-group">
        <label for="class_group_id">Class Group <span class="text-danger">*</span></label>
        <select class="form-control" name="class_group_id" id="class_group_id" required>
            <option value="">-- Select Class Group --</option>
            <?php 
            if ($classgroups && $classgroups->num_rows > 0) {
                $classgroups->data_seek(0); // reset pointer
                while($cg = $classgroups->fetch_assoc()): ?>
                    <option value="<?=$cg['id']?>" <?=($bclass['class_group_id']==$cg['id']?'selected':'')?>><?=htmlspecialchars($cg['name'])?></option>
            <?php endwhile; 
            } ?>
        </select>
    </div>
    <div class="form-group">
        <label for="name">Class Name <span class="text-danger">*</span></label>
        <input type="text" class="form-control" name="name" id="name" value="<?=htmlspecialchars($bclass['name'])?>" required>
    </div>
    <div class="form-group">
        <label for="code">Class Code <span class="text-danger">*</span></label>
        <input type="text" class="form-control" name="code" id="code" value="<?=htmlspecialchars($bclass['code'])?>" required>
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
