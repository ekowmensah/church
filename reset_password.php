<?php
require_once __DIR__.'/config/config.php';
$token = $_GET['token'] ?? '';
$error = $success = '';
if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    $error = 'Invalid or missing reset token.';
} else {
    $stmt = $conn->prepare('SELECT * FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW() LIMIT 1');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $reset = $stmt->get_result()->fetch_assoc();
    if (!$reset) {
        $error = 'Reset link is invalid or has expired.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm'] ?? '';
        if (!$password || strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt2 = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt2->bind_param('si', $hash, $reset['user_id']);
            $stmt2->execute();
            $conn->query("UPDATE password_resets SET used = 1 WHERE id = ".$reset['id']);
            $success = 'Your password has been reset. You may now <a href="login.php">login</a>.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password</title>
  <!-- AdminLTE 3 -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
  <style>
    body {
      background: #f4f6f9;
    }
    .login-logo img {
      width: 80px;
      height: 80px;
      margin-bottom: 10px;
    }
    .card-primary.card-outline {
      border-top: 3px solid #007bff;
    }
  </style>
</head>
<body class="hold-transition login-page">
<div class="login-box">
  <div class="login-logo">
    <a href="#"><b><img src="/assets/img/logo.png" alt="Logo" onerror="this.style.display='none';"><br>Reset Password</b></a>
  </div>
  <div class="card card-primary card-outline">
    <div class="card-body login-card-body">
      <p class="login-box-msg">Enter your new password below</p>
      <?php if ($error): ?>
        <div class="alert alert-danger text-center py-2"><?= htmlspecialchars($error) ?></div>
      <?php elseif ($success): ?>
        <div class="alert alert-success text-center py-2"><?= $success ?></div>
      <?php endif; ?>
      <?php if (!$success && (!$error || ($error && $reset))): ?>
      <form method="post" autocomplete="off">
        <div class="input-group mb-3">
          <input type="password" class="form-control" name="password" placeholder="New Password" required minlength="6">
          <div class="input-group-append">
            <div class="input-group-text">
              <span class="fas fa-lock"></span>
            </div>
          </div>
        </div>
        <div class="input-group mb-3">
          <input type="password" class="form-control" name="confirm" placeholder="Confirm Password" required minlength="6">
          <div class="input-group-append">
            <div class="input-group-text">
              <span class="fas fa-lock"></span>
            </div>
          </div>
        </div>
        <div class="row">
          <div class="col-12">
            <button type="submit" class="btn btn-primary btn-block">Reset Password</button>
          </div>
        </div>
      </form>
      <?php endif; ?>
      <p class="mt-4 mb-0 text-center">
        <a href="login.php"><i class="fas fa-arrow-left mr-1"></i>Back to Login</a>
      </p>
    </div>
  </div>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
</body>
</html>
