<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../helpers/global_audit_log.php';

if (!isset($_SESSION['user_id']) || (int) $_SESSION['user_id'] < 1) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$stmt = $conn->prepare(
    'SELECT id, name, email, password_hash, must_change_password
       FROM users WHERE id = ? AND status = "active" LIMIT 1'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$account = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$account) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}
if ((int) $account['must_change_password'] !== 1) {
    $_SESSION['must_change_password'] = 0;
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Your form expired. Refresh the page and try again.');
        }
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmation = (string) ($_POST['confirm_password'] ?? '');
        if (!password_verify($currentPassword, (string) $account['password_hash'])) {
            throw new RuntimeException('The temporary password is incorrect.');
        }
        if (strlen($newPassword) < 8) {
            throw new RuntimeException('Your new password must contain at least eight characters.');
        }
        if ($newPassword !== $confirmation) {
            throw new RuntimeException('The new passwords do not match.');
        }
        if (hash_equals($currentPassword, $newPassword)) {
            throw new RuntimeException('Choose a new password that differs from the temporary password.');
        }

        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $conn->prepare(
            'UPDATE users
                SET password_hash = ?, must_change_password = 0, password_changed_at = NOW()
              WHERE id = ? AND must_change_password = 1'
        );
        $stmt->bind_param('si', $passwordHash, $userId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('The password was not changed. Please try again.');
        }
        $stmt->close();

        $_SESSION['must_change_password'] = 0;
        unset($_SESSION['_csrf_token']);
        session_regenerate_id(true);
        log_activity(
            'required_password_changed',
            'user',
            $userId,
            json_encode(['source' => 'first_login'], JSON_UNESCAPED_SLASHES)
        );
        $_SESSION['flash_success'] = 'Your password was changed successfully.';
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Change Temporary Password - MyFreeman</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    body{min-height:100vh;margin:0;display:flex;align-items:center;justify-content:center;padding:1rem;background:linear-gradient(135deg,#174f5e,#238096);font-family:Arial,sans-serif}.password-card{width:100%;max-width:470px;background:#fff;border-radius:16px;padding:2rem;box-shadow:0 18px 45px rgba(0,0,0,.24)}.password-icon{width:58px;height:58px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#e8f4f7;color:#174f5e;font-size:1.45rem;margin:0 auto 1rem}.btn-primary{background:#238096;border-color:#238096}.account-email{overflow-wrap:anywhere}
  </style>
</head>
<body>
  <main class="password-card">
    <div class="password-icon"><i class="fas fa-key"></i></div>
    <h2 class="h4 text-center mb-2">Change your temporary password</h2>
    <p class="text-muted text-center mb-4">Before entering MyFreeman, <?= htmlspecialchars((string) $account['name']) ?> must choose a private password for <span class="account-email"><?= htmlspecialchars((string) $account['email']) ?></span>.</p>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <?= csrf_input() ?>
      <div class="form-group"><label for="current_password">Temporary password</label><input class="form-control" type="password" id="current_password" name="current_password" autocomplete="current-password" required></div>
      <div class="form-group"><label for="new_password">New password</label><input class="form-control" type="password" id="new_password" name="new_password" minlength="8" autocomplete="new-password" required><small class="form-text text-muted">Use at least eight characters and do not reuse the temporary password.</small></div>
      <div class="form-group"><label for="confirm_password">Confirm new password</label><input class="form-control" type="password" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password" required></div>
      <button class="btn btn-primary btn-block" type="submit"><i class="fas fa-lock mr-1"></i> Save password and continue</button>
    </form>
    <div class="text-center mt-3"><a class="text-muted" href="<?= htmlspecialchars(BASE_URL) ?>/logout.php">Sign out instead</a></div>
  </main>
</body>
</html>
