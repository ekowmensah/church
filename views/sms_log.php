<?php
ob_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$is_super_admin = (int) ($_SESSION['role_id'] ?? 0) === 1;
if (!$is_super_admin && !has_permission('view_sms_logs')) {
    http_response_code(403);
    include __DIR__ . '/errors/403.php';
    exit;
}

$requiredAuditColumns = [
    'sundayschool_id', 'church_id', 'payment_id', 'sender',
    'provider_message_id', 'attempt_number', 'retry_of_sms_log_id',
    'attempted_by_user_id', 'error_message',
];
$availableAuditColumns = [];
$schemaResult = $conn->query('SHOW COLUMNS FROM sms_logs');
while ($schemaRow = $schemaResult->fetch_assoc()) {
    $availableAuditColumns[$schemaRow['Field']] = true;
}
foreach ($requiredAuditColumns as $requiredAuditColumn) {
    if (!isset($availableAuditColumns[$requiredAuditColumn])) {
        header('Location: sms_logs.php?upgrade=required');
        exit;
    }
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(400);
    exit('Invalid SMS log ID.');
}

$sql = 'SELECT log.*, payment.description AS payment_description,
               payment.amount AS payment_amount,
               payment.payment_date,
               user_account.name AS attempted_by_name
          FROM sms_logs log
          LEFT JOIN payments payment ON payment.id = log.payment_id
          LEFT JOIN users user_account ON user_account.id = log.attempted_by_user_id
         WHERE log.id = ?';
$params = [$id];
$types = 'i';
if (!$is_super_admin) {
    $churchId = (int) ($_SESSION['church_id'] ?? 0);
    if ($churchId <= 0 && !empty($_SESSION['user_id'])) {
        $scope = $conn->prepare('SELECT church_id FROM users WHERE id = ? LIMIT 1');
        $userId = (int) $_SESSION['user_id'];
        $scope->bind_param('i', $userId);
        $scope->execute();
        $churchId = (int) (($scope->get_result()->fetch_assoc()['church_id'] ?? 0));
        $scope->close();
    }
    $sql .= ' AND log.church_id = ?';
    $params[] = $churchId;
    $types .= 'i';
}
$sql .= ' LIMIT 1';
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$log = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$log) {
    http_response_code(404);
    exit('SMS log entry not found in your church scope.');
}

