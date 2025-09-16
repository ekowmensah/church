<?php
require_once __DIR__.'/config/config.php';

session_start();

$error = '';
$login_mode = isset($_POST['login_mode']) ? $_POST['login_mode'] : 'member';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    if ($login_mode === 'member') {
        $crn = trim($_POST['crn'] ?? '');
        $stmt = $conn->prepare('SELECT * FROM members WHERE crn = ? AND status = "active" LIMIT 1');
        $stmt->bind_param('s', $crn);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($member = $result->fetch_assoc()) {
            if (isset($member['password_hash']) && password_verify($password, $member['password_hash'])) {
                $_SESSION['member_id'] = $member['id'];
                $_SESSION['crn'] = $member['crn'];
                $_SESSION['member_name'] = $member['first_name'].' '.$member['last_name'];
                $_SESSION['role'] = 'member';
                // Set session flag for welcome modal
                $_SESSION['login_success'] = true;
                $_SESSION['login_fullname'] = $member['first_name'] . ' ' . $member['last_name'];
                header('Location: ' . BASE_URL . '/views/member_dashboard.php');
                exit;
            } else {
                $error = 'Invalid CRN or password.';
            }
        } else {
            $error = 'Invalid CRN or password.';
        }
    } else {
        $email = trim($_POST['email'] ?? '');
        $stmt = $conn->prepare('SELECT id, name, email, password_hash FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($user = $result->fetch_assoc()) {
            if (password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['user_name'] = $user['name']; // For dashboard compatibility
                $_SESSION['email'] = $user['email'];
                // Robust super admin session flag
                $_SESSION['is_super_admin'] = ($user['id'] == 3) ? true : false;
                // Fetch all roles for this user
                $role_stmt = $conn->prepare('SELECT role_id FROM user_roles WHERE user_id = ? ORDER BY role_id ASC');
                $role_stmt->bind_param('i', $user['id']);
                $role_stmt->execute();
                $role_result = $role_stmt->get_result();
                $role_ids = [];
                while ($row = $role_result->fetch_assoc()) {
                    $role_ids[] = (int)$row['role_id'];
                }
                $role_stmt->close();
                if (empty($role_ids)) {
                    require_once __DIR__.'/helpers/global_audit_log.php';
                    log_activity('login_failed', 'user', $user['id'], json_encode(['username'=>$email, 'ip'=>$_SERVER['REMOTE_ADDR']]));
                    $error = 'No roles assigned to this user. Please contact admin.';
                } else {
                    $_SESSION['role_ids'] = $role_ids;
                    // For backward compatibility, set role_id to the first (lowest) role
                    $_SESSION['role_id'] = $role_ids[0];
                    // Set super admin flag if user has role_id 1
                    $_SESSION['is_super_admin'] = in_array(1, $role_ids);
                    
                    // Load user permissions into session
                    $permissions = [];
                    $perm_stmt = $conn->prepare('
                        SELECT DISTINCT p.name 
                        FROM permissions p
                        JOIN role_permissions rp ON p.id = rp.permission_id
                        WHERE rp.role_id IN (' . implode(',', array_fill(0, count($role_ids), '?')) . ')
                    ');
                    $perm_stmt->bind_param(str_repeat('i', count($role_ids)), ...$role_ids);
                    $perm_stmt->execute();
                    $perm_result = $perm_stmt->get_result();
                    while ($perm_row = $perm_result->fetch_assoc()) {
                        $permissions[] = $perm_row['name'];
                    }
                    $perm_stmt->close();
                    $_SESSION['permissions'] = $permissions;
                    
                    require_once __DIR__.'/helpers/global_audit_log.php';
                    log_activity('login_success', 'user', $user['id'], json_encode(['username'=>$email, 'ip'=>$_SERVER['REMOTE_ADDR']]));
                    // Super Admin override: always set role_id to 1 if present
                    if (in_array(1, $role_ids)) {
                        $_SESSION['role_id'] = 1;
                    }
                    header('Location: ' . BASE_URL . '/index.php');
                    exit;
                }
            } else {
                require_once __DIR__.'/helpers/global_audit_log.php';
                log_activity('login_failed', 'user', null, json_encode(['username'=>$email, 'ip'=>$_SERVER['REMOTE_ADDR']]));
                $error = 'Invalid email or password.';
            }
        } else {
            require_once __DIR__.'/helpers/global_audit_log.php';
            log_activity('login_failed', 'user', null, json_encode(['username'=>$email, 'ip'=>$_SERVER['REMOTE_ADDR']]));
            $error = 'Invalid email or password.';
        }
    }
}

