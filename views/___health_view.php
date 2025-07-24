<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
// Allow Super Admin (role_id==1 or role name 'Super Admin') to always access
$super_admin = false;
$role_id = $_SESSION['role_id'] ?? 0;
if ($role_id == 1) {
    $super_admin = true;
} else {
    $stmt = $conn->prepare("SELECT name FROM roles WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $role_id);
    $stmt->execute();
    $stmt->bind_result($role_name);
    $stmt->fetch();
    $stmt->close();
    if ($role_name === 'Super Admin') {
        $super_admin = true;
    }
}
if (!$super_admin && !has_permission('health_statistics')) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$id) {
    die('Invalid health record ID.');
}
// Fetch the current health record
$stmt = $conn->prepare("SELECT hr.*, m.first_name, m.last_name, m.id as member_id, u.name AS recorded_by FROM health_records hr LEFT JOIN members m ON hr.member_id = m.id LEFT JOIN users u ON hr.recorded_by = u.id WHERE hr.id = ? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();
if (!($row = $result->fetch_assoc())) {
    die('Health record not found.');
}
$member_id = $row['member_id'];
$member_name = trim($row['first_name'].' '.$row['last_name']);
$vitals = json_decode($row['vitals'], true) ?: [];
$notes = $row['notes'];
$recorded_at = $row['recorded_at'];
$recorded_by = $row['recorded_by'];
// Fetch all previous records for this member (most recent first)
$stmt2 = $conn->prepare("SELECT * FROM health_records WHERE member_id = ? ORDER BY recorded_at DESC");
$stmt2->bind_param('i', $member_id);
$stmt2->execute();
$all_result = $stmt2->get_result();
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0 text-gray-800"><i class="fas fa-heartbeat mr-2"></i>Health Records for <?= htmlspecialchars($member_name) ?></h1>
    <a href="health_list.php" class="btn btn-secondary"><i class="fas fa-list"></i> Back to List</a>
</div>
<div class="card shadow mb-4">
    <div class="card-header bg-primary text-white">
        <h6 class="m-0 font-weight-bold">All Health Records</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="thead-light">
                    <tr>
                        <th>Date/Time</th>
                        <th>Weight (Kg)</th>
                        <th>Temperature (°C)</th>
                        <th>BP (MMHG)</th>
                        <th>BP Status</th>
                        <th>Sugar (mmol/L)</th>
                        <th>Sugar Status</th>
                        <th>Hep B</th>
                        <th>Malaria</th>
                        <th>Notes</th>
                        <th>Recorded By</th>
                    </tr>
                </thead>
                <tbody>
                <?php while($rec = $all_result->fetch_assoc()): $v = json_decode($rec['vitals'], true) ?: []; ?>
                    <tr>
                        <td><?= htmlspecialchars($rec['recorded_at']) ?></td>
                        <td><?= htmlspecialchars($v['weight'] ?? '') ?></td>
                        <td><?= htmlspecialchars($v['temperature'] ?? '') ?></td>
                        <td><?= htmlspecialchars($v['bp'] ?? '') ?></td>
                        <td><?= htmlspecialchars($v['bp_status'] ?? '') ?></td>
                        <td><?= htmlspecialchars($v['sugar'] ?? '') ?></td>
                        <td><?= htmlspecialchars($v['sugar_status'] ?? '') ?></td>
                        <td><?= htmlspecialchars($v['hepatitis_b'] ?? '') ?></td>
                        <td><?= htmlspecialchars($v['malaria'] ?? '') ?></td>
                        <td><?= htmlspecialchars($rec['notes']) ?></td>
                        <td><?php
                            $uid = $rec['recorded_by'];
                            $u = $conn->query("SELECT name FROM users WHERE id=".intval($uid));
                            echo $u && $ur = $u->fetch_assoc() ? htmlspecialchars($ur['name']) : '';
                        ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require_once __DIR__.'/../includes/layout.php';
