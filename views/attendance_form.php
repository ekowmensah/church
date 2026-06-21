<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Canonical permission check for Attendance Form
require_once __DIR__.'/../helpers/permissions_v2.php';
if (!has_permission('edit_attendance')) {
    http_response_code(403);
    include '../views/errors/403.php';
    exit;
}

function attendance_scope_columns_available($conn) {
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    $sql = "SELECT COUNT(*) AS cnt
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'attendance_sessions'
              AND COLUMN_NAME IN ('attendance_scope', 'scope_id')";
    $res = $conn->query($sql);
    $row = $res ? $res->fetch_assoc() : null;
    $available = ($row && intval($row['cnt']) === 2);
    return $available;
}

function table_exists($conn, $tableName) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row && intval($row['cnt']) > 0;
}

function normalize_date_input($rawDate) {
    $rawDate = trim((string)$rawDate);
    if ($rawDate === '' || $rawDate === '0000-00-00') {
        return null;
    }
    $ts = strtotime($rawDate);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d', $ts);
}

$scope_columns_available = attendance_scope_columns_available($conn);
$organizations_table_available = table_exists($conn, 'organizations');

$error = '';
$title = '';
$service_date = '';
$is_recurring = 0;
$recurrence_type = '';
$recurrence_day = '';
$church_id = '';
$attendance_scope = 'church';
$scope_id = null;
$scope_class_id = null;
$scope_org_id = null;
$edit_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Load churches for dropdown
$churches = $conn->query("SELECT id, name FROM churches ORDER BY name ASC");
// Load all bible classes for optional class-scoped sessions
$bible_classes = $conn->query("SELECT id, church_id, name, code FROM bible_classes ORDER BY name ASC");
$organizations = $organizations_table_available
    ? $conn->query("SELECT id, church_id, name FROM organizations ORDER BY name ASC")
    : false;