// Get church logo dynamically
$logo_path = 'logo.png'; // Default logo
if (file_exists(__DIR__.'/uploads/logo_6866e9048867c.jpg')) {
    $logo_path = 'uploads/logo_6866e9048867c.jpg';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="base-url" content="<?php echo BASE_URL; ?>">
    
    <!-- Favicon and App Icons -->
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="icon" type="image/x-icon" href="<?php echo BASE_URL; ?>/assets/img/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="manifest" href="<?php echo BASE_URL; ?>/assets/img/site.webmanifest">
    
    <!-- Theme and App Metadata -->
    <meta name="theme-color" content="#667eea">
    <meta name="msapplication-TileColor" content="#667eea">
    <meta name="application-name" content="Church CMS">
    <meta name="apple-mobile-web-app-title" content="Church CMS">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    
    <title>Login - Church Management System</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-color: #2563eb;
            --secondary-color: #1d4ed8;
            --accent-color: #3b82f6;
            --success-color: #059669;
            --danger-color: #dc2626;
            --warning-color: #d97706;
            --dark-color: #1e293b;
            --light-color: #f8fafc;
            --border-radius: 12px;
            --shadow-soft: 0 4px 15px rgba(37, 99, 235, 0.1);
            --shadow-medium: 0 10px 25px rgba(37, 99, 235, 0.15);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 15px;
            position: relative;
            overflow: hidden;
        }

        /* Animated background elements */
        body::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.08) 1px, transparent 1px);
            background-size: 40px 40px;
            animation: float 25s ease-in-out infinite;
            z-index: 0;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-15px) rotate(180deg); }
        }

        /* Floating shapes */
        .shape {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.06);
            animation: floatShapes 20s ease-in-out infinite;
        }

        .shape:nth-child(1) {
            width: 60px;
            height: 60px;
            top: 15%;
            left: 10%;
            animation-delay: 0s;
        }

        .shape:nth-child(2) {
            width: 80px;
            height: 80px;
            top: 70%;
            right: 15%;
            animation-delay: 7s;
        }

        .shape:nth-child(3) {
            width: 45px;
            height: 45px;
            bottom: 25%;
            left: 20%;
            animation-delay: 14s;
        }

        @keyframes floatShapes {
            0%, 100% { transform: translateY(0px) scale(1); opacity: 0.6; }
            50% { transform: translateY(-25px) scale(1.1); opacity: 0.3; }
        }

        .login-container {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 420px;
        }

        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-medium);
            border: 1px solid rgba(255, 255, 255, 0.2);
            overflow: hidden;
            transition: var(--transition);
        }

        .login-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(37, 99, 235, 0.2);
        }

        .login-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            padding: 25px 20px;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .login-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 15px 15px;
            animation: headerFloat 12s linear infinite;
        }

        @keyframes headerFloat {
            0% { transform: translate(0, 0); }
            100% { transform: translate(-15px, -15px); }
        }

        .church-logo {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            border: 3px solid rgba(255, 255, 255, 0.3);
            margin: 0 auto 15px;
            display: block;
            object-fit: cover;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
            transition: var(--transition);
            position: relative;
            z-index: 2;
        }

        .church-logo:hover {
            transform: scale(1.05) rotate(3deg);
            border-color: rgba(255, 255, 255, 0.5);
        }

        .church-name {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 5px;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
            position: relative;
            z-index: 2;
        }

        .welcome-text {
            font-size: 0.85rem;
            opacity: 0.9;
            font-weight: 400;
            position: relative;
            z-index: 2;
        }

        .login-body {
            padding: 25px 20px;
        }

        .login-tabs {
            margin-bottom: 20px;
        }

        .nav-pills .nav-link {
            border-radius: 25px;
            padding: 8px 16px;
            font-weight: 600;
            font-size: 0.85rem;
            transition: var(--transition);
            border: 2px solid transparent;
            color: #64748b;
        }

        .nav-pills .nav-link:hover {
            background-color: rgba(37, 99, 235, 0.1);
            color: var(--primary-color);
            transform: translateY(-1px);
        }

        .nav-pills .nav-link.active {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.3);
            transform: translateY(-1px);
        }

        .form-floating {
            margin-bottom: 15px;
            position: relative;
        }

        .form-control {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 14px;
            transition: var(--transition);
            background: #f8fafc;
            height: auto;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
            background: white;
            transform: translateY(-1px);
        }

        .form-floating > label {
            color: #64748b;
            font-weight: 500;
            font-size: 0.85rem;
        }

        .btn-login {
            width: 100%;
            padding: 12px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            border: none;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: var(--transition);
        }

        .btn-login:hover::before {
            left: 100%;
        }

        .btn-member {
            background: linear-gradient(135deg, var(--success-color), #047857);
            color: white;
            box-shadow: 0 4px 15px rgba(5, 150, 105, 0.3);
        }

        .btn-member:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(5, 150, 105, 0.4);
            background: linear-gradient(135deg, #047857, #065f46);
        }

        .btn-admin {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.3);
        }

        .btn-admin:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4);
            background: linear-gradient(135deg, var(--secondary-color), var(--accent-color));
        }

        .alert {
            border-radius: 8px;
            border: none;
            padding: 12px 16px;
            margin-bottom: 20px;
            font-weight: 500;
            font-size: 0.85rem;
            animation: slideInDown 0.5s ease-out;
        }

        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-15px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-danger {
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            color: var(--danger-color);
            border-left: 3px solid var(--danger-color);
        }

        .forgot-password {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }

        .forgot-password a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.85rem;
            transition: var(--transition);
            position: relative;
        }

        .forgot-password a::after {
            content: '';
            position: absolute;
            width: 0;
            height: 2px;
            bottom: -2px;
            left: 50%;
            background: var(--primary-color);
            transition: var(--transition);
            transform: translateX(-50%);
        }

        .forgot-password a:hover::after {
            width: 100%;
        }

        .forgot-password a:hover {
            color: var(--secondary-color);
            transform: translateY(-1px);
        }

        /* Loading animation */
        .btn-loading {
            position: relative;
            color: transparent !important;
        }

        .btn-loading::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            top: 50%;
            left: 50%;
            margin-left: -8px;
            margin-top: -8px;
            border: 2px solid transparent;
            border-top-color: currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            color: white;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Responsive design */
        @media (max-width: 576px) {
            body {
                padding: 10px;
            }
            
            .login-container {
                max-width: 100%;
            }
            
            .login-header {
                padding: 20px 15px;
            }
            
            .login-body {
                padding: 20px 15px;
            }
            
            .church-name {
                font-size: 1.1rem;
            }
            
            .nav-pills .nav-link {
                padding: 6px 12px;
                font-size: 0.8rem;
            }
        }

        @media (max-height: 700px) {
            .login-header {
                padding: 20px 20px;
            }
            
            .login-body {
                padding: 20px 20px;
            }
            
            .church-logo {
                width: 50px;
                height: 50px;
                margin-bottom: 10px;
            }
            
            .church-name {
                font-size: 1.1rem;
                margin-bottom: 3px;
            }
            
            .welcome-text {
                font-size: 0.8rem;
            }
        }

        /* Form icons */
        .input-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            transition: var(--transition);
            z-index: 5;
            cursor: pointer;
        }

        .form-control:focus + .input-icon {
            color: var(--primary-color);
        }

        /* Success animation */
        @keyframes successPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }

        .success-animation {
            animation: successPulse 0.6s ease-in-out;
        }

        /* Ensure no scrolling */
        html, body {
            height: 100%;
            overflow: hidden;
        }
    </style>
