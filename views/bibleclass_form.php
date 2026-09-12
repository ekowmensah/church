<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/bible_class_capacity.php';
require_once __DIR__ . '/../helpers/csrf.php';

$error = '';
$editing = isset($_GET['id']) && ctype_digit((string) $_GET['id']);
$id = $editing ? (int) $_GET['id'] : 0;
$bclass = ['name' => '', 'code' => '', 'class_group_id' => '', 'church_id' => ''];

if (!is_logged_in()) {
    $error = 'Not logged in (session or login issue).';
} elseif ((int) ($_SESSION['role_id'] ?? 0) !== 1) {
    $requiredPermission = $editing ? 'edit_bibleclass' : 'create_bibleclass';
    if (!has_permission($requiredPermission)) {
        $error = $editing ? 'No permission to edit Bible classes.' : 'No permission to create Bible classes.';
    }
}

if ($editing) {
    $stmt = $conn->prepare('SELECT id, name, code, class_group_id, church_id FROM bible_classes WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $storedClass = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$storedClass) {
        $error = 'Bible class not found.';
        $editing = false;
        $id = 0;
    } else {
        $bclass = $storedClass;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your form session expired. Refresh the page and try again.';
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $code = trim((string) ($_POST['code'] ?? ''));
    $churchId = (int) ($_POST['church_id'] ?? 0);
    $classGroupId = (int) ($_POST['class_group_id'] ?? 0);
    $bclass = ['name' => $name, 'code' => $code, 'class_group_id' => $classGroupId, 'church_id' => $churchId];

    if ($error === '' && ($name === '' || $code === '' || $churchId <= 0 || $classGroupId <= 0)) {
        $error = 'Name, code, church, and class group are required.';
    } elseif ($error === '' && !preg_match('/^[A-Za-z0-9_-]+$/', $code)) {
        $error = 'Class code may contain only letters, numbers, dashes, and underscores.';
    }

    if ($error === '') {
        $groupStmt = $conn->prepare('SELECT id FROM class_groups WHERE id = ? AND church_id = ? AND is_active = 1 LIMIT 1');
        $groupStmt->bind_param('ii', $classGroupId, $churchId);
        $groupStmt->execute();
        $validGroup = $groupStmt->get_result()->fetch_assoc();
        $groupStmt->close();
        if (!$validGroup) {
            $error = 'Select an active class group belonging to the selected church.';
        }
    }

    if ($error === '') {
        $uniqueSql = 'SELECT id FROM bible_classes WHERE code = ?';
        if ($editing) $uniqueSql .= ' AND id <> ?';
        $uniqueStmt = $conn->prepare($uniqueSql . ' LIMIT 1');
        if ($editing) {
            $uniqueStmt->bind_param('si', $code, $id);
        } else {
            $uniqueStmt->bind_param('s', $code);
        }
        $uniqueStmt->execute();
        $duplicate = $uniqueStmt->get_result()->fetch_assoc();
        $uniqueStmt->close();
        if ($duplicate) $error = 'Class code must be unique.';
    }

    if ($error === '') {
        $conn->begin_transaction();
        try {
            if ($editing) {
                $stmt = $conn->prepare('UPDATE bible_classes SET name = ?, code = ?, church_id = ?, class_group_id = ? WHERE id = ?');
                $stmt->bind_param('ssiii', $name, $code, $churchId, $classGroupId, $id);
                $stmt->execute();
                $stmt->close();
                $savedClassId = $id;
            } else {
                $stmt = $conn->prepare('INSERT INTO bible_classes (name, code, church_id, class_group_id) VALUES (?, ?, ?, ?)');
                $stmt->bind_param('ssii', $name, $code, $churchId, $classGroupId);
                $stmt->execute();
                $savedClassId = (int) $conn->insert_id;
                $stmt->close();
            }

            if ($savedClassId <= 0 || !ensure_bible_class_rule($conn, $savedClassId)) {
                throw new RuntimeException('Unable to create the Bible class capacity rule.');
            }
            $conn->commit();
            header('Location: bibleclass_list.php?' . ($editing ? 'updated=1' : 'added=1'));
            exit;
        } catch (Throwable $exception) {
            $conn->rollback();
            error_log('Bible class save failed: ' . $exception->getMessage());
            $error = 'Database error. Please try again.';
        }
    }
}

$churches = $conn->query('SELECT id, name FROM churches ORDER BY name');
$classgroups = $conn->query('SELECT id, name, church_id, meeting_day FROM class_groups WHERE is_active = 1 ORDER BY name');
$dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800"><?= $editing ? 'Edit Bible Class' : 'Add Bible Class' ?></h1>
    <a href="bibleclass_list.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to List</a>
</div>
<div class="row justify-content-center"><div class="col-lg-7"><div class="card shadow mb-4">
    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Bible Class Details</h6></div>
    <div class="card-body">
        <?php if ($error !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
            <?= csrf_input() ?>
            <div class="form-group">
                <label for="church_id">Church <span class="text-danger">*</span></label>
                <select class="form-control" name="church_id" id="church_id" required>
                    <option value="">-- Select Church --</option>
                    <?php while ($churches && $church = $churches->fetch_assoc()): ?>
                        <option value="<?= (int) $church['id'] ?>" <?= (int) $bclass['church_id'] === (int) $church['id'] ? 'selected' : '' ?>><?= htmlspecialchars($church['name']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="class_group_id">Class Group <span class="text-danger">*</span></label>
                <select class="form-control" name="class_group_id" id="class_group_id" required>
                    <option value="">-- Select Class Group --</option>
                    <?php while ($classgroups && $group = $classgroups->fetch_assoc()): ?>
                        <?php $meetingDay = $group['meeting_day'] === null ? 'day not configured' : $dayNames[(int) $group['meeting_day']]; ?>
                        <option value="<?= (int) $group['id'] ?>" data-church-id="<?= (int) $group['church_id'] ?>" <?= (int) $bclass['class_group_id'] === (int) $group['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($group['name'] . ' (' . $meetingDay . ')') ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <small class="form-text text-muted">Only groups belonging to the selected church are available.</small>
            </div>
            <div class="form-group">
                <label for="name">Class Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="name" id="name" value="<?= htmlspecialchars($bclass['name']) ?>" required>
            </div>
            <div class="form-group">
                <label for="code">Class Code <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="code" id="code" value="<?= htmlspecialchars($bclass['code']) ?>" required>
            </div>
            <button type="submit" class="btn btn-primary">Save</button>
        </form>
    </div>
</div></div></div>
<script>
(function () {
    const churchSelect = document.getElementById('church_id');
    const groupSelect = document.getElementById('class_group_id');
    if (!churchSelect || !groupSelect) return;
    function filterGroups() {
        const churchId = churchSelect.value;
        Array.from(groupSelect.options).forEach(function (option, index) {
            if (index === 0) return;
            const visible = churchId !== '' && option.dataset.churchId === churchId;
            option.hidden = !visible;
            option.disabled = !visible;
            if (!visible && option.selected) option.selected = false;
        });
    }
    churchSelect.addEventListener('change', filterGroups);
    filterGroups();
})();
</script>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