$status = strtolower((string) ($log['status'] ?? ''));
$is_failed = strpos($status, 'fail') !== false || strpos($status, 'error') !== false;
$resend_allowed = $is_super_admin || has_permission('resend_sms');
$response = json_decode((string) ($log['response'] ?? ''), true);
$safe_response = is_array($response) ? $response : ['raw' => (string) ($log['response'] ?? '')];
// Only operational provider evidence is shown. Never render provider secrets.
unset($safe_response['api_key'], $safe_response['api_secret'], $safe_response['debug']);
?>
<main class="container py-4 animate__animated animate__fadeIn">
    <div class="row justify-content-center">
        <div class="col-12 col-xl-9">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white d-flex align-items-center justify-content-between">
                    <div>
                        <h5 class="mb-0"><i class="fas fa-sms text-primary mr-2"></i>SMS delivery attempt #<?=intval($log['id'])?></h5>
                        <small class="text-muted">Provider evidence and retry lineage</small>
                    </div>
                    <a href="sms_logs.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left mr-1"></i>Logs</a>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <dl class="row mb-0">
                                <dt class="col-5 text-muted">Attempted</dt><dd class="col-7"><?=htmlspecialchars((string) $log['sent_at'])?></dd>
                                <dt class="col-5 text-muted">Recipient</dt><dd class="col-7"><?=htmlspecialchars((string) $log['phone'])?></dd>
                                <dt class="col-5 text-muted">Sender</dt><dd class="col-7"><?=htmlspecialchars((string) ($log['sender'] ?? ''))?></dd>
                                <dt class="col-5 text-muted">Provider</dt><dd class="col-7"><?=htmlspecialchars((string) ($log['provider'] ?? ''))?></dd>
                                <dt class="col-5 text-muted">Provider ID</dt><dd class="col-7"><?=htmlspecialchars((string) ($log['provider_message_id'] ?? ''))?></dd>
                            </dl>
                        </div>
                        <div class="col-md-6">
                            <dl class="row mb-0">
                                <dt class="col-5 text-muted">Status</dt><dd class="col-7"><span class="badge badge-<?=$is_failed ? 'danger' : 'success'?>"><?=htmlspecialchars((string) ($log['status'] ?? 'unknown'))?></span></dd>
                                <dt class="col-5 text-muted">Attempt</dt><dd class="col-7">#<?=intval($log['attempt_number'] ?? 1)?></dd>
                                <dt class="col-5 text-muted">Retry of</dt><dd class="col-7"><?=!empty($log['retry_of_sms_log_id']) ? '<a href="sms_log.php?id=' . intval($log['retry_of_sms_log_id']) . '">#' . intval($log['retry_of_sms_log_id']) . '</a>' : 'Original attempt'?></dd>
                                <dt class="col-5 text-muted">Payment</dt><dd class="col-7"><?=!empty($log['payment_id']) ? '#' . intval($log['payment_id']) : 'Not payment-linked'?></dd>
                                <dt class="col-5 text-muted">Attempted by</dt><dd class="col-7"><?=htmlspecialchars((string) ($log['attempted_by_name'] ?? 'System'))?></dd>
                            </dl>
                        </div>
                    </div>
                    <?php if (!empty($log['payment_id'])): ?>
                        <div class="alert alert-light border mt-3 mb-3">
                            <strong>Payment context:</strong>
                            GHS <?=number_format((float) ($log['payment_amount'] ?? 0), 2)?>
                            on <?=htmlspecialchars((string) ($log['payment_date'] ?? ''))?>
                            — <?=htmlspecialchars((string) ($log['payment_description'] ?? ''))?>
                        </div>
                    <?php endif; ?>
                    <h6 class="text-muted mt-3">Delivered message</h6>
                    <pre class="bg-light border rounded p-3" style="white-space:pre-wrap;word-break:break-word"><?=htmlspecialchars((string) $log['message'])?></pre>
                    <?php if (!empty($log['error_message'])): ?>
                        <div class="alert alert-danger"><strong>Failure:</strong> <?=htmlspecialchars((string) $log['error_message'])?></div>
                    <?php endif; ?>
                    <details class="mt-3">
                        <summary class="text-muted">Provider response</summary>
                        <pre class="bg-dark text-light rounded p-3 mt-2" style="white-space:pre-wrap;word-break:break-word"><?=htmlspecialchars((string) json_encode($safe_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))?></pre>
                    </details>
                    <?php if ($resend_allowed && $is_failed): ?>
                        <button class="btn btn-warning resend-sms-btn mt-3" data-log-id="<?=intval($log['id'])?>"><i class="fas fa-redo mr-1"></i>Retry failed SMS</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>
<script>
$(function() {
    var csrfToken = <?=json_encode(csrf_token())?>;
    $('.resend-sms-btn').on('click', function() {
        var btn = $(this), original = btn.html();
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Retrying');
        $.ajax({url:'ajax_resend_sms.php', method:'POST', dataType:'json', data:{id:btn.data('log-id'), csrf_token:csrfToken}})
            .done(function(resp) {
                alert(resp.audit_status === 'logged' ? (resp.message || 'SMS resent successfully.') : 'SMS was sent, but its audit row could not be saved.');
                window.location.href = resp.sms_log_id ? 'sms_log.php?id=' + resp.sms_log_id : 'sms_log.php?id=' + btn.data('log-id');
            })
            .fail(function(xhr) { var resp=xhr.responseJSON||{}; alert(resp.error||'SMS retry failed.'); })
            .always(function() { btn.prop('disabled', false).html(original); });
    });
});
</script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
