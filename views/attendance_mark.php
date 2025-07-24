<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

// Auth and session validation

if (!is_logged_in()) {
        header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Canonical permission check for Attendance Mark
require_once __DIR__.'/../helpers/permissions.php';
if (!has_permission('mark_attendance')) {
    http_response_code(403);
    include '../views/errors/403.php';
    exit;
}

$session_id = intval($_GET['id'] ?? 0);
if (!$session_id) {
        header('Location: attendance_list.php');
    exit;
}

// Fetch session
$stmt = $conn->prepare("SELECT * FROM attendance_sessions WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $session_id);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
if (!$session) {
        header('Location: attendance_list.php');
    exit;
}

// Fetch filter options
$bible_classes = $conn->query("SELECT id, name FROM bible_classes ORDER BY name ASC");
$organizations = $conn->query("SELECT id, name FROM organizations ORDER BY name ASC");

// Get filter values
$filter_class = $_GET['class_id'] ?? '';
$filter_org = $_GET['organization_id'] ?? '';
$search = trim($_GET['search'] ?? '');

// Build member query with filters
$sql = "SELECT m.id, m.first_name, m.last_name, m.middle_name, m.crn FROM members m ";
if ($filter_org) {
    $sql .= "LEFT JOIN member_organizations mo ON mo.member_id = m.id ";
}
$sql .= "WHERE m.church_id = ? ";
$params = [$session['church_id']];
$types = 'i';
if ($filter_class) {
    $sql .= "AND m.class_id = ? ";
    $params[] = $filter_class;
    $types .= 'i';
}
if ($filter_org) {
    $sql .= "AND mo.organization_id = ? ";
    $params[] = $filter_org;
    $types .= 'i';
}
if ($search !== '') {
    $sql .= " AND (m.first_name LIKE ? OR m.last_name LIKE ? OR m.middle_name LIKE ? OR m.crn LIKE ?) ";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}
$sql .= "ORDER BY m.last_name, m.first_name";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $members_result = $stmt->get_result();
} else {
    // fallback: still filter by church
    $members_result = $conn->query("SELECT id, first_name, last_name, middle_name, crn FROM members WHERE church_id = " . intval($session['church_id']) . " ORDER BY last_name, first_name");
}
$members = $members_result ? $members_result->fetch_all(MYSQLI_ASSOC) : [];

// Fetch previous attendance for this session and filtered members
$prev_attendance = [];
$member_ids = array_column($members, 'id');
if (count($member_ids) > 0) {
    $placeholders = implode(',', array_fill(0, count($member_ids), '?'));
    $sql_att = "SELECT member_id, status FROM attendance_records WHERE session_id = ? AND member_id IN ($placeholders)";
    $stmt = $conn->prepare($sql_att);
    $types_att = str_repeat('i', count($member_ids) + 1); // session_id + member_ids
    $bind_params = array_merge([$session_id], $member_ids);
    // Bind params dynamically using call_user_func_array
    $refs = [];
    foreach ($bind_params as $k => $v) {
        $refs[$k] = &$bind_params[$k];
    }
    array_unshift($refs, $types_att);
    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $prev_attendance[$row['member_id']] = $row['status'];
    }
}


// Handle POST (mark attendance)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $marked = $_POST['attendance'] ?? [];
    foreach ($members as $m) {
        $member_id = $m['id'];
        $status = isset($marked[$member_id]) && $marked[$member_id] === 'Present' ? 'Present' : 'Absent';
        $stmt = $conn->prepare("REPLACE INTO attendance_records (session_id, member_id, status, marked_by, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param('iisi', $session_id, $member_id, $status, $_SESSION['user_id']);
        $stmt->execute();
    }
    header('Location: attendance_list.php?marked=1');
    exit;
}

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Mark Attendance: <?= htmlspecialchars($session['title']) ?> (<?= date('Y-m-d') ?>)</h1>
    <a href="attendance_list.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to List</a>
