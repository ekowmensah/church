<?php
//if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
// Canonical permission check for SMS Settings
$is_super_admin = (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
if (!is_logged_in() || (!$is_super_admin && !has_permission('edit_sms_settings'))) {
    http_response_code(403);
    exit('Forbidden: You do not have permission to access this page.');
}

// Handle form submission
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $default_provider = trim($_POST['default_provider'] ?? 'arkesel');
    
    // Arkesel settings
    $arkesel_api_key = trim($_POST['arkesel_api_key'] ?? '');
    $arkesel_sender = trim($_POST['arkesel_sender'] ?? '');
    
    // Hubtel settings
    $hubtel_api_key = trim($_POST['hubtel_api_key'] ?? '');
    $hubtel_api_secret = trim($_POST['hubtel_api_secret'] ?? '');
    $hubtel_sender = trim($_POST['hubtel_sender'] ?? '');
    
    $settings = [
        'default_provider' => $default_provider,
        'arkesel' => [
            'api_key' => $arkesel_api_key,
            'sender' => $arkesel_sender,
            'url' => 'https://sms.arkesel.com/api/v2/sms/send'
        ],
        'hubtel' => [
            'api_key' => $hubtel_api_key,
            'api_secret' => $hubtel_api_secret,
            'sender' => $hubtel_sender,
            'url' => 'https://sms.hubtel.com/v1/messages/send'
        ]
    ];
    
    file_put_contents(__DIR__.'/../config/sms_settings.json', json_encode($settings, JSON_PRETTY_PRINT));
    $msg = '<div class="alert alert-success">SMS settings updated successfully!</div>';
}

// Load current settings
$settings_file = __DIR__.'/../config/sms_settings.json';
if (file_exists($settings_file)) {
    $settings = json_decode(file_get_contents($settings_file), true);
} else {
    $settings = [
        'default_provider' => 'arkesel',
        'arkesel' => [
            'api_key' => defined('ARKESEL_API_KEY') ? ARKESEL_API_KEY : '',
            'sender' => defined('SMS_SENDER') ? SMS_SENDER : 'FMC-KM',
            'url' => 'https://sms.arkesel.com/api/v2/sms/send'
        ],
        'hubtel' => [
            'api_key' => '',
            'api_secret' => '',
            'sender' => 'FMC-KM',
            'url' => 'https://smsc.hubtel.com/v1/messages/send'
        ]
    ];
}
?>
<?php ob_start(); ?>
<div class="container-fluid py-4">
    <h2 class="mb-4">SMS Provider Settings</h2>
    <?= $msg ?>
    <form method="post" class="card card-body shadow-sm" style="max-width:700px;">
        <!-- Default Provider Selection -->
        <div class="form-group mb-4">
            <label for="default_provider"><strong>Default SMS Provider</strong></label>
            <select class="form-control" name="default_provider" id="default_provider">
                <option value="arkesel" <?= ($settings['default_provider'] ?? 'arkesel') === 'arkesel' ? 'selected' : '' ?>>Arkesel</option>
                <option value="hubtel" <?= ($settings['default_provider'] ?? 'arkesel') === 'hubtel' ? 'selected' : '' ?>>Hubtel</option>
            </select>
        </div>

        <hr>

        <!-- Arkesel Settings -->
        <h5 class="text-primary mb-3">Arkesel Configuration</h5>
        <div class="form-group">
            <label for="arkesel_api_key">Arkesel API Key</label>
            <input type="text" class="form-control" name="arkesel_api_key" id="arkesel_api_key" 
                   value="<?= htmlspecialchars($settings['arkesel']['api_key'] ?? '') ?>">
        </div>
        <div class="form-group mb-4">
            <label for="arkesel_sender">Arkesel Sender ID</label>
            <input type="text" class="form-control" name="arkesel_sender" id="arkesel_sender" 
                   value="<?= htmlspecialchars($settings['arkesel']['sender'] ?? '') ?>" maxlength="11">
            <small class="form-text text-muted">Sender ID must be registered in your Arkesel dashboard (max 11 characters).</small>
        </div>

        <hr>

        <!-- Hubtel Settings -->
        <h5 class="text-success mb-3">Hubtel Configuration</h5>
        <div class="form-group">
            <label for="hubtel_api_key">Hubtel API Key</label>
            <input type="text" class="form-control" name="hubtel_api_key" id="hubtel_api_key" 
                   value="<?= htmlspecialchars($settings['hubtel']['api_key'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="hubtel_api_secret">Hubtel API Secret</label>
            <input type="password" class="form-control" name="hubtel_api_secret" id="hubtel_api_secret" 
                   value="<?= htmlspecialchars($settings['hubtel']['api_secret'] ?? '') ?>">
        </div>
        <div class="form-group mb-4">
            <label for="hubtel_sender">Hubtel Sender ID</label>
            <input type="text" class="form-control" name="hubtel_sender" id="hubtel_sender" 
                   value="<?= htmlspecialchars($settings['hubtel']['sender'] ?? '') ?>" maxlength="11">
            <small class="form-text text-muted">Sender ID must be registered in your Hubtel account (max 11 characters).</small>
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
