<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/csrf.php';
require_once __DIR__.'/../services/AttendanceScheduleService.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Canonical permission check for Attendance Form
require_once __DIR__.'/../helpers/permissions_v2.php';
if (!has_permission('edit_attendance')) {
    http_response_code(403);
    include __DIR__ . '/errors/403.php';
    exit;
}


$attendance_role_ids = array_map('intval', $_SESSION['role_ids'] ?? [$_SESSION['role_id'] ?? 0]);
if (in_array(6, $attendance_role_ids, true)
    && !array_intersect([1, 2, 4], $attendance_role_ids)) {
    header('Location: my_organization_attendance.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    exit('Your form expired. Refresh and try again.');
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

function attendance_reporting_categories_available($conn) {
    $sql = "SELECT COUNT(*) AS cnt
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'attendance_sessions'
              AND COLUMN_NAME = 'attendance_report_category_id'";
    $res = $conn->query($sql);
    $row = $res ? $res->fetch_assoc() : null;
    return $row && intval($row['cnt']) === 1;
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
$reporting_categories_available = attendance_reporting_categories_available($conn);
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
$attendance_report_category_id = null;
$attendance_audience = 'members';
$role_of_serving_id = null;
$schedule_mode = 'one_time';
$schedule_type = 'weekly';
$schedule_start_date = '';
$schedule_end_date = '';
$schedule_interval = 1;
$schedule_weekday = '';
$schedule_day_of_month = '';
$edit_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Load churches for dropdown
$churches = $conn->query("SELECT id, name FROM churches ORDER BY name ASC");
// Load all bible classes for optional class-scoped sessions
$bible_classes = $conn->query("SELECT id, church_id, name, code FROM bible_classes ORDER BY name ASC");
$organizations = $organizations_table_available
    ? $conn->query("SELECT id, church_id, name FROM organizations ORDER BY name ASC")
    : false;
$roles_of_serving = $conn->query("SELECT id, name FROM roles_of_serving ORDER BY name ASC");
$attendance_categories = [];
if ($reporting_categories_available) {
    $category_result = $conn->query(
        "SELECT category.id, category.code, category.name, parent.name AS parent_name
           FROM attendance_report_categories category
           LEFT JOIN attendance_report_categories parent ON parent.id = category.parent_id
          WHERE category.is_active = 1
          ORDER BY COALESCE(parent.sort_order, category.sort_order),
                   category.parent_id IS NOT NULL, category.sort_order"
    );
    $attendance_categories = $category_result ? $category_result->fetch_all(MYSQLI_ASSOC) : [];
}

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
        if ($reporting_categories_available) {
            $attendance_report_category_id = isset($row['attendance_report_category_id'])
                ? (int) $row['attendance_report_category_id']
                : null;
        }
        $attendance_audience = $row['attendance_audience'] ?? 'members';
        $role_of_serving_id = !empty($row['role_of_serving_id']) ? (int) $row['role_of_serving_id'] : null;
    } else {
        header('Location: attendance_list.php?notfound=1');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $service_date = normalize_date_input($_POST['service_date'] ?? '');
    $schedule_mode = trim((string) ($_POST['schedule_mode'] ?? 'one_time'));
    $is_recurring = $schedule_mode === 'recurring' ? 1 : 0;
    $recurrence_type = trim($_POST['schedule_type'] ?? '');
    $recurrence_day = $_POST['schedule_weekday'] ?? ($_POST['schedule_day_of_month'] ?? '');
    $schedule_type = trim((string) ($_POST['schedule_type'] ?? 'weekly'));
    $schedule_start_date = normalize_date_input($_POST['schedule_start_date'] ?? '') ?? '';
    $schedule_end_date = normalize_date_input($_POST['schedule_end_date'] ?? '') ?? '';
    $schedule_interval = max(1, intval($_POST['schedule_interval'] ?? 1));
    $schedule_weekday = $_POST['schedule_weekday'] ?? '';
    $schedule_day_of_month = $_POST['schedule_day_of_month'] ?? '';
    $church_id = intval($_POST['church_id'] ?? 0);
    $attendance_audience = trim((string) ($_POST['attendance_audience'] ?? 'members'));
    $role_of_serving_id = isset($_POST['role_of_serving_id']) && $_POST['role_of_serving_id'] !== ''
        ? (int) $_POST['role_of_serving_id'] : null;
    if (!in_array($attendance_audience, ['members', 'sunday_school', 'role_of_serving'], true)) {
        $attendance_audience = 'members';
    }
    if ($attendance_audience !== 'role_of_serving') {
        $role_of_serving_id = null;
    }
    $attendance_report_category_id = isset($_POST['attendance_report_category_id'])
        && $_POST['attendance_report_category_id'] !== ''
        ? (int) $_POST['attendance_report_category_id']
        : null;
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
    $classification_source = 'manual';
    if ($reporting_categories_available && in_array($attendance_scope, ['bible_class', 'organization'], true)) {
        $category_code = $attendance_scope === 'bible_class' ? 'bible_class' : 'organization_meeting';
        $category_stmt = $conn->prepare('SELECT id FROM attendance_report_categories WHERE code = ? AND is_active = 1 LIMIT 1');
        $category_stmt->bind_param('s', $category_code);
        $category_stmt->execute();
        $attendance_report_category_id = (int) ($category_stmt->get_result()->fetch_assoc()['id'] ?? 0) ?: null;
        $category_stmt->close();
        $classification_source = 'scope';
    }
    $edit_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if (!$title || !$church_id) {
        $error = 'All fields are required.';
    } elseif (!in_array($schedule_mode, ['one_time', 'multi_day', 'recurring'], true)) {
        $error = 'Please select a valid session schedule.';
    } elseif ($schedule_mode === 'one_time' && !$service_date) {
        $error = 'Please select the service date.';
    } elseif ($schedule_mode !== 'one_time' && (!$schedule_start_date || !$schedule_end_date)) {
        $error = 'Please select the schedule start and end dates.';
    } elseif ($schedule_mode === 'recurring' && !in_array($schedule_type, ['daily', 'weekly', 'monthly'], true)) {
        $error = 'Please select a valid recurrence type.';
    } elseif ($schedule_mode === 'recurring' && $schedule_type === 'weekly'
        && ($schedule_weekday === '' || (int) $schedule_weekday < 0 || (int) $schedule_weekday > 6)) {
        $error = 'Please select a valid recurrence weekday.';
    } elseif ($schedule_mode === 'recurring' && $schedule_type === 'monthly'
        && ((int) $schedule_day_of_month < 1 || (int) $schedule_day_of_month > 31)) {
        $error = 'Please select a valid day of month.';
    } elseif ($scope_columns_available && $attendance_scope === 'bible_class' && (!$scope_id || $scope_id <= 0)) {
        $error = 'Please select a Bible class for class-scoped attendance sessions.';
    } elseif ($scope_columns_available && $attendance_scope === 'organization' && (!$scope_id || $scope_id <= 0)) {
        $error = 'Please select an organization for organization-scoped attendance sessions.';
    } elseif ($scope_columns_available && $attendance_scope === 'organization' && !$organizations_table_available) {
        $error = 'Organization scope is unavailable on this database.';
    } elseif ($reporting_categories_available && (!$attendance_report_category_id || $attendance_report_category_id <= 0)) {
        $error = 'Please select an attendance reporting type.';
    } else {
        if ($reporting_categories_available) {
            $category_stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM attendance_report_categories WHERE id = ? AND is_active = 1');
            $category_stmt->bind_param('i', $attendance_report_category_id);
            $category_stmt->execute();
            $valid_category = (int) ($category_stmt->get_result()->fetch_assoc()['cnt'] ?? 0) === 1;
            $category_stmt->close();
            if (!$valid_category) {
                $error = 'Please select a valid active attendance reporting type.';
            }
        }
    }

    if ($error === '') {
        if ($edit_id && $schedule_mode !== 'one_time') {
            $error = 'Edit this occurrence as a one-time session. Create a new schedule to change a series.';
        } elseif ($edit_id) {
            $stmt = $conn->prepare(
                "UPDATE attendance_sessions
                 SET title=?, service_date=?, church_id=?, attendance_scope=?,
                     attendance_audience=?, scope_id=?, role_of_serving_id=?,
                     attendance_report_category_id=?, classification_source=?
                 WHERE id=?"
            );
            $stmt->bind_param(
                'ssissiiisi',
                $title, $service_date, $church_id, $attendance_scope,
                $attendance_audience, $scope_id, $role_of_serving_id,
                $attendance_report_category_id, $classification_source, $edit_id
            );
            $stmt->execute();
            $stmt->close();

            header('Location: attendance_list.php?updated=1');
            exit;
        } elseif ($schedule_mode === 'one_time') {
            $stmt = $conn->prepare(
                "INSERT INTO attendance_sessions
                    (title, service_date, is_recurring, church_id, attendance_scope,
                     attendance_audience, scope_id, role_of_serving_id,
                     attendance_report_category_id, classification_source, created_by_user_id)
                 VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $actor_user_id = (int) ($_SESSION['user_id'] ?? 0) ?: null;
            $stmt->bind_param(
                'ssissiiisi',
                $title, $service_date, $church_id, $attendance_scope,
                $attendance_audience, $scope_id, $role_of_serving_id,
                $attendance_report_category_id, $classification_source, $actor_user_id
            );
            if ($stmt->execute()) {
                $stmt->close();

                header('Location: attendance_list.php?added=1');
                exit;
            } else {
                $error = 'Database error. Please try again.';
            }
        } else {
            try {
                $scheduleService = new AttendanceScheduleService($conn);
                $result = $scheduleService->create([
                    'church_id' => $church_id,
                    'title' => $title,
                    'attendance_scope' => $attendance_scope,
                    'scope_id' => $scope_id,
                    'attendance_report_category_id' => $attendance_report_category_id,
                    'audience_type' => $attendance_audience,
                    'role_of_serving_id' => $role_of_serving_id,
                    'schedule_type' => $schedule_mode === 'multi_day' ? 'multi_day' : $schedule_type,
                    'start_date' => $schedule_start_date,
                    'end_date' => $schedule_end_date,
                    'interval_value' => $schedule_interval,
                    'weekday' => $schedule_weekday,
                    'day_of_month' => $schedule_day_of_month,
                ], (int) ($_SESSION['user_id'] ?? 0) ?: null);
                header('Location: attendance_list.php?generated=' . (int) $result['created_sessions']);
                exit;
            } catch (Throwable $scheduleError) {
                $error = $scheduleError->getMessage();
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
                    <?= csrf_input() ?>
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
                        <h6>Attendance Audience</h6>
                        <div class="row">
                            <div class="col-md-6 form-group mb-md-0">
                                <label for="attendance_audience">Register <span class="text-danger">*</span></label>
                                <select class="form-control" name="attendance_audience" id="attendance_audience" required>
                                    <option value="members" <?= $attendance_audience === 'members' ? 'selected' : '' ?>>Church Members</option>
                                    <option value="sunday_school" <?= $attendance_audience === 'sunday_school' ? 'selected' : '' ?>>Sunday School Children</option>
                                    <option value="role_of_serving" <?= $attendance_audience === 'role_of_serving' ? 'selected' : '' ?>>Role-of-Serving Holders</option>
                                </select>
                                <small class="text-muted">This determines which register appears when attendance is marked.</small>
                            </div>
                            <div class="col-md-6 form-group mb-0" id="role_of_serving_group" style="display: <?= $attendance_audience === 'role_of_serving' ? 'block' : 'none' ?>;">
                                <label for="role_of_serving_id">Role of Serving</label>
                                <select class="form-control" name="role_of_serving_id" id="role_of_serving_id">
                                    <option value="">All active role holders</option>
                                    <?php if ($roles_of_serving && $roles_of_serving->num_rows > 0):
                                        $roles_of_serving->data_seek(0);
                                        while ($servingRole = $roles_of_serving->fetch_assoc()): ?>
                                        <option value="<?= (int) $servingRole['id'] ?>" <?= (int) $role_of_serving_id === (int) $servingRole['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($servingRole['name']) ?>
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <small class="text-muted">Leave blank for a Leaders Meeting containing every assigned role holder.</small>
                            </div>
                        </div>
                    </div>

                    <?php if ($reporting_categories_available): ?>
                    <div class="form-section">
                        <h6>Reporting Classification</h6>
                        <div class="form-group mb-0">
                            <label for="attendance_report_category_id">Attendance Type <span class="text-danger">*</span></label>
                            <select class="form-control" name="attendance_report_category_id" id="attendance_report_category_id" required>
                                <option value="">-- Select Attendance Type --</option>
                                <?php foreach ($attendance_categories as $category): ?>
                                    <option
                                        value="<?= (int) $category['id'] ?>"
                                        data-code="<?= htmlspecialchars($category['code'], ENT_QUOTES, 'UTF-8') ?>"
                                        <?= (int) $attendance_report_category_id === (int) $category['id'] ? 'selected' : '' ?>
                                    ><?= htmlspecialchars(
                                        $category['parent_name']
                                            ? $category['parent_name'] . ' — ' . $category['name']
                                            : $category['name']
                                    ) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">
                                This controls where the session appears in unified reports. Bible Class and Organization scopes are classified automatically.
                            </small>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="form-section">
                        <h6>Schedule</h6>
                        <div class="form-group">
                            <label>Session Type <span class="text-danger">*</span></label>
                            <div class="session-type-grid">
                                <div class="session-type-option">
                                    <input type="radio" name="schedule_mode" id="one_time" value="one_time" <?= $schedule_mode === 'one_time' ? 'checked' : '' ?>>
                                    <label for="one_time"><i class="far fa-calendar-alt"></i> One-time Session</label>
                                </div>
                                <div class="session-type-option">
                                    <input type="radio" name="schedule_mode" id="multi_day" value="multi_day" <?= $schedule_mode === 'multi_day' ? 'checked' : '' ?>>
                                    <label for="multi_day"><i class="fas fa-calendar-week"></i> Multi-day Event</label>
                                </div>
                                <div class="session-type-option">
                                    <input type="radio" name="schedule_mode" id="recurring" value="recurring" <?= $schedule_mode === 'recurring' ? 'checked' : '' ?>>
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

                        <div id="schedule_range_fields" style="display: <?= $schedule_mode !== 'one_time' ? 'block' : 'none' ?>;">
                            <div class="row">
                                <div class="col-md-4 form-group">
                                    <label for="schedule_start_date">Start Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="schedule_start_date" id="schedule_start_date" value="<?= htmlspecialchars($schedule_start_date) ?>">
                                </div>
                                <div class="col-md-4 form-group">
                                    <label for="schedule_end_date">End Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="schedule_end_date" id="schedule_end_date" value="<?= htmlspecialchars($schedule_end_date) ?>">
                                </div>
                                <div class="col-md-4 form-group" id="recurrence_type_group">
                                    <label for="schedule_type">Recurrence Type <span class="text-danger">*</span></label>
                                    <select class="form-control" name="schedule_type" id="schedule_type">
                                        <option value="daily" <?= $schedule_type === 'daily' ? 'selected' : '' ?>>Daily</option>
                                        <option value="weekly" <?= $schedule_type === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                                        <option value="monthly" <?= $schedule_type === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                    </select>
                                </div>
                                <div class="col-md-4 form-group" id="schedule_weekday_group">
                                    <label for="schedule_weekday">Day of Week <span class="text-danger">*</span></label>
                                    <select class="form-control" id="schedule_weekday" name="schedule_weekday">
                                        <option value="">Select...</option>
                                        <option value="0" <?= (string) $schedule_weekday === '0' ? 'selected' : '' ?>>Sunday</option>
                                        <option value="1" <?= (string) $schedule_weekday === '1' ? 'selected' : '' ?>>Monday</option>
                                        <option value="2" <?= (string) $schedule_weekday === '2' ? 'selected' : '' ?>>Tuesday</option>
                                        <option value="3" <?= (string) $schedule_weekday === '3' ? 'selected' : '' ?>>Wednesday</option>
                                        <option value="4" <?= (string) $schedule_weekday === '4' ? 'selected' : '' ?>>Thursday</option>
                                        <option value="5" <?= (string) $schedule_weekday === '5' ? 'selected' : '' ?>>Friday</option>
                                        <option value="6" <?= (string) $schedule_weekday === '6' ? 'selected' : '' ?>>Saturday</option>
                                    </select>
                                </div>
                                <div class="col-md-4 form-group" id="schedule_monthday_group">
                                    <label for="schedule_day_of_month">Day of Month <span class="text-danger">*</span></label>
                                    <input type="number" min="1" max="31" class="form-control" id="schedule_day_of_month" name="schedule_day_of_month" value="<?= htmlspecialchars((string) $schedule_day_of_month) ?>">
                                    <small class="text-muted">For shorter months, the final day is used.</small>
                                </div>
                                <div class="col-md-4 form-group" id="schedule_interval_group">
                                    <label for="schedule_interval">Repeat Every</label>
                                    <input type="number" min="1" max="52" class="form-control" id="schedule_interval" name="schedule_interval" value="<?= (int) $schedule_interval ?>">
                                    <small class="text-muted">1 means every day, week, or month.</small>
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
        var mode = document.querySelector('input[name="schedule_mode"]:checked').value;
        var scheduled = mode !== 'one_time';
        var recurring = mode === 'recurring';
        var serviceDateGroup = document.getElementById('service_date_group');
        var serviceDateInput = document.getElementById('service_date');
        document.getElementById('schedule_range_fields').style.display = scheduled ? 'block' : 'none';
        document.getElementById('recurrence_type_group').style.display = recurring ? 'block' : 'none';
        document.getElementById('schedule_interval_group').style.display = recurring ? 'block' : 'none';
        serviceDateGroup.style.display = scheduled ? 'none' : 'block';
        if (scheduled) {
            serviceDateInput.removeAttribute('required');
            document.getElementById('schedule_start_date').required = true;
            document.getElementById('schedule_end_date').required = true;
        } else {
            serviceDateInput.setAttribute('required', 'required');
            document.getElementById('schedule_start_date').required = false;
            document.getElementById('schedule_end_date').required = false;
        }
        showRecurrenceDay();
    }
    function showRecurrenceDay() {
        var recurring = document.getElementById('recurring').checked;
        var type = document.getElementById('schedule_type').value;
        var weeklyGroup = document.getElementById('schedule_weekday_group');
        var monthlyGroup = document.getElementById('schedule_monthday_group');
        weeklyGroup.style.display = recurring && type === 'weekly' ? 'block' : 'none';
        monthlyGroup.style.display = recurring && type === 'monthly' ? 'block' : 'none';
        document.getElementById('schedule_weekday').required = recurring && type === 'weekly';
        document.getElementById('schedule_day_of_month').required = recurring && type === 'monthly';
    }

    function showAudienceFields() {
        var audience = document.getElementById('attendance_audience').value;
        document.getElementById('role_of_serving_group').style.display = audience === 'role_of_serving' ? 'block' : 'none';
        if (audience !== 'role_of_serving') {
            document.getElementById('role_of_serving_id').value = '';
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
        var reportingCategory = document.getElementById('attendance_report_category_id');
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
        if (reportingCategory && (showClass || showOrg)) {
            var requiredCode = showClass ? 'bible_class' : 'organization_meeting';
            for (var i = 0; i < reportingCategory.options.length; i++) {
                if (reportingCategory.options[i].getAttribute('data-code') === requiredCode) {
                    reportingCategory.value = reportingCategory.options[i].value;
                    break;
                }
            }
        }
        filterBibleClassesByChurch();
        filterOrganizationsByChurch();
    }

    document.getElementById('one_time').addEventListener('change', showRecurrenceFields);
    document.getElementById('multi_day').addEventListener('change', showRecurrenceFields);
    document.getElementById('recurring').addEventListener('change', showRecurrenceFields);
    document.getElementById('schedule_type').addEventListener('change', showRecurrenceDay);
    document.getElementById('attendance_audience').addEventListener('change', showAudienceFields);
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
        showAudienceFields();
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
include __DIR__ . '/../includes/layout.php';
?>