</div>
<div class="card shadow mb-4 border-0 rounded-lg">
    <div class="card-header py-3 bg-white border-bottom-primary">
        <h6 class="m-0 font-weight-bold text-primary">Filter Members</h6>
    </div>
    <div class="card-body">
        <form method="get" class="form-row align-items-end">
            <input type="hidden" name="id" value="<?= htmlspecialchars($session_id) ?>">
            <div class="form-group col-md-4 mb-3">
                <label for="class_id" class="font-weight-bold">Bible Class</label>
                <select class="form-control custom-select" name="class_id" id="class_id">
                    <option value="">All</option>
                    <?php if ($bible_classes && $bible_classes->num_rows > 0): while($cl = $bible_classes->fetch_assoc()): ?>
                        <option value="<?= $cl['id'] ?>" <?= ($filter_class == $cl['id'] ? 'selected' : '') ?>><?= htmlspecialchars($cl['name']) ?></option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div class="form-group col-md-4 mb-3">
                <label for="organization_id" class="font-weight-bold">Organization</label>
                <select class="form-control custom-select" name="organization_id" id="organization_id">
                    <option value="">All</option>
                    <?php if ($organizations && $organizations->num_rows > 0): while($org = $organizations->fetch_assoc()): ?>
                        <option value="<?= $org['id'] ?>" <?= ($filter_org == $org['id'] ? 'selected' : '') ?>><?= htmlspecialchars($org['name']) ?></option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div class="form-group col-md-4 mb-3">
                <label for="search" class="font-weight-bold">Search Name/CRN</label>
                <input type="text" class="form-control" name="search" id="search" value="<?= htmlspecialchars($search ?? '') ?>" placeholder="Enter name or CRN...">
            </div>
            <div class="form-group col-md-12 mb-3 d-flex flex-row align-items-center">
                <button type="submit" class="btn btn-primary mr-2">Apply</button>
                <a href="attendance_mark.php?id=<?= htmlspecialchars($session_id) ?>" class="btn btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>
</div>
<form method="post">
    <div class="card shadow mb-4">
        <div class="card-header py-3 bg-primary text-white">
            <h6 class="m-0 font-weight-bold">Members</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" width="100%">
                    <thead class="thead-light">
                        <tr>
                            <th>CRN</th>
                            <th>Member Name</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="members-tbody">
                    <?php foreach($members as $member): ?>
                        <?php
                            $checked = '';
                            if (isset($prev_attendance[$member['id']])) {
                                if (strtolower($prev_attendance[$member['id']]) === 'present') {
                                    $checked = 'checked';
                                }
                            }
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($member['crn'] ?? '') ?></td>
                            <td><?= htmlspecialchars($member['last_name'] . ', ' . $member['first_name'] . ' ' . $member['middle_name']) ?></td>
                            <td>
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="attend_<?= $member['id'] ?>" name="attendance[<?= $member['id'] ?>]" value="Present" <?= $checked ?>>
                                    <label class="custom-control-label" for="attend_<?= $member['id'] ?>">Present</label>
                                </div>
                                <!-- <small class="text-muted">Absent if off</small> -->
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <script>
                    // Realtime AJAX filter/search
                    (function() {
                        var form = document.querySelector('form.form-row');
                        var tbody = document.getElementById('members-tbody');
                        var timeout = null;
                        function doAjax() {
                            var params = new URLSearchParams(new FormData(form));
                            fetch('attendance_mark_ajax.php?' + params.toString())
                                .then(r => r.text())
                                .then(html => { tbody.innerHTML = html; });
                        }
                        function debounceAjax() {
                            clearTimeout(timeout);
                            timeout = setTimeout(doAjax, 250);
                        }
                        form.querySelectorAll('input,select').forEach(function(el) {
                            el.addEventListener('input', debounceAjax);
                            el.addEventListener('change', debounceAjax);
                        });
                    })();
                    </script>
                </table>
            </div>
            <button type="submit" class="btn btn-success">Save Attendance</button>
        </div>
    </div>
</form>
<?php $page_content = ob_get_clean(); include '../includes/layout.php'; ?>