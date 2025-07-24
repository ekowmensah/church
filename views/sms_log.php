<?php
// sms_log.php - Detailed SMS Log View (Single Log Entry)
// Professional, clean, mobile-friendly interface
ob_start();
require_once '../includes/admin_auth.php';
require_once __DIR__.'/../helpers/auth.php';
require_once '../config/config.php';
if (!has_permission('view_sms_logs')) {
    http_response_code(403);
    exit('Forbidden: You do not have permission to access this page.');
}
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$id) {
    http_response_code(400);
    exit('Invalid log ID');
}
$stmt = $conn->prepare('SELECT * FROM sms_logs WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$log = $res->fetch_assoc();
$stmt->close();
if (!$log) {
    http_response_code(404);
    exit('SMS log entry not found.');
}
$resend_allowed = function_exists('has_permission') && has_permission('resend_sms');
?>
<main class="container py-4 animate__animated animate__fadeIn">
    <div class="row justify-content-center">
        <div class="col-12 col-md-10 col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-light d-flex align-items-center justify-content-between">
                    <div><i class="fas fa-sms text-primary mr-2"></i>SMS Log Detail</div>
                    <a href="sms_logs.php" class="btn btn-link btn-sm"><i class="fas fa-arrow-left"></i> Back to Logs</a>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4 text-muted small">Date/Time</dt>
                        <dd class="col-sm-8 mb-2"><?=htmlspecialchars($log['sent_at'])?></dd>
                        <dt class="col-sm-4 text-muted small">Recipient</dt>
                        <dd class="col-sm-8 mb-2"><?=htmlspecialchars($log['phone'])?></dd>
                        <dt class="col-sm-4 text-muted small">Sender</dt>
                        <dd class="col-sm-8 mb-2"><?=htmlspecialchars($log['sender'] ?? '')?></dd>
                        <dt class="col-sm-4 text-muted small">Payment ID</dt>
                        <dd class="col-sm-8 mb-2"><?=htmlspecialchars($log['payment_id'] ?? '')?></dd>
                        <dt class="col-sm-4 text-muted small">Type</dt>
                        <dd class="col-sm-8 mb-2"><?=htmlspecialchars($log['type'] ?? '')?></dd>
                        <dt class="col-sm-4 text-muted small">Status</dt>
                        <dd class="col-sm-8 mb-2">
                            <?php
                            $status = strtolower($log['status'] ?? '');
                            if (strpos($status, 'fail') !== false) {
                                echo '<span class="badge badge-danger">Failed</span>';
                            } elseif (strpos($status, 'sent') !== false || strpos($status, 'success') !== false) {
                                echo '<span class="badge badge-success">Sent</span>';
                            } else {
                                echo '<span class="badge badge-secondary">'.htmlspecialchars($log['status']).'</span>';
                            }
                            ?>
                        </dd>
                        <dt class="col-sm-4 text-muted small">Message</dt>
                        <dd class="col-sm-8 mb-2"><pre class="bg-light p-2 rounded small" style="white-space:pre-wrap;word-break:break-word;"><?=htmlspecialchars($log['message'])?></pre></dd>
                        <dt class="col-sm-4 text-muted small">API Response</dt>
                        <dd class="col-sm-8 mb-2"><pre class="bg-light p-2 rounded small" style="white-space:pre-wrap;word-break:break-word;"><?=htmlspecialchars($log['api_response'])?></pre></dd>
                    </dl>
                    <?php if ($resend_allowed && strpos($status, 'fail') !== false): ?>
                        <button class="btn btn-warning btn-sm resend-sms-btn mt-3" data-log-id="<?=intval($log['id'])?>"><i class="fas fa-redo"></i> Resend SMS</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>
<script>
$(function() {
    $(document).on('click', '.resend-sms-btn', function(e) {
        e.preventDefault();
        var btn = $(this);
        var logId = btn.data('log-id');
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
        $.post('ajax_resend_sms.php', {id: logId}, function(resp) {
            btn.prop('disabled', false).html('<i class="fas fa-redo"></i> Resend SMS');
            alert(resp && resp.success ? 'SMS resent!' : (resp && resp.error ? resp.error : 'Failed to resend SMS.'));
        }, 'json');
    });
});
</script>
<?php
$page_content = ob_get_clean();
include __DIR__.'/../includes/layout.php';
