<?php
require_once __DIR__.'/config/config.php';
$error = $success = '';
require_once __DIR__.'/includes/sms.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_mode = $_POST['login_mode'] ?? 'member';
    if ($login_mode === 'user') {
        $email = trim($_POST['email'] ?? '');
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $stmt = $conn->prepare('SELECT id, name FROM users WHERE email = ? LIMIT 1');
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($user = $result->fetch_assoc()) {
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiry
                $conn->query("DELETE FROM password_resets WHERE email = '".$conn->real_escape_string($email)."'");
                $stmt2 = $conn->prepare('INSERT INTO password_resets (user_id, email, token, expires_at) VALUES (?, ?, ?, ?)');
                $stmt2->bind_param('isss', $user['id'], $email, $token, $expires);
                $stmt2->execute();
                $reset_link = BASE_URL.'/reset_password.php?token='.$token;
                $msg = "Hello ".$user['name'].",\n\nTo reset your password, click the link below or paste it into your browser:\n".$reset_link."\n\nThis link will expire in 1 hour.";
                @mail($email, 'Password Reset Request', $msg, 'From: no-reply@'.$_SERVER['HTTP_HOST']);
            }
            // Always show success message to avoid email enumeration
            $success = 'If the email is registered, a password reset link has been sent.';
        }
    } else if ($login_mode === 'member') {
        $crn = trim($_POST['crn'] ?? '');
        if (!$crn) {
            $error = 'Please enter your CRN.';
        } else {
            $stmt = $conn->prepare('SELECT id, first_name, last_name, phone FROM members WHERE crn = ? AND status = "active" LIMIT 1');
            $stmt->bind_param('s', $crn);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($member = $result->fetch_assoc()) {
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiry
                $conn->query("DELETE FROM password_resets WHERE user_id = " . intval($member['id']) . " AND email = ''");
                $stmt2 = $conn->prepare('INSERT INTO password_resets (user_id, email, token, expires_at) VALUES (?, ?, ?, ?)');
                $empty = '';
                $stmt2->bind_param('isss', $member['id'], $empty, $token, $expires);
                $stmt2->execute();
                $reset_link = BASE_URL.'/reset_password.php?token='.$token;
                $msg = "Dear ".$member['first_name'].' '.$member['last_name'].", to reset your password, click: ".$reset_link."\nThis link will expire in 1 hour.";
                send_sms($member['phone'], $msg);
            }
            // Always show success message to avoid CRN enumeration
            $success = 'If the CRN is registered, a password reset link has been sent to your phone.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot Password</title>
  <!-- AdminLTE 3 -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
  <style>
    body { background: #f4f6f9; }
    .login-logo img { width: 80px; height: 80px; margin-bottom: 10px; }
    .card-primary.card-outline { border-top: 3px solid #007bff; }
  </style>
</head>
<body class="hold-transition login-page">
<div class="login-box">
  <div class="login-logo">
    <a href="#"><b><img src="/assets/img/logo.png" alt="Logo" onerror="this.style.display='none';"><br>Forgot Password</b></a>
  </div>
  <div class="card card-primary card-outline">
    <div class="card-body login-card-body">
      <p class="login-box-msg">Reset your Password</p>
      <?php if ($error): ?>
        <div class="alert alert-danger text-center py-2"><?= htmlspecialchars($error) ?></div>
      <?php elseif ($success): ?>
        <div class="alert alert-success text-center py-2"><?= $success ?></div>
      <?php endif; ?>
      <?php if (!$success): ?>
      <form method="post" autocomplete="off">
        <div class="mb-3 text-center">
          <div class="btn-group btn-group-toggle" data-toggle="buttons">
            <label class="btn btn-outline-primary<?= (!isset($_POST['login_mode']) || $_POST['login_mode'] === 'user') ? ' active' : '' ?>">
              <input type="radio" name="login_mode" value="user" autocomplete="off"<?= (!isset($_POST['login_mode']) || $_POST['login_mode'] === 'user') ? ' checked' : '' ?>>
              <i class="fas fa-envelope mr-1"></i> User (Email)
            </label>
            <label class="btn btn-outline-primary<?= (isset($_POST['login_mode']) && $_POST['login_mode'] === 'member') ? ' active' : '' ?>">
              <input type="radio" name="login_mode" value="member" autocomplete="off"<?= (isset($_POST['login_mode']) && $_POST['login_mode'] === 'member') ? ' checked' : '' ?>>
              <i class="fas fa-id-card mr-1"></i> Member (CRN)
            </label>
          </div>
        </div>
        <div class="input-group mb-3" id="email-group" style="display:<?= (!isset($_POST['login_mode']) || $_POST['login_mode'] === 'user') ? 'flex' : 'none' ?>;">
          <input type="email" class="form-control" name="email" placeholder="Enter your email" <?= (!isset($_POST['login_mode']) || $_POST['login_mode'] === 'user') ? 'required' : '' ?> value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
          <div class="input-group-append">
            <div class="input-group-text">
              <span class="fas fa-envelope"></span>
            </div>
          </div>
        </div>
        <div class="input-group mb-3" id="crn-group" style="display:<?= (isset($_POST['login_mode']) && $_POST['login_mode'] === 'member') ? 'flex' : 'none' ?>;">
          <input type="text" class="form-control" name="crn" placeholder="Enter your CRN" <?= (isset($_POST['login_mode']) && $_POST['login_mode'] === 'member') ? 'required' : '' ?> value="<?= htmlspecialchars($_POST['crn'] ?? '') ?>">
          <div class="input-group-append">
            <div class="input-group-text">
              <span class="fas fa-id-card"></span>
            </div>
          </div>
        </div>
        <div class="row">
          <div class="col-12">
            <button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>
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
<script>
// Toggle form fields based on mode
$(function() {
  $('input[name="login_mode"]').change(function() {
    if ($(this).val() === 'user') {
      $('#email-group').show();
      $('#crn-group').hide();
      $('#email-group input').prop('required', true);
      $('#crn-group input').prop('required', false);
    } else {
      $('#email-group').hide();
      $('#crn-group').show();
      $('#email-group input').prop('required', false);
      $('#crn-group input').prop('required', true);
    }
  });
});
</script>
</body>
</html>
