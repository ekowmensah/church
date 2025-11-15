<?php
// sms_logs.php - Professional SMS Log Viewer
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

if (!$is_super_admin && !has_permission('view_sms_logs')) {
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
$can_resend = $is_super_admin || has_permission('resend_sms');
$can_view = true; // Already validated above

// Filters
$phone = $_GET['phone'] ?? '';
$sender = $_GET['sender'] ?? '';
$type = $_GET['type'] ?? '';
$status = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$where = [];
$params = [];
$typestr = '';
if ($phone) { $where[] = 'phone LIKE ?'; $params[] = "%$phone%"; $typestr.='s'; }
if ($sender) { $where[] = 'sender LIKE ?'; $params[] = "%$sender%"; $typestr.='s'; }
if ($type) { $where[] = 'type = ?'; $params[] = $type; $typestr.='s'; }
if ($status) {
    if ($status === 'sent') {
        $where[] = "(status LIKE '%sent%' OR status LIKE '%success%')";
    } elseif ($status === 'failed') {
        $where[] = "status LIKE '%fail%'";
    }
}
if ($date_from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
    $where[] = 'DATE(sent_at) >= ?'; $params[] = $date_from; $typestr.='s';
}
if ($date_to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    $where[] = 'DATE(sent_at) <= ?'; $params[] = $date_to; $typestr.='s';
}
$sql = 'SELECT * FROM sms_logs';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY sent_at DESC';
$stmt = $conn->prepare($sql);
if ($typestr) {
    $stmt->bind_param($typestr, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
$sms_logs = [];
while ($row = $res->fetch_assoc()) $sms_logs[] = $row;
$stmt->close();
?>
<main class="container-fluid py-4 animate__animated animate__fadeIn">
    <div class="row mb-3">
        <div class="col-12 col-lg-10 mx-auto">
            <div class="card shadow-sm">
                <div class="card-header bg-light d-flex align-items-center justify-content-between">
                    <div><i class="fas fa-sms text-primary mr-2"></i>SMS Logs</div>
                    <a href="export_sms_logs.php?<?=http_build_query($_GET)?>" class="btn btn-outline-success btn-sm"><i class="fas fa-file-csv mr-1"></i>Export CSV</a>
                </div>
                <div class="card-body">
                    <form method="get" class="form-row mb-3">
                        <div class="col-md-2 mb-2"><input type="text" class="form-control form-control-sm" name="phone" placeholder="Recipient" value="<?=htmlspecialchars($phone)?>"></div>
                        <div class="col-md-2 mb-2"><input type="text" class="form-control form-control-sm" name="sender" placeholder="Sender" value="<?=htmlspecialchars($sender)?>"></div>
                        <div class="col-md-2 mb-2"><input type="date" class="form-control form-control-sm" name="date_from" value="<?=htmlspecialchars($date_from)?>"></div>
                        <div class="col-md-2 mb-2"><input type="date" class="form-control form-control-sm" name="date_to" value="<?=htmlspecialchars($date_to)?>"></div>
                        <div class="col-md-2 mb-2">
                            <select class="form-control form-control-sm" name="type">
                                <option value="">All Types</option>
                                <option value="payment" <?=$type==='payment'?'selected':''?>>Payment</option>
                                <option value="bulk" <?=$type==='bulk'?'selected':''?>>Bulk</option>
                                <option value="general" <?=$type==='general'?'selected':''?>>General</option>
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <select class="form-control form-control-sm" name="status">
                                <option value="">All Status</option>
                                <option value="sent" <?=$status==='sent'?'selected':''?>>Sent</option>
                                <option value="failed" <?=$status==='failed'?'selected':''?>>Failed</option>
                            </select>
                        </div>
                        <div class="col-md-2 mb-2"><button type="submit" class="btn btn-primary btn-sm btn-block">Filter</button></div>
                    </form>
                    <div class="table-responsive">
                        <table id="smsLogsTable" class="table table-bordered table-striped table-sm">
                            <thead class="thead-light">
                                <tr>
                                    <th>Date/Time</th>
                                    <th>Recipient</th>
                                    <th>Message</th>
                                    <th>Sender</th>
                                    <th>Payment ID</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($sms_logs as $row): ?>
                                <tr>
                                    <td><?=htmlspecialchars($row['sent_at'])?></td>
                                    <td><?=htmlspecialchars($row['phone'])?></td>
                                    <td style="max-width:320px;overflow:auto;word-break:break-word;">
                                        <?=htmlspecialchars($row['message'])?>
                                    </td>
                                    <td><?=htmlspecialchars($row['sender'] ?? '')?></td>
                                    <td><?=htmlspecialchars($row['payment_id'] ?? '')?></td>
                                    <td><?=htmlspecialchars($row['type'] ?? '')?></td>
                                    <td>
                                        <?php
                                        $status = strtolower($row['status'] ?? '');
                                        if (strpos($status, 'fail') !== false) {
                                            echo '<span class="badge badge-danger">Failed</span>';
                                        } elseif (strpos($status, 'sent') !== false || strpos($status, 'success') !== false) {
                                            echo '<span class="badge badge-success">Sent</span>';
                                        } else {
                                            echo '<span class="badge badge-secondary">'.htmlspecialchars($row['status']).'</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($can_resend && strpos($status, 'fail') !== false): ?>
                                            <button class="btn btn-sm btn-warning resend-sms-btn" data-log-id="<?=intval($row['id'])?>"><i class="fas fa-redo"></i> Resend</button>
                                        <?php endif; ?>
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
    $('#smsLogsTable').DataTable({
        order: [[0, 'desc']],
        pageLength: 25,
        lengthMenu: [10,25,50,100],
        stateSave: true
    });
    $(document).on('click', '.resend-sms-btn', function(e) {
        e.preventDefault();
        var btn = $(this);
        var logId = btn.data('log-id');
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
        $.post('ajax_resend_sms.php', {id: logId}, function(resp) {
            btn.prop('disabled', false).html('<i class="fas fa-redo"></i> Resend');
            alert(resp && resp.success ? 'SMS resent!' : (resp && resp.error ? resp.error : 'Failed to resend SMS.'));
        }, 'json');
    });
});
</script>
<?php
$page_content = ob_get_clean();
include __DIR__.'/../includes/layout.php';
