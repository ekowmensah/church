<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Canonical permission check for Attendance Detail View
require_once __DIR__.'/../helpers/permissions_v2.php';
if (!has_permission('view_attendance_list')) {
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

// Get status filter
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Fetch attendance records with optional status filter
$sql = "SELECT m.last_name, m.first_name, m.middle_name, ar.status, ar.created_at, u.name AS marked_by
        FROM attendance_records ar
        JOIN members m ON ar.member_id = m.id
        LEFT JOIN users u ON ar.marked_by = u.id
        WHERE ar.session_id = ?";
$params = [$session_id];
$types = 'i';
if ($status_filter === 'present' || $status_filter === 'absent') {
    $sql .= " AND ar.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}
$sql .= " ORDER BY m.last_name, m.first_name";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$records = $stmt->get_result();

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Attendance: <?= htmlspecialchars($session['title']) ?> (<?= htmlspecialchars($session['service_date']) ?>)</h1>
    <a href="attendance_list.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to List</a>
</div>
<form method="get" class="form-inline mb-3">
    <input type="hidden" name="id" value="<?= htmlspecialchars($session_id) ?>">
    <label for="status" class="mr-2 font-weight-bold">Status:</label>
    <select class="form-control mr-2" name="status" id="status" onchange="this.form.submit()">
        <option value="" <?= $status_filter === '' ? 'selected' : '' ?>>All</option>
        <option value="present" <?= $status_filter === 'present' ? 'selected' : '' ?>>Present</option>
        <option value="absent" <?= $status_filter === 'absent' ? 'selected' : '' ?>>Absent</option>
    </select>
    <noscript><button type="submit" class="btn btn-primary btn-sm">Apply</button></noscript>
</form>
<div class="card shadow mb-4">
    <div class="card-header py-3 bg-primary text-white">
        <h6 class="m-0 font-weight-bold">Attendance Records</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover" width="100%">
                <thead class="thead-light">
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Marked By</th>
                        <th>Marked At</th>
                    </tr>
                </thead>
                <tbody>
                <?php while($row = $records->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['last_name'] . ', ' . $row['first_name'] . ' ' . $row['middle_name']) ?></td>
                        <td><?= htmlspecialchars($row['status']) ?></td>
                        <td><?= htmlspecialchars($row['marked_by']) ?></td>
                        <td><?= htmlspecialchars($row['created_at']) ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php $page_content = ob_get_clean(); include '../includes/layout.php'; ?>
