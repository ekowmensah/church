<?php
// audit_list.php - Professional Audit Log Viewer
ob_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_audit_log')) {
    http_response_code(403);
    if (file_exists(__DIR__.'/errors/403.php')) {
        include __DIR__.'/errors/403.php';
    } else if (file_exists(dirname(__DIR__).'/views/errors/403.php')) {
        include dirname(__DIR__).'/views/errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    }
    exit;
}

// Set permission flags for UI elements
$can_add = $is_super_admin || has_permission('create_audit');
$can_edit = $is_super_admin || has_permission('edit_audit');
$can_delete = $is_super_admin || has_permission('delete_audit');
$can_view = true; // Already validated above

// Filters
$user = $_GET['user'] ?? '';
$action = $_GET['action'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$where = [];
$params = [];
$typestr = '';
if ($user) { $where[] = 'username LIKE ?'; $params[] = "%$user%"; $typestr.='s'; }
if ($action) { $where[] = 'action LIKE ?'; $params[] = "%$action%"; $typestr.='s'; }
if ($date_from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
    $where[] = 'DATE(created_at) >= ?'; $params[] = $date_from; $typestr.='s';
}
if ($date_to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    $where[] = 'DATE(created_at) <= ?'; $params[] = $date_to; $typestr.='s';
}
$sql = 'SELECT * FROM audit_log';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY created_at DESC';
$stmt = $conn->prepare($sql);
if ($typestr) {
    $stmt->bind_param($typestr, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
$audit_logs = [];
while ($row = $res->fetch_assoc()) $audit_logs[] = $row;
$stmt->close();
?>
<main class="container-fluid py-4 animate__animated animate__fadeIn">
    <div class="row mb-3">
        <div class="col-12 col-lg-10 mx-auto">
            <div class="card shadow-sm">
                <div class="card-header bg-light d-flex align-items-center justify-content-between">
                    <div><i class="fas fa-clipboard-list text-primary mr-2"></i>Audit Logs</div>
                    <a href="export_audit_logs.php?<?=http_build_query($_GET)?>" class="btn btn-outline-success btn-sm"><i class="fas fa-file-csv mr-1"></i>Export CSV</a>
                </div>
                <div class="card-body">
                    <form method="get" class="form-row mb-3">
                        <div class="col-md-3 mb-2"><input type="text" class="form-control form-control-sm" name="user" placeholder="User" value="<?=htmlspecialchars($user)?>"></div>
                        <div class="col-md-3 mb-2"><input type="text" class="form-control form-control-sm" name="action" placeholder="Action" value="<?=htmlspecialchars($action)?>"></div>
                        <div class="col-md-2 mb-2"><input type="date" class="form-control form-control-sm" name="date_from" value="<?=htmlspecialchars($date_from)?>"></div>
                        <div class="col-md-2 mb-2"><input type="date" class="form-control form-control-sm" name="date_to" value="<?=htmlspecialchars($date_to)?>"></div>
                        <div class="col-md-2 mb-2"><button type="submit" class="btn btn-primary btn-sm btn-block">Filter</button></div>
                    </form>
                    <div class="table-responsive">
                        <table id="auditLogsTable" class="table table-bordered table-striped table-sm">
                            <thead class="thead-light">
                                <tr>
                                    <th>Date/Time</th>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>IP</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($audit_logs as $row): ?>
                                <tr>
                                    <td><?=htmlspecialchars($row['created_at'])?></td>
                                    <td><?=htmlspecialchars($row['username'] ?? $row['user'] ?? '')?></td>
                                    <td><?=htmlspecialchars($row['action'])?></td>
                                    <td><?=htmlspecialchars($row['ip_address'] ?? '')?></td>
                                    <td style="max-width:320px;overflow:auto;word-break:break-word;">
                                        <?=htmlspecialchars($row['details'])?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css"/>
<script>
$(function() {
    $('#auditLogsTable').DataTable({
        order: [[0, 'desc']],
        pageLength: 25,
        lengthMenu: [10,25,50,100],
        stateSave: true
    });
});
</script>
<?php
$page_content = ob_get_clean();
include __DIR__.'/../includes/layout.php';