// Load for edit
if ($edit_id && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $conn->prepare("SELECT * FROM attendance_sessions WHERE id = ?");
    $stmt->bind_param('i', $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $title = $row['title'];
        $service_date = normalize_date_input($row['service_date'] ?? '') ?? '';
        $is_recurring = $row['is_recurring'];
        $recurrence_type = $row['recurrence_type'];
        $recurrence_day = $row['recurrence_day'];
        $church_id = $row['church_id'];
        if ($scope_columns_available) {
            $attendance_scope = trim((string)($row['attendance_scope'] ?? ''));
            if ($attendance_scope === '') {
                $attendance_scope = 'church';
            }
            $scope_id = isset($row['scope_id']) ? intval($row['scope_id']) : null;
            if ($attendance_scope === 'bible_class') {
                $scope_class_id = $scope_id;
            } elseif ($attendance_scope === 'organization') {
                $scope_org_id = $scope_id;
            }
        }
    } else {
        header('Location: attendance_list.php?notfound=1');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $service_date = normalize_date_input($_POST['service_date'] ?? '');
    $is_recurring = intval($_POST['is_recurring'] ?? 0);
    $recurrence_type = trim($_POST['recurrence_type'] ?? '');
    $recurrence_day = $_POST['recurrence_day'] ?? '';
    $church_id = intval($_POST['church_id'] ?? 0);
    if ($scope_columns_available) {
        $attendance_scope = trim((string)($_POST['attendance_scope'] ?? 'church'));
        if (!in_array($attendance_scope, ['church', 'bible_class', 'organization'], true)) {
            $attendance_scope = 'church';
        }
        $scope_class_id = isset($_POST['scope_class_id']) && $_POST['scope_class_id'] !== '' ? intval($_POST['scope_class_id']) : null;
        $scope_org_id = isset($_POST['scope_org_id']) && $_POST['scope_org_id'] !== '' ? intval($_POST['scope_org_id']) : null;
        if ($attendance_scope === 'bible_class') {
            $scope_id = $scope_class_id;
        } elseif ($attendance_scope === 'organization') {
            $scope_id = $scope_org_id;
        } else {
            $scope_id = null;
        }
    }
    $edit_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if (!$title || (!$is_recurring && !$service_date) || !$church_id) {
        $error = 'All fields are required.';
    } elseif ($is_recurring && (!$recurrence_type || strlen($recurrence_day) == 0)) {
        $error = 'Please select recurrence type and day.';
    } elseif ($scope_columns_available && $attendance_scope === 'bible_class' && (!$scope_id || $scope_id <= 0)) {
        $error = 'Please select a Bible class for class-scoped attendance sessions.';
    } elseif ($scope_columns_available && $attendance_scope === 'organization' && (!$scope_id || $scope_id <= 0)) {
        $error = 'Please select an organization for organization-scoped attendance sessions.';
    } elseif ($scope_columns_available && $attendance_scope === 'organization' && !$organizations_table_available) {
        $error = 'Organization scope is unavailable on this database.';
    } else {
        if ($is_recurring) {
            $service_date = null;
        }

        if ($edit_id) {
            if ($scope_columns_available) {
                $stmt = $conn->prepare("UPDATE attendance_sessions SET title=?, service_date=?, is_recurring=?, recurrence_type=?, recurrence_day=?, church_id=?, attendance_scope=?, scope_id=? WHERE id=?");
                $stmt->bind_param('ssissisii', $title, $service_date, $is_recurring, $recurrence_type, $recurrence_day, $church_id, $attendance_scope, $scope_id, $edit_id);
            } else {
                $stmt = $conn->prepare("UPDATE attendance_sessions SET title=?, service_date=?, is_recurring=?, recurrence_type=?, recurrence_day=?, church_id=? WHERE id=?");
                $stmt->bind_param('ssissii', $title, $service_date, $is_recurring, $recurrence_type, $recurrence_day, $church_id, $edit_id);
            }
            $stmt->execute();
            $stmt->close();

            header('Location: attendance_list.php?updated=1');
            exit;
        } else {
            if ($scope_columns_available) {
                $stmt = $conn->prepare("INSERT INTO attendance_sessions (title, service_date, is_recurring, recurrence_type, recurrence_day, church_id, attendance_scope, scope_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('ssissisi', $title, $service_date, $is_recurring, $recurrence_type, $recurrence_day, $church_id, $attendance_scope, $scope_id);
            } else {
                $stmt = $conn->prepare("INSERT INTO attendance_sessions (title, service_date, is_recurring, recurrence_type, recurrence_day, church_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('ssissi', $title, $service_date, $is_recurring, $recurrence_type, $recurrence_day, $church_id);
            }
            if ($stmt->execute()) {
                $stmt->close();

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
<style>
    .attendance-form-page {
        background: linear-gradient(180deg, #f4f7fc 0%, #eef3f9 100%);
        padding: 1rem 0 2rem;
    }
    .attendance-form-shell {
        max-width: 980px;
        margin: 0 auto;
    }
    .attendance-hero {
        background: linear-gradient(135deg, #1f4e79 0%, #2f6ca5 55%, #3d7fbe 100%);
        color: #fff;
        border-radius: 16px;
        padding: 1.4rem 1.6rem;
        box-shadow: 0 8px 24px rgba(25, 60, 96, 0.25);
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
    }
    .attendance-hero h1 {
        font-size: 1.35rem;
        font-weight: 700;
        margin: 0;
        letter-spacing: 0.01em;
    }
    .attendance-hero p {
        margin: 0.25rem 0 0;
        opacity: 0.88;
        font-size: 0.92rem;
    }
    .attendance-main-card {
        background: #fff;
        border: 1px solid #dfe7f2;
        border-radius: 16px;
        box-shadow: 0 8px 20px rgba(19, 45, 72, 0.08);
        overflow: hidden;
    }
    .attendance-main-body {
        padding: 1.25rem;
    }
    .form-section {
        border: 1px solid #e6edf7;
        border-radius: 12px;
        padding: 1rem;
        margin-bottom: 1rem;
        background: #fbfdff;
    }
    .form-section h6 {
        font-weight: 700;
        font-size: 0.95rem;
        color: #244b73;
        margin-bottom: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .session-type-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.5rem;
    }
    .session-type-option input[type="radio"] {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }
    .session-type-option label {
        width: 100%;
        margin: 0;
        border: 1px solid #cad9eb;
        border-radius: 10px;
        padding: 0.65rem 0.8rem;
        font-weight: 600;
        color: #375674;
        background: #fff;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        gap: 0.45rem;
    }
    .session-type-option input[type="radio"]:checked + label {
        border-color: #2f6ca5;
        background: #edf5ff;
        color: #1f4e79;
        box-shadow: 0 0 0 2px rgba(47, 108, 165, 0.1) inset;
    }
    .scope-hint {
        font-size: 0.8rem;
        margin-top: 0.4rem;
        color: #5f7388;
    }
    .form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 0.65rem;
        margin-top: 1rem;
    }
    @media (max-width: 767px) {
        .attendance-hero {
            flex-direction: column;
            align-items: flex-start;
        }
        .session-type-grid {
            grid-template-columns: 1fr;
        }
        .form-actions {
            flex-direction: column-reverse;
        }
        .form-actions .btn {
            width: 100%;
        }
    }
</style>

<div class="attendance-form-page">
    <div class="attendance-form-shell">
        <div class="attendance-hero">
            <div>
                <h1><i class="fas fa-calendar-check mr-2"></i><?= $edit_id ? 'Edit Attendance Session' : 'Create Attendance Session' ?></h1>
                <p>Define the audience scope, schedule pattern, and attendance session details.</p>
            </div>
            <a href="attendance_list.php" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left"></i> Back to Sessions
            </a>
        </div>

        <div class="attendance-main-card">
            <div class="attendance-main-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger mb-3">
                        <i class="fas fa-exclamation-circle mr-1"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="post" autocomplete="off">
                    <div class="form-section">
                        <h6>Session Details</h6>
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label for="church_id">Church <span class="text-danger">*</span></label>
                                <select class="form-control" name="church_id" id="church_id" required>
                                    <option value="">-- Select Church --</option>
                                    <?php if ($churches && $churches->num_rows > 0):
                                        $churches->data_seek(0);
                                        while($ch = $churches->fetch_assoc()): ?>
                                        <option value="<?= $ch['id'] ?>" <?= ($church_id == $ch['id'] ? 'selected' : '') ?>><?= htmlspecialchars($ch['name']) ?></option>
                                    <?php endwhile; endif; ?>
                                </select>
                            </div>
                            <div class="col-md-6 form-group">
                                <label for="title">Session Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="title" name="title" value="<?= htmlspecialchars($title) ?>" placeholder="e.g. Sunday Service Attendance" required>
                            </div>
                        </div>
                    </div>

                    <?php if ($scope_columns_available): ?>
                    <div class="form-section">
                        <h6>Audience Scope</h6>
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label for="attendance_scope">Attendance Scope <span class="text-danger">*</span></label>
                                <select class="form-control" name="attendance_scope" id="attendance_scope" required>
                                    <option value="church" <?= $attendance_scope === 'church' ? 'selected' : '' ?>>Church-wide</option>
                                    <option value="bible_class" <?= $attendance_scope === 'bible_class' ? 'selected' : '' ?>>Bible Class Meeting</option>
                                    <option value="organization" <?= $attendance_scope === 'organization' ? 'selected' : '' ?>>Organization Meeting</option>
                                </select>
                                <div class="scope-hint">Scope controls who appears on the mark-attendance page.</div>
                            </div>
                            <div class="col-md-8">
                                <div class="row">
                                    <div class="col-md-6 form-group" id="scope_class_group" style="display: <?= $attendance_scope === 'bible_class' ? 'block' : 'none' ?>;">
                                        <label for="scope_class_id">Bible Class <span class="text-danger">*</span></label>
                                        <select class="form-control" name="scope_class_id" id="scope_class_id">
                                            <option value="">-- Select Bible Class --</option>
                                            <?php if ($bible_classes && $bible_classes->num_rows > 0):
                                                $bible_classes->data_seek(0);
                                                while($bc = $bible_classes->fetch_assoc()):
                                            ?>
                                                <option value="<?= (int) $bc['id'] ?>" data-church-id="<?= (int) $bc['church_id'] ?>" <?= (int)$scope_class_id === (int)$bc['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($bc['name']) ?><?= !empty($bc['code']) ? ' (' . htmlspecialchars($bc['code']) . ')' : '' ?>
                                                </option>
                                            <?php endwhile; endif; ?>
                                        </select>
                                        <small class="text-muted">Only classes in the selected church are available.</small>
                                    </div>
                                    <div class="col-md-6 form-group" id="scope_org_group" style="display: <?= $attendance_scope === 'organization' ? 'block' : 'none' ?>;">
                                        <label for="scope_org_id">Organization <span class="text-danger">*</span></label>
                                        <select class="form-control" name="scope_org_id" id="scope_org_id" <?= !$organizations_table_available ? 'disabled' : '' ?>>
                                            <option value="">-- Select Organization --</option>
                                            <?php if ($organizations_table_available && $organizations && $organizations->num_rows > 0):
                                                $organizations->data_seek(0);
                                                while($org = $organizations->fetch_assoc()):
                                            ?>
                                                <option value="<?= (int) $org['id'] ?>" data-church-id="<?= (int) ($org['church_id'] ?? 0) ?>" <?= (int)$scope_org_id === (int)$org['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($org['name']) ?>
                                                </option>
                                            <?php endwhile; endif; ?>
                                        </select>
                                        <?php if (!$organizations_table_available): ?>
                                            <small class="text-danger">Organizations table is unavailable in this environment.</small>
                                        <?php else: ?>
                                            <small class="text-muted">Only organizations in the selected church are available.</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="form-section">
                        <h6>Schedule</h6>
                        <div class="form-group">
                            <label>Session Type <span class="text-danger">*</span></label>
                            <div class="session-type-grid">
                                <div class="session-type-option">
                                    <input type="radio" name="is_recurring" id="one_time" value="0" <?= !$is_recurring ? 'checked' : '' ?>>
                                    <label for="one_time"><i class="far fa-calendar-alt"></i> One-time Session</label>
                                </div>
                                <div class="session-type-option">
                                    <input type="radio" name="is_recurring" id="recurring" value="1" <?= $is_recurring ? 'checked' : '' ?>>
                                    <label for="recurring"><i class="fas fa-sync-alt"></i> Recurring Session</label>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 form-group" id="service_date_group" style="display: <?= !$is_recurring ? 'block' : 'none' ?>;">
                                <label for="service_date">Service Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="service_date" name="service_date" value="<?= htmlspecialchars($service_date) ?>" <?= !$is_recurring ? 'required' : '' ?>>
                            </div>
                        </div>

                        <div id="recurrence_fields" style="display: <?= $is_recurring ? 'block' : 'none' ?>;">
                            <div class="row">
                                <div class="col-md-4 form-group">
                                    <label for="recurrence_type">Recurrence Type <span class="text-danger">*</span></label>
                                    <select class="form-control" name="recurrence_type" id="recurrence_type">
                                        <option value="">Select...</option>
                                        <option value="weekly" <?= $recurrence_type === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                                        <option value="monthly" <?= $recurrence_type === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                    </select>
                                </div>
                                <div class="col-md-4 form-group">
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
                                <div class="col-md-4 form-group">
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
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="attendance_list.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> <?= $edit_id ? 'Update Session' : 'Create Session' ?>
                        </button>
                    </div>
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

    function filterBibleClassesByChurch() {
        var churchSelect = document.getElementById('church_id');
        var classSelect = document.getElementById('scope_class_id');
        if (!churchSelect || !classSelect) return;

        var selectedChurchId = churchSelect.value;
        var currentValue = classSelect.value;
        var hasVisibleSelected = false;

        for (var i = 0; i < classSelect.options.length; i++) {
            var opt = classSelect.options[i];
            if (!opt.value) {
                opt.hidden = false;
                continue;
            }
            var optionChurchId = opt.getAttribute('data-church-id');
            var visible = !selectedChurchId || optionChurchId === selectedChurchId;
            opt.hidden = !visible;
            if (visible && opt.value === currentValue) {
                hasVisibleSelected = true;
            }
        }

        if (!hasVisibleSelected) {
            classSelect.value = '';
        }
    }

    function filterOrganizationsByChurch() {
        var churchSelect = document.getElementById('church_id');
        var orgSelect = document.getElementById('scope_org_id');
        if (!churchSelect || !orgSelect) return;

        var selectedChurchId = churchSelect.value;
        var currentValue = orgSelect.value;
        var hasVisibleSelected = false;

        for (var i = 0; i < orgSelect.options.length; i++) {
            var opt = orgSelect.options[i];
            if (!opt.value) {
                opt.hidden = false;
                continue;
            }
            var optionChurchId = opt.getAttribute('data-church-id');
            var visible = !selectedChurchId || optionChurchId === selectedChurchId;
            opt.hidden = !visible;
            if (visible && opt.value === currentValue) {
                hasVisibleSelected = true;
            }
        }

        if (!hasVisibleSelected) {
            orgSelect.value = '';
        }
    }

    function showScopeFields() {
        var scope = document.getElementById('attendance_scope');
        var classGroup = document.getElementById('scope_class_group');
        var orgGroup = document.getElementById('scope_org_group');
        var classSelect = document.getElementById('scope_class_id');
        var orgSelect = document.getElementById('scope_org_id');
        if (!scope) return;

        var showClass = scope.value === 'bible_class';
        var showOrg = scope.value === 'organization';

        if (classGroup && classSelect) {
            classGroup.style.display = showClass ? 'block' : 'none';
            classSelect.required = showClass;
            if (!showClass) {
                classSelect.value = '';
            }
        }
        if (orgGroup && orgSelect) {
            orgGroup.style.display = showOrg ? 'block' : 'none';
            orgSelect.required = showOrg;
            if (!showOrg) {
                orgSelect.value = '';
            }
        }
        filterBibleClassesByChurch();
        filterOrganizationsByChurch();
    }

    document.getElementById('one_time').addEventListener('change', showRecurrenceFields);
    document.getElementById('recurring').addEventListener('change', showRecurrenceFields);
    document.getElementById('recurrence_type').addEventListener('change', showRecurrenceDay);
    document.getElementById('church_id').addEventListener('change', function() {
        filterBibleClassesByChurch();
        filterOrganizationsByChurch();
    });
    <?php if ($scope_columns_available): ?>
    document.getElementById('attendance_scope').addEventListener('change', showScopeFields);
    <?php endif; ?>
    window.onload = function() {
        showRecurrenceFields();
        showRecurrenceDay();
        filterBibleClassesByChurch();
        filterOrganizationsByChurch();
        showScopeFields();
    };
</script>
            </div>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
$page_title = $edit_id ? 'Edit Attendance Session' : 'Create Attendance Session';
include '../includes/layout.php';
?>