</head>
<body>
    <!-- Floating background shapes -->
    <div class="shape"></div>
    <div class="shape"></div>
    <div class="shape"></div>

    <div class="login-container">
        <div class="login-card">
            <!-- Login Header -->
            <div class="login-header">
                <img src="<?php echo BASE_URL . '/' . $logo_path; ?>" alt="Church Logo" class="church-logo">
                <h1 class="church-name">FREEMAN METHODIST CHURCH</h1>
                <p class="welcome-text">KWESIMINTSIM</p>
            </div>

            <!-- Login Body -->
            <div class="login-body">
                <!-- Error Alert -->
                <?php if ($error): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <!-- Login Mode Select -->
                <div class="form-floating mb-3">
                    <select class="form-select" id="loginModeSelect" name="login_mode_select">
                        <option value="member" <?php echo ($login_mode === 'member' ? 'selected' : ''); ?>>
                            👥 Member Login
                        </option>
                        <option value="user" <?php echo ($login_mode !== 'member' ? 'selected' : ''); ?>>
                            🛡️ Admin Login
                        </option>
                    </select>
                    <label for="loginModeSelect">Login Type</label>
                </div>

                <!-- Login Forms Container -->
                <div id="loginFormsContainer">
                    <!-- Member Login Form -->
                    <div id="member-login" style="display: <?php echo ($login_mode === 'member' ? 'block' : 'none'); ?>;">
                        <form method="post" autocomplete="off" id="memberLoginForm" action="<?php echo BASE_URL; ?>/login.php">
                            <input type="hidden" name="login_mode" value="member">
                            
                            <div class="form-floating">
                                <input type="text" class="form-control" id="crn" name="crn" 
       style="text-transform:uppercase;" 
       placeholder="Church Registration Number" required 
       value="<?php echo htmlspecialchars(strtoupper($_POST['crn'] ?? '')); ?>">
                                <label for="crn">Church Registration Number</label>
                                <i class="fas fa-id-card input-icon"></i>
                            </div>

                            <div class="form-floating">
                                <input type="password" class="form-control" id="password_member" 
                                       name="password" placeholder="Password" required>
                                <label for="password_member">Password</label>
                                <i class="fas fa-lock input-icon toggle-password" data-target="password_member"></i>
                            </div>

                            <button type="submit" class="btn btn-login btn-member">
                                <i class="fas fa-sign-in-alt me-2"></i>Login as Member
                            </button>
                        </form>
                    </div>

                    <!-- Admin Login Form -->
                    <div id="admin-login" style="display: <?php echo ($login_mode !== 'member' ? 'block' : 'none'); ?>;">
                        <form method="post" autocomplete="off" id="adminLoginForm" action="<?php echo BASE_URL; ?>/login.php">
                            <input type="hidden" name="login_mode" value="user">
                            
                            <div class="form-floating">
                                <input type="email" class="form-control" id="email" name="email" 
                                       placeholder="Email Address" required 
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                                <label for="email">Email Address</label>
                                <i class="fas fa-envelope input-icon"></i>
                            </div>

                            <div class="form-floating">
                                <input type="password" class="form-control" id="password_admin" 
                                       name="password" placeholder="Password" required>
                                <label for="password_admin">Password</label>
                                <i class="fas fa-lock input-icon toggle-password" data-target="password_admin"></i>
                            </div>

                            <button type="submit" class="btn btn-login btn-admin">
                                <i class="fas fa-shield-alt me-2"></i>Login as Admin
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Forgot Password Link -->
                <div class="forgot-password">
                    <a href="<?php echo BASE_URL; ?>/forgot_password.php">
                        <i class="fas fa-key me-1"></i>Forgot your password?
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Password toggle functionality
            document.querySelectorAll('.toggle-password').forEach(function(toggle) {
                toggle.addEventListener('click', function() {
                    const targetId = this.getAttribute('data-target');
                    const passwordField = document.getElementById(targetId);
                    const icon = this;
                    
                    if (passwordField.type === 'password') {
                        passwordField.type = 'text';
                        icon.classList.remove('fa-lock');
                        icon.classList.add('fa-lock-open');
                    } else {
                        passwordField.type = 'password';
                        icon.classList.remove('fa-lock-open');
                        icon.classList.add('fa-lock');
                    }
                });
            });

            // Force CRN input to uppercase and auto-format with dashes
            var crnInput = document.getElementById('crn');
            if (crnInput) {
                crnInput.addEventListener('input', function(e) {
                    let value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, ''); // Remove all non-alphanumeric
                    let formatted = '';
                    
                    // Format: FMC-K0101-KM (3-5-2 pattern)
                    if (value.length > 0) {
                        formatted = value.substring(0, 3); // First 3 chars (FMC)
                        if (value.length > 3) {
                            formatted += '-' + value.substring(3, 8); // Next 5 chars with dash (K0101)
                            if (value.length > 8) {
                                formatted += '-' + value.substring(8, 10); // Last 2 chars with dash (KM)
                            }
                        }
                    }
                    
                    this.value = formatted;
                });
                
                // Handle backspace to allow proper deletion of dashes
                crnInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Backspace') {
                        let cursorPos = this.selectionStart;
                        let value = this.value;
                        
                        // If cursor is right after a dash, move cursor back one more position
                        if (cursorPos > 0 && value[cursorPos - 1] === '-') {
                            setTimeout(() => {
                                this.setSelectionRange(cursorPos - 1, cursorPos - 1);
                            }, 0);
                        }
                    }
                });
            }
            // Form submission with loading state
            document.querySelectorAll('form').forEach(function(form) {
                form.addEventListener('submit', function(e) {
                    const submitBtn = form.querySelector('button[type="submit"]');
                    const originalText = submitBtn.innerHTML;
                    
                    // Add loading state
                    submitBtn.classList.add('btn-loading');
                    submitBtn.disabled = true;
                    
                    // Reset after 3 seconds if form doesn't redirect
                    setTimeout(function() {
                        submitBtn.classList.remove('btn-loading');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText;
                    }, 3000);
                });
            });

            // Login mode select functionality
            document.getElementById('loginModeSelect').addEventListener('change', function(e) {
                const selectedMode = e.target.value;
                const memberForm = document.getElementById('member-login');
                const adminForm = document.getElementById('admin-login');
                
                if (selectedMode === 'member') {
                    memberForm.style.display = 'block';
                    adminForm.style.display = 'none';
                } else {
                    memberForm.style.display = 'none';
                    adminForm.style.display = 'block';
                }
            });

            // Input focus animations
            document.querySelectorAll('.form-control').forEach(function(input) {
                input.addEventListener('focus', function() {
                    this.parentElement.style.transform = 'translateY(-2px)';
                });
                
                input.addEventListener('blur', function() {
                    this.parentElement.style.transform = 'translateY(0)';
                });
            });

            // Success animation for login card
            if (window.location.search.includes('success')) {
                document.querySelector('.login-card').classList.add('success-animation');
            }

            // Auto-focus first input
            const activeTab = document.querySelector('.tab-pane.active');
            if (activeTab) {
                const firstInput = activeTab.querySelector('.form-control');
                if (firstInput) {
                    setTimeout(() => firstInput.focus(), 100);
                }
            }
        });
    </script>
</body>
</html>
