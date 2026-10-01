<?php

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/AiAssistantSettingsService.php';

if (!is_logged_in() || !is_super_admin()) {
    http_response_code(403);
    exit('Only the Super Admin can configure the AI assistant.');
}

$service = new AiAssistantSettingsService($conn);
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $message = '<div class="alert alert-danger">Your session token expired. Refresh and try again.</div>';
    } else {
        try {
            $service->update($_POST, (int) $_SESSION['user_id']);
            $message = '<div class="alert alert-success">Assistant settings saved.</div>';
        } catch (Throwable $exception) {
            $message = '<div class="alert alert-danger">' . htmlspecialchars($exception->getMessage()) . '</div>';
        }
    }
}
$settings = $service->get();
$hasApiKey = $service->hasApiKey();
$page_title = 'AI Assistant Settings';
ob_start();
?>
<div class="container-fluid py-4">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h3 mb-1"><i class="fas fa-robot text-primary mr-2"></i>AI Assistant Settings</h1>
            <p class="text-muted mb-0">Choose how the global conversational assistant answers authorized users.</p>
        </div>
    </div>
    <?= $message ?>
    <div class="row">
        <div class="col-xl-8">
            <form method="post" class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <?= csrf_input() ?>
                    <div class="form-group">
                        <label class="font-weight-bold">Processing mode</label>
                        <div class="row">
                            <?php foreach ([
                                'local' => ['Local', 'Uses only permission-scoped MyFreeman queries. No external API.'],
                                'agent' => ['AI Agent', 'Uses verified local results as context and returns the AI response.'],
                                'both' => ['Both', 'Shows the AI response plus its verified local system result.'],
                            ] as $value => $option): ?>
                                <div class="col-md-4 mb-3">
                                    <label class="border rounded p-3 d-block h-100 bg-light" style="cursor:pointer">
                                        <input type="radio" name="assistant_mode" value="<?= $value ?>"
                                            <?= $settings['assistant_mode'] === $value ? 'checked' : '' ?>>
                                        <strong class="ml-1"><?= htmlspecialchars($option[0]) ?></strong>
                                        <small class="d-block text-muted mt-2"><?= htmlspecialchars($option[1]) ?></small>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="agent_model">OpenAI model</label>
                            <input class="form-control" id="agent_model" name="agent_model"
                                   value="<?= htmlspecialchars((string) $settings['agent_model']) ?>" maxlength="80">
                            <small class="form-text text-muted">Model identifiers can be changed without code deployment.</small>
                        </div>
                        <div class="form-group col-md-3">
                            <label for="max_output_tokens">Max output tokens</label>
                            <input type="number" class="form-control" id="max_output_tokens" name="max_output_tokens"
                                   min="100" max="4000" value="<?= (int) $settings['max_output_tokens'] ?>">
                        </div>
                        <div class="form-group col-md-3">
                            <label for="requests_per_user_per_minute">Messages/minute</label>
                            <input type="number" class="form-control" id="requests_per_user_per_minute"
                                   name="requests_per_user_per_minute" min="1" max="60"
                                   value="<?= (int) $settings['requests_per_user_per_minute'] ?>">
                        </div>
                    </div>
                    <div class="custom-control custom-switch mb-4">
                        <input type="checkbox" class="custom-control-input" id="is_enabled" name="is_enabled" value="1"
                               <?= !empty($settings['is_enabled']) ? 'checked' : '' ?>>
                        <label class="custom-control-label" for="is_enabled">Enable the assistant</label>
                    </div>
                    <button class="btn btn-primary"><i class="fas fa-save mr-1"></i>Save Settings</button>
                </div>
            </form>
        </div>
        <div class="col-xl-4">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <h5>Agent connection</h5>
                    <p class="mb-2"><span class="badge badge-<?= $hasApiKey ? 'success' : 'warning' ?>">
                        <?= $hasApiKey ? 'OPENAI_API_KEY detected' : 'OPENAI_API_KEY not configured' ?>
                    </span></p>
                    <p class="small text-muted mb-0">The key is read only from the server environment and is never stored in the database or exposed in the browser. If Agent mode fails, the verified Local answer is returned.</p>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
