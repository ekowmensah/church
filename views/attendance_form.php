<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Canonical permission check for Attendance Form
require_once __DIR__.'/../helpers/permissions.php';
if (!has_permission('edit_attendance')) {
    http_response_code(403);
    include '../views/errors/403.php';
    exit;
}

$error = '';
$title = '';
$service_date = '';
$is_recurring = 0;
$recurrence_type = '';
$recurrence_day = '';
$church_id = '';
$edit_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Load churches for dropdown
$churches = $conn->query("SELECT id, name FROM churches ORDER BY name ASC");

// Load for edit
if ($edit_id && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $conn->prepare("SELECT * FROM attendance_sessions WHERE id = ?");
    $stmt->bind_param('i', $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $title = $row['title'];
        $service_date = $row['service_date'];
        $is_recurring = $row['is_recurring'];
        $recurrence_type = $row['recurrence_type'];
        $recurrence_day = $row['recurrence_day'];
        $church_id = $row['church_id'];
    } else {
        header('Location: attendance_list.php?notfound=1');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $service_date = trim($_POST['service_date'] ?? '');
    $is_recurring = intval($_POST['is_recurring'] ?? 0);
    $recurrence_type = trim($_POST['recurrence_type'] ?? '');
    $recurrence_day = $_POST['recurrence_day'] ?? '';
    $church_id = intval($_POST['church_id'] ?? 0);
    $edit_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if (!$title || (!$is_recurring && !$service_date) || !$church_id) {
        $error = 'All fields are required.';
    } elseif ($is_recurring && (!$recurrence_type || strlen($recurrence_day) == 0)) {
        $error = 'Please select recurrence type and day.';
    } else {
        if ($edit_id) {
            $stmt = $conn->prepare("UPDATE attendance_sessions SET title=?, service_date=?, is_recurring=?, recurrence_type=?, recurrence_day=?, church_id=? WHERE id=?");
            $stmt->bind_param('ssissii', $title, $service_date, $is_recurring, $recurrence_type, $recurrence_day, $church_id, $edit_id);
            $stmt->execute();
            header('Location: attendance_list.php?updated=1');
            exit;
        } else {
            $stmt = $conn->prepare("INSERT INTO attendance_sessions (title, service_date, is_recurring, recurrence_type, recurrence_day, church_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('ssissi', $title, $service_date, $is_recurring, $recurrence_type, $recurrence_day, $church_id);
            if ($stmt->execute()) {
                header('Location: attendance_list.php?added=1');
                exit;
            } else {
                $error = 'Database error. Please try again.';
            }
        }
    }
}

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800"><?= $edit_id ? 'Edit' : 'Add' ?> Attendance Session</h1>
    <a href="attendance_list.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to List</a>
</div>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow mb-4">
            <div class="card-header py-3 bg-primary text-white">
                <h6 class="m-0 font-weight-bold"><?= $edit_id ? 'Edit' : 'New' ?> Attendance Session</h6>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger mb-4"> <?= htmlspecialchars($error) ?> </div>
                <?php endif; ?>
                <form method="post" autocomplete="off">
    <div class="form-group">
        <label for="church_id">Church <span class="text-danger">*</span></label>
        <select class="form-control" name="church_id" id="church_id" required>
            <option value="">-- Select Church --</option>
            <?php if ($churches && $churches->num_rows > 0): 
                // Reset pointer for edit (if needed)
                $churches->data_seek(0);
                while($ch = $churches->fetch_assoc()): ?>
                <option value="<?= $ch['id'] ?>" <?= ($church_id == $ch['id'] ? 'selected' : '') ?>><?= htmlspecialchars($ch['name']) ?></option>
            <?php endwhile; endif; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="title">Title <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="title" name="title" value="<?= htmlspecialchars($title) ?>" required>
    </div>
    <div class="form-group" id="service_date_group" style="display: <?= !$is_recurring ? 'block' : 'none' ?>;">
        <label for="service_date">Service Date <span class="text-danger">*</span></label>
        <input type="date" class="form-control" id="service_date" name="service_date" value="<?= htmlspecialchars($service_date) ?>" <?= !$is_recurring ? 'required' : '' ?> >
    </div>
    <div class="form-group">
        <label>Session Type <span class="text-danger">*</span></label><br>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="is_recurring" id="one_time" value="0" <?= !$is_recurring ? 'checked' : '' ?>>
            <label class="form-check-label" for="one_time">One-time</label>
        </div>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="is_recurring" id="recurring" value="1" <?= $is_recurring ? 'checked' : '' ?>>
            <label class="form-check-label" for="recurring">Recurring</label>
        </div>
    </div>
    <div id="recurrence_fields" style="display: <?= $is_recurring ? 'block' : 'none' ?>;">
        <div class="form-group">
            <label for="recurrence_type">Recurrence Type <span class="text-danger">*</span></label>
            <select class="form-control" name="recurrence_type" id="recurrence_type">
                <option value="">Select...</option>
                <option value="weekly" <?= $recurrence_type === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                <option value="monthly" <?= $recurrence_type === 'monthly' ? 'selected' : '' ?>>Monthly</option>
            </select>
        </div>
        <div class="form-group">
            <label for="recurrence_day_weekly">Day of Week <span class="text-danger">*</span></label>
            <select class="form-control" id="recurrence_day_weekly" <?= $recurrence_type === 'weekly' ? 'name="recurrence_day"' : '' ?>>
                <option value="">Select...</option>
                <option value="0" <?= $recurrence_day === '0' ? 'selected' : '' ?>>Sunday</option>
                <option value="1" <?= $recurrence_day === '1' ? 'selected' : '' ?>>Monday</option>
                <option value="2" <?= $recurrence_day === '2' ? 'selected' : '' ?>>Tuesday</option>
                <option value="3" <?= $recurrence_day === '3' ? 'selected' : '' ?>>Wednesday</option>
                <option value="4" <?= $recurrence_day === '4' ? 'selected' : '' ?>>Thursday</option>
                <option value="5" <?= $recurrence_day === '5' ? 'selected' : '' ?>>Friday</option>
                <option value="6" <?= $recurrence_day === '6' ? 'selected' : '' ?>>Saturday</option>
            </select>
        </div>
        <div class="form-group">
            <label for="recurrence_day_monthly">Month <span class="text-danger">*</span></label>
            <select class="form-control" id="recurrence_day_monthly" <?= $recurrence_type === 'monthly' ? 'name="recurrence_day"' : '' ?>>
                <option value="">Select...</option>
                <option value="1" <?= $recurrence_day == '1' ? 'selected' : '' ?>>January</option>
                <option value="2" <?= $recurrence_day == '2' ? 'selected' : '' ?>>February</option>
                <option value="3" <?= $recurrence_day == '3' ? 'selected' : '' ?>>March</option>
                <option value="4" <?= $recurrence_day == '4' ? 'selected' : '' ?>>April</option>
                <option value="5" <?= $recurrence_day == '5' ? 'selected' : '' ?>>May</option>
                <option value="6" <?= $recurrence_day == '6' ? 'selected' : '' ?>>June</option>
                <option value="7" <?= $recurrence_day == '7' ? 'selected' : '' ?>>July</option>
                <option value="8" <?= $recurrence_day == '8' ? 'selected' : '' ?>>August</option>
                <option value="9" <?= $recurrence_day == '9' ? 'selected' : '' ?>>September</option>
                <option value="10" <?= $recurrence_day == '10' ? 'selected' : '' ?>>October</option>
                <option value="11" <?= $recurrence_day == '11' ? 'selected' : '' ?>>November</option>
                <option value="12" <?= $recurrence_day == '12' ? 'selected' : '' ?>>December</option>
            </select>
        </div>
    </div>
    <button type="submit" class="btn btn-success"><?= $edit_id ? 'Update' : 'Add' ?> Session</button>
</form>
<script>
    function showRecurrenceFields() {
        var recurring = document.getElementById('recurring').checked;
        var serviceDateGroup = document.getElementById('service_date_group');
        var serviceDateInput = document.getElementById('service_date');
        document.getElementById('recurrence_fields').style.display = recurring ? 'block' : 'none';
        serviceDateGroup.style.display = recurring ? 'none' : 'block';
        if (recurring) {
            serviceDateInput.removeAttribute('required');
            serviceDateInput.value = '';
        } else {
            serviceDateInput.setAttribute('required', 'required');
        }
    }
    function showRecurrenceDay() {
        var type = document.getElementById('recurrence_type').value;
        var weekly = document.getElementById('recurrence_day_weekly');
        var monthly = document.getElementById('recurrence_day_monthly');
        // Remove name from both
        weekly.removeAttribute('name');
        monthly.removeAttribute('name');
        if (type === 'weekly') {
            weekly.setAttribute('name', 'recurrence_day');
            weekly.disabled = false;
            weekly.parentElement.style.display = 'block';
            monthly.disabled = true;
            monthly.parentElement.style.display = 'none';
        } else if (type === 'monthly') {
            monthly.setAttribute('name', 'recurrence_day');
            monthly.disabled = false;
            monthly.parentElement.style.display = 'block';
            weekly.disabled = true;
            weekly.parentElement.style.display = 'none';
        } else {
            weekly.disabled = true;
            monthly.disabled = true;
            weekly.parentElement.style.display = 'none';
            monthly.parentElement.style.display = 'none';
        }
    }
    document.getElementById('one_time').addEventListener('change', showRecurrenceFields);
    document.getElementById('recurring').addEventListener('change', showRecurrenceFields);
    document.getElementById('recurrence_type').addEventListener('change', showRecurrenceDay);
    window.onload = function() { showRecurrenceFields(); showRecurrenceDay(); };
</script>
            </div>
        </div>
    </div>
</div>
<?php $page_content = ob_get_clean(); include '../includes/layout.php'; ?>
