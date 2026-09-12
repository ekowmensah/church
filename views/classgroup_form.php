<?php
//if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/csrf.php';

$error = '';
$success = '';
$editing = false;
$group = ['name' => '', 'church_id' => '', 'meeting_day' => ''];
$churches = $conn->query('SELECT id, name FROM churches ORDER BY name');

if (!is_logged_in()) {
    $error = 'Not logged in (session or login issue)';
} elseif (!(isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1)) {
    // Determine if adding or editing
    $is_edit = (isset($_GET['id']) && is_numeric($_GET['id'])) || $editing;
    if ($is_edit) {
        if (!has_permission('edit_classgroup')) {
            $error = 'No permission to edit class group';
        }
    } else {
        if (!has_permission('create_classgroup')) {
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
        $group = ['name' => '', 'church_id' => '', 'meeting_day' => ''];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $error = 'Your form expired. Refresh the page and try again.';
    }
    $name = trim($_POST['name'] ?? '');
    $churchId = (int) ($_POST['church_id'] ?? 0);
    $meetingDay = filter_var($_POST['meeting_day'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 0, 'max_range' => 6],
    ]);
    if (!$error && (!$name || $churchId < 1 || $meetingDay === false)) {
        $error = 'Church, group name, and meeting day are required.';
    } elseif (!$error && $editing) {
        $scopeCheck = $conn->prepare('SELECT COUNT(*) AS total FROM bible_classes WHERE class_group_id = ? AND church_id <> ?');
        $scopeCheck->bind_param('ii', $id, $churchId);
        $scopeCheck->execute();
        $scopeMismatch = (int) ($scopeCheck->get_result()->fetch_assoc()['total'] ?? 0);
        $scopeCheck->close();
        if ($scopeMismatch > 0) {
            $error = 'Move or update the assigned Bible classes before changing this group to another church.';
        }
    }
    if (!$error) {
        // Check for duplicate name
        if ($editing) {
            // When editing, check for duplicates excluding the current record
            $duplicate_check = $conn->prepare('SELECT id FROM class_groups WHERE church_id = ? AND name = ? AND id != ?');
            $duplicate_check->bind_param('isi', $churchId, $name, $id);
        } else {
            // When adding, check for any existing record with the same name
            $duplicate_check = $conn->prepare('SELECT id FROM class_groups WHERE church_id = ? AND name = ?');
            $duplicate_check->bind_param('is', $churchId, $name);
        }
        
        $duplicate_check->execute();
        $duplicate_check->store_result();
        
        if ($duplicate_check->num_rows > 0) {
            $error = 'A class group with this name already exists. Please choose a different name.';
            $duplicate_check->close();
        } else {
            $duplicate_check->close();
            
            if ($editing) {
                $stmt = $conn->prepare('UPDATE class_groups SET name = ?, church_id = ?, meeting_day = ?, is_active = 1 WHERE id = ?');
                $stmt->bind_param('siii', $name, $churchId, $meetingDay, $id);
                $stmt->execute();
                if ($stmt->affected_rows >= 0) {
                    $review = $conn->prepare("UPDATE class_group_schedule_review SET resolved = 1, resolved_by = ?, resolved_at = NOW() WHERE class_group_id = ? AND issue_type = 'missing_meeting_day'");
                    $actorUserId = (int) ($_SESSION['user_id'] ?? 0) ?: null;
                    $review->bind_param('ii', $actorUserId, $id);
                    $review->execute();
                    $review->close();
                    header('Location: classgroup_list.php?updated=1');
                    exit;
                } else {
                    $error = 'Database error. Please try again.';
                }
            } else {
                $stmt = $conn->prepare('INSERT INTO class_groups (church_id, name, meeting_day, is_active) VALUES (?, ?, ?, 1)');
                $stmt->bind_param('isi', $churchId, $name, $meetingDay);
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
    $group = ['name' => $name, 'church_id' => $churchId, 'meeting_day' => $meetingDay];
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
                    <?= csrf_input() ?>
                    <div class="form-group">
                        <label for="church_id">Church <span class="text-danger">*</span></label>
                        <select class="form-control" name="church_id" id="church_id" required>
                            <option value="">-- Select Church --</option>
                            <?php if ($churches): while ($church = $churches->fetch_assoc()): ?>
                                <option value="<?= (int) $church['id'] ?>" <?= (int) ($group['church_id'] ?? 0) === (int) $church['id'] ? 'selected' : '' ?>><?= htmlspecialchars($church['name']) ?></option>
                            <?php endwhile; endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="name">Group Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" id="name" value="<?=htmlspecialchars($group['name'])?>" required>
                    </div>
                    <div class="form-group">
                        <label for="meeting_day">Weekly Meeting Day <span class="text-danger">*</span></label>
                        <select class="form-control" name="meeting_day" id="meeting_day" required>
                            <option value="">-- Select Meeting Day --</option>
                            <?php foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $dayNumber => $dayName): ?>
                                <option value="<?= $dayNumber ?>" <?= (string) ($group['meeting_day'] ?? '') === (string) $dayNumber ? 'selected' : '' ?>><?= $dayName ?></option>
                            <?php endforeach; ?>
                        </select>
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
