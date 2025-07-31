<?php
//if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';
// Canonical permission check for SMS Settings
$is_super_admin = (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
if (!is_logged_in() || (!$is_super_admin && !has_permission('edit_sms_settings'))) {
    http_response_code(403);
    exit('Forbidden: You do not have permission to access this page.');
}

// Handle form submission
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_api_key = trim($_POST['arkesel_api_key'] ?? '');
    $new_sender = trim($_POST['sms_sender'] ?? '');
    if ($new_api_key && $new_sender) {
        $settings = [
            'arkesel_api_key' => $new_api_key,
            'sms_sender' => $new_sender
        ];
        file_put_contents(__DIR__.'/../config/sms_settings.json', json_encode($settings, JSON_PRETTY_PRINT));
        $msg = '<div class="alert alert-success">Arkesel SMS settings updated successfully!</div>';
    } else {
        $msg = '<div class="alert alert-danger">Please enter both API key and sender ID.</div>';
    }
}
// Load current settings
$settings_file = __DIR__.'/../config/sms_settings.json';
if (file_exists($settings_file)) {
    $settings = json_decode(file_get_contents($settings_file), true);
    $current_api_key = $settings['arkesel_api_key'] ?? (defined('ARKESEL_API_KEY') ? ARKESEL_API_KEY : '');
    $current_sender = $settings['sms_sender'] ?? (defined('SMS_SENDER') ? SMS_SENDER : 'FMC-KM');
} else {
    $current_api_key = defined('ARKESEL_API_KEY') ? ARKESEL_API_KEY : '';
    $current_sender = defined('SMS_SENDER') ? SMS_SENDER : 'FMC-KM';
}
?>
<?php ob_start(); ?>
<div class="container-fluid py-4">
    <h2 class="mb-4">SMS Provider Settings</h2>
    <?= $msg ?>
    <form method="post" class="card card-body shadow-sm" style="max-width:500px;">
        <div class="form-group">
            <label for="arkesel_api_key">Arkesel API Key</label>
            <input type="text" class="form-control" name="arkesel_api_key" id="arkesel_api_key" value="<?= htmlspecialchars($current_api_key) ?>" required>
        </div>
        <div class="form-group">
            <label for="sms_sender">Sender ID</label>
            <input type="text" class="form-control" name="sms_sender" id="sms_sender" value="<?= htmlspecialchars($current_sender) ?>" maxlength="11" required>
            <small class="form-text text-muted">Sender ID must be registered in your Arkesel dashboard (max 11 characters).</small>
        </div>
        <button type="submit" class="btn btn-primary">Save Settings</button>
    </form>
    <div class="mt-4">
        <a href="sms_bulk.php" class="btn btn-secondary">&larr; Back to Bulk SMS</a>
    </div>
</div>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
?>
