<?php
//if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

// Check if editing or creating
$editing = isset($_GET['id']) && is_numeric($_GET['id']);
$required_permission = $editing ? 'edit_user' : 'create_user';

if (!$is_super_admin && !has_permission($required_permission)) {
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
$can_add = $is_super_admin || has_permission('create_user');
$can_edit = $is_super_admin || has_permission('edit_user');
$can_view = true; // Already validated above

$error = '';
$success = '';
$user = [
    'name' => '', 'email' => '', 'phone' => '', 'password' => '', 'role_id' => [], 'status' => 'active',
    'church_id' => '', 'class_id' => '', 'crn' => ''
];

// Edit mode: fetch user and roles
if ($editing) {
    $uid = intval($_GET['id']);
    $stmt = $conn->prepare('SELECT name, email, phone, status, member_id FROM users WHERE id = ?');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $stmt->bind_result($name, $email, $phone, $status, $member_id);
    if ($stmt->fetch()) {
        $user['name'] = $name;
        $user['email'] = $email;
        $user['phone'] = $phone;
        $user['status'] = $status;
    }
    $stmt->close();
    // fetch member details for church/class
    if (isset($member_id) && $member_id) {
        $stmt2 = $conn->prepare('SELECT church_id, class_id, crn FROM members WHERE id = ?');
        $stmt2->bind_param('i', $member_id);
        $stmt2->execute();
        $stmt2->bind_result($church_id, $class_id, $crn);
        if ($stmt2->fetch()) {
            $user['church_id'] = $church_id;
            $user['class_id'] = $class_id;
            $user['crn'] = $crn;
        }
        $stmt2->close();
    }
    // fetch all roles for user
    $user['role_id'] = [];
    $rstmt = $conn->prepare('SELECT role_id FROM user_roles WHERE user_id = ?');
    $rstmt->bind_param('i', $uid);
    $rstmt->execute();
    $rstmt->bind_result($rid);
    while ($rstmt->fetch()) {
        $user['role_id'][] = $rid;
    }
    $rstmt->close();
}

// Generate form token for CSRF protection
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}

// Fetch dropdowns
$churches = $conn->query("SELECT id, name FROM churches ORDER BY name ASC");
$roles = $conn->query("SELECT id, name FROM roles ORDER BY name ASC");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Prevent duplicate submissions using session token
    $form_token = $_POST['form_token'] ?? '';
    $session_token = $_SESSION['form_token'] ?? '';
    
    if (empty($form_token) || $form_token !== $session_token) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        // Clear the token to prevent reuse
        unset($_SESSION['form_token']);
    }
    
    if (empty($error)) {
    if ($editing) {
        // EDIT MODE: Only update user and roles, never create or link member
        $user['name'] = trim($_POST['name'] ?? '');
        $user['email'] = trim($_POST['email'] ?? '');
        $user['phone'] = trim($_POST['phone'] ?? '');
        $user['role_id'] = isset($_POST['role_id']) ? (is_array($_POST['role_id']) ? $_POST['role_id'] : [$_POST['role_id']]) : [];
        $user['status'] = trim($_POST['status'] ?? 'active');
        $user['church_id'] = trim($_POST['church_id'] ?? '');
        $user['class_id'] = trim($_POST['class_id'] ?? '');
        $user['crn'] = trim($_POST['crn'] ?? '');
        $user['password'] = $_POST['password'] ?? '';
        // Validation
        if ($user['name'] === '') $error .= 'Full name is required.<br>';
        if (!filter_var($user['email'], FILTER_VALIDATE_EMAIL)) $error .= 'Valid email is required.<br>';
        if ($user['phone'] === '') $error .= 'Phone is required.<br>';
        if (empty($user['role_id'])) $error .= 'At least one role is required.<br>';
        if (!$editing && $user['church_id'] === '') $error .= 'Church is required.<br>';
        if (!$editing && $user['class_id'] === '') $error .= 'Bible Class is required.<br>';
        // Uniqueness checks
        $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $stmt->bind_param('si', $user['email'], $uid);
        $stmt->execute();
        $stmt->bind_result($uid2);
        if ($stmt->fetch()) $error .= 'Email already exists in users table.<br>';
        $stmt->close();
        $stmt = $conn->prepare('SELECT id FROM users WHERE phone = ? AND id != ?');
        $stmt->bind_param('si', $user['phone'], $uid);
        $stmt->execute();
        $stmt->bind_result($uid2);
        if ($stmt->fetch()) $error .= 'Phone already exists in users table.<br>';
        $stmt->close();
        if ($error === '') {
            // Update user
            $query = 'UPDATE users SET name=?, email=?, phone=?, status=?, church_id=?';
            $params = [$user['name'], $user['email'], $user['phone'], $user['status'], $user['church_id']];
            $types = 'ssssi';
            if ($user['password']) {
                $query .= ', password_hash=?';
                $params[] = password_hash($user['password'], PASSWORD_DEFAULT);
                $types .= 's';
            }
            $query .= ' WHERE id=?';
            $params[] = $uid;
            $types .= 'i';
            $stmt = $conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
            // Update roles
            $conn->query('DELETE FROM user_roles WHERE user_id=' . intval($uid));
            foreach($user['role_id'] as $rid) {
                $stmt = $conn->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
                $stmt->bind_param('ii', $uid, $rid);
                $stmt->execute();
                $stmt->close();
            }
            $success = 'User updated successfully.';
        }
    } else {
        // ADD MODE: Create user and member
        $user['name'] = trim($_POST['name'] ?? '');
        $user['email'] = trim($_POST['email'] ?? '');
        $user['phone'] = trim($_POST['phone'] ?? '');
        $user['role_id'] = isset($_POST['role_id']) ? (is_array($_POST['role_id']) ? $_POST['role_id'] : [$_POST['role_id']]) : [];
        $user['status'] = trim($_POST['status'] ?? 'active');
        $user['church_id'] = trim($_POST['church_id'] ?? '');
        $user['class_id'] = trim($_POST['class_id'] ?? '');
        $user['crn'] = trim($_POST['crn'] ?? '');
        $user['password'] = $_POST['password'] ?? '';
        $link_to_member = isset($_POST['link_to_member']) ? intval($_POST['link_to_member']) : 0;
        // Validation
        if ($user['name'] === '') $error .= 'Full name is required.<br>';
        if (!filter_var($user['email'], FILTER_VALIDATE_EMAIL)) $error .= 'Valid email is required.<br>';
        if ($user['phone'] === '') $error .= 'Phone is required.<br>';
        if (empty($user['role_id'])) $error .= 'At least one role is required.<br>';
        if ($user['church_id'] === '') $error .= 'Church is required.<br>';
        if ($user['class_id'] === '') $error .= 'Bible Class is required.<br>';
        if (!$editing && strlen($user['password']) < 6) $error .= 'Password must be at least 6 characters.<br>';
        // Uniqueness checks (users table)
        $stmt = $conn->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->bind_param('s', $user['email']);
        $stmt->execute();
        $stmt->bind_result($uid);
        if ($stmt->fetch()) $error .= 'Email already exists in users table.<br>';
        $stmt->close();
        $stmt = $conn->prepare('SELECT id FROM users WHERE phone = ?');
        $stmt->bind_param('s', $user['phone']);
        $stmt->execute();
        $stmt->bind_result($uid);
        if ($stmt->fetch()) $error .= 'Phone already exists in users table.<br>';
        $stmt->close();
        // Check for existing member
        $existing_member_id = 0;
        $stmt = $conn->prepare('SELECT id FROM members WHERE email = ? OR phone = ? LIMIT 1');
        $stmt->bind_param('ss', $user['email'], $user['phone']);
        $stmt->execute();
        $stmt->bind_result($mid);
        if ($stmt->fetch()) $existing_member_id = $mid;
        $stmt->close();
        if ($existing_member_id && !$link_to_member) {
            // Prompt: show confirmation UI to link user to this member
            // Fetch the member's CRN and use it for the form
            $stmt = $conn->prepare('SELECT crn FROM members WHERE id = ?');
            $stmt->bind_param('i', $existing_member_id);
            $stmt->execute();
            $stmt->bind_result($existing_crn);
            if ($stmt->fetch()) {
                $user['crn'] = $existing_crn;
            }
            $stmt->close();
            $error = '';
            $show_link_prompt = true;
        } else if ($existing_member_id && isset($_POST['skip_member_link']) && $_POST['skip_member_link'] == '1') {
            // Skip linking, just create user without member_id
            $conn->begin_transaction();
            try {
                $password_hash = password_hash($user['password'], PASSWORD_DEFAULT);
                $stmt = $conn->prepare('INSERT INTO users (name, email, phone, password_hash, status, member_id, church_id) VALUES (?, ?, ?, ?, ?, NULL, ?)');
                $stmt->bind_param('ssssss', $user['name'], $user['email'], $user['phone'], $password_hash, $user['status'], $user['church_id']);
                $stmt->execute();
                if ($stmt->affected_rows <= 0) throw new Exception('Failed to create user.');
                $user_id = $stmt->insert_id;
                $stmt->close();
                // Insert user_roles
                foreach($user['role_id'] as $rid) {
                    $stmt = $conn->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
                    $stmt->bind_param('ii', $user_id, $rid);
                    $stmt->execute();
                    $stmt->close();
                }
                $conn->commit();
                header('Location: user_list.php');
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Error: ' . $e->getMessage();
            }
        } else if ($existing_member_id && $link_to_member == $existing_member_id) {
            // Link to existing member
            $conn->begin_transaction();
            try {
                $password_hash = password_hash($user['password'], PASSWORD_DEFAULT);
                $stmt = $conn->prepare('INSERT INTO users (name, email, phone, password_hash, status, member_id, church_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('sssssis', $user['name'], $user['email'], $user['phone'], $password_hash, $user['status'], $existing_member_id, $user['church_id']);
                $stmt->execute();
                if ($stmt->affected_rows <= 0) throw new Exception('Failed to create user.');
                $user_id = $stmt->insert_id;
                $stmt->close();
                // Insert user_roles
                foreach($user['role_id'] as $rid) {
                    $stmt = $conn->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
                    $stmt->bind_param('ii', $user_id, $rid);
                    $stmt->execute();
                    $stmt->close();
                }
                $conn->commit();
                header('Location: user_list.php');
                exit;
                // $success = 'User account created and linked to existing member.';
                // $user = [
                //     'name' => '', 'email' => '', 'phone' => '', 'password' => '', 'role_id' => '', 'status' => 'active',
                //     'church_id' => '', 'class_id' => '', 'crn' => ''
                // ];
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Error: ' . $e->getMessage();
            }
        } else if ($error === '') {
            // No existing member, proceed as before
            $conn->begin_transaction();
            try {
                // Insert member
                $registration_token = bin2hex(random_bytes(16));
                $stmt = $conn->prepare('INSERT INTO members (first_name, middle_name, last_name, crn, phone, email, class_id, church_id, registration_token, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $first_name = $user['name'];
                $middle_name = '';
                $last_name = '';
                $status = 'pending';
                $stmt->bind_param('ssssssssss', $first_name, $middle_name, $last_name, $user['crn'], $user['phone'], $user['email'], $user['class_id'], $user['church_id'], $registration_token, $status);
                if (!$stmt->execute()) {
                    error_log('Member creation SQL error: ' . $stmt->error);
                    throw new Exception('Failed to create member: ' . $stmt->error);
                }
                if ($stmt->affected_rows <= 0) throw new Exception('Failed to create member.');
                $member_id = $stmt->insert_id;
                $stmt->close();
                // Send registration SMS with link
                if (!empty($user['phone'])) {
                    require_once __DIR__.'/../includes/sms.php';
                    require_once __DIR__.'/../includes/sms_templates.php';
                    $tpl = get_sms_template('registration_link', $conn);
                    if ($tpl) {
                        $registration_link = BASE_URL . '/views/complete_registration.php?token=' . urlencode($registration_token);
                        $msg = fill_sms_template($tpl['body'], [
                            'name' => $first_name,
                            'link' => $registration_link
                        ]);
                        send_sms($user['phone'], $msg);
                        log_sms($user['phone'], $msg);
                    }
                }
                // Insert user
                $password_hash = password_hash($user['password'], PASSWORD_DEFAULT);
                $stmt = $conn->prepare('INSERT INTO users (name, email, phone, password_hash, status, member_id, church_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('sssssis', $user['name'], $user['email'], $user['phone'], $password_hash, $user['status'], $member_id, $user['church_id']);
                $stmt->execute();
                if ($stmt->affected_rows <= 0) throw new Exception('Failed to create user.');
                $user_id = $stmt->insert_id;
                $stmt->close();
                // Insert user_roles
                foreach($user['role_id'] as $rid) {
                    $stmt = $conn->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
                    $stmt->bind_param('ii', $user_id, $rid);
                    $stmt->execute();
                    $stmt->close();
                }
                $conn->commit();
                header('Location: user_list.php');
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Error: ' . $e->getMessage();
            }
        }
    }
    } // End of empty($error) check
}

ob_start();
?>
<!-- Modern Bootstrap Card Layout -->
<div class="container-fluid px-2 px-md-4">
  <div class="row justify-content-center mt-4">
    <!-- Main Form Card -->
    <div class="col-lg-7 col-md-10 mb-4">
      <div class="card shadow-lg border-0 rounded-lg">
        <div class="card-header bg-primary text-white d-flex align-items-center">
          <i class="fas fa-user-edit fa-fw mr-2"></i>
          <h3 class="m-0 font-weight-bold flex-grow-1" style="font-size:1.3rem;"> <?= isset($editing) && $editing ? 'Edit' : 'Add' ?> User</h3>
        </div>
        <div class="card-body p-4">
          <?php if ($error): ?>
            <div class="alert alert-danger font-weight-bold" style="font-size:1.1em;"> <?= $error ?> </div>
          <?php endif; ?>
          <?php if ($success): ?>
            <div class="alert alert-success font-weight-bold" style="font-size:1.1em;"> <?= $success ?> </div>
          <?php endif; ?>
          <?php if (!empty($show_link_prompt) && $show_link_prompt && !empty($existing_member_id)): ?>
            <div class="alert alert-warning font-weight-bold" style="font-size:1.1em;">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                A member with this email or phone already exists in the system.<br>
                <strong>CRN:</strong> <?php
                    $stmt = $conn->prepare('SELECT crn, first_name, last_name FROM members WHERE id = ?');
                    $stmt->bind_param('i', $existing_member_id);
                    $stmt->execute();
                    $stmt->bind_result($existing_crn, $existing_first, $existing_last);
                    $stmt->fetch();
                    $stmt->close();
                    echo htmlspecialchars($existing_crn);
                ?><br>
                <strong>Name:</strong> <?= htmlspecialchars($existing_first . ' ' . $existing_last) ?><br>
                <br>
                Would you like to link this user account to the existing member record above?<br>
                <form method="post" autocomplete="off" id="linkMemberForm">
                    <input type="hidden" name="form_token" value="<?= htmlspecialchars($_SESSION['form_token']) ?>">
                    <?php foreach ($_POST as $k => $v):
                        if ($k === 'link_to_member' || $k === 'form_token') continue;
                        if (is_array($v)) {
                            foreach ($v as $vv) {
                                echo '<input type="hidden" name="'.htmlspecialchars($k).'[]" value="'.htmlspecialchars($vv).'">';
                            }
                        } else {
                            echo '<input type="hidden" name="'.htmlspecialchars($k).'" value="'.htmlspecialchars($v).'">';
                        }
                    endforeach; ?>
                    <input type="hidden" name="link_to_member" value="<?= $existing_member_id ?>">
                    <button type="submit" class="btn btn-primary mt-2">Yes, Link User to Member</button>
                    <button type="submit" name="skip_member_link" value="1" class="btn btn-warning mt-2 ml-2">No, Just Create User</button>
                    <a href="user_form.php" class="btn btn-secondary mt-2 ml-2">Cancel</a>
                </form>
            </div>
            <script>$(function(){ $('#userForm').hide(); });</script>
          <?php endif; ?>
          <form method="post" autocomplete="off" id="userForm">
            <input type="hidden" name="form_token" value="<?= htmlspecialchars($_SESSION['form_token']) ?>">
            <!-- Account Info -->
            <div class="mb-4 pb-3 border-bottom">
              <h5 class="font-weight-bold mb-3"><i class="fas fa-user-circle mr-2 text-primary"></i>Account Info</h5>
              <div class="form-row">
                <div class="form-group col-md-6">
                  <label for="name" class="font-weight-bold">Full Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($user['name'] ?? '') ?>" required maxlength="100">
                  <small class="form-text text-muted">Required.</small>
                </div>
                <div class="form-group col-md-6">
                  <label for="email" class="font-weight-bold">Email <span class="text-danger">*</span></label>
                  <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required maxlength="100">
                  <div id="email-feedback"></div>
                  <small class="form-text text-muted">Required. Must be unique.</small>
                </div>
              </div>
              <div class="form-row">
                <div class="form-group col-md-6">
                  <label for="phone" class="font-weight-bold">Phone <span class="text-danger">*</span></label>
                  <input type="tel" class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" required maxlength="20" pattern="[0-9+\-() ]+">
                  <div id="phone-feedback"></div>
                  <small class="form-text text-muted">Required. Must be valid and unique.</small>
                </div>
                <div class="form-group col-md-6">
                  <label for="password" class="font-weight-bold">Password <?= isset($editing) && $editing ? '' : '<span class="text-danger">*</span>' ?></label>
                  <div class="input-group">
                    <input type="password" class="form-control" id="password" name="password" <?= isset($editing) && $editing ? '' : 'required' ?> minlength="6" autocomplete="new-password">
                    <div class="input-group-append">
                      <span class="input-group-text" style="cursor:pointer;" onclick="togglePassword()">
                        <i class="fas fa-eye" id="togglePasswordIcon"></i>
                      </span>
                    </div>
                  </div>
                  <?php if (isset($editing) && $editing): ?><small class="form-text text-muted">Leave blank to keep current password.</small><?php endif; ?>
                </div>
              </div>
            </div>
            <!-- Member Info -->
            <div class="mb-4 pb-3 border-bottom">
              <h5 class="font-weight-bold mb-3"><i class="fas fa-users mr-2 text-primary"></i>Member Info</h5>
              <div class="form-row">
                <div class="form-group col-md-6">
                  <label for="church_id" class="font-weight-bold">Church<?php if ($editing): ?> <span class="text-muted">(optional)</span><?php else: ?> <span class="text-danger">*</span><?php endif; ?></label>
                  <select class="form-control" id="church_id" name="church_id"<?php if (!$editing): ?> required<?php endif; ?>>
                    <option value="">-- Select Church --</option>
                    <?php 
                    $churches2 = $conn->query("SELECT id, name FROM churches ORDER BY name ASC");
                    if ($churches2 && $churches2->num_rows > 0):
                      while($ch = $churches2->fetch_assoc()): ?>
                        <option value="<?= $ch['id'] ?>" <?= (isset($user['church_id']) && $user['church_id'] == $ch['id']) ? 'selected' : '' ?>><?= htmlspecialchars($ch['name']) ?></option>
                    <?php endwhile; endif; ?>
                  </select>
                  <small class="form-text text-muted">Required. Changing church will reset class selection.</small>
                </div>
                <div class="form-group col-md-6">
                  <label for="class_id" class="font-weight-bold">Bible Class<?php if ($editing): ?> <span class="text-muted">(optional)</span><?php else: ?> <span class="text-danger">*</span><?php endif; ?></label>
                  <select class="form-control" id="class_id" name="class_id"<?php if (!$editing): ?> required<?php endif; ?> style="width:100%">
                    <option value="">-- Select Class --</option>
                    <?php
                    if (!empty($user['church_id'])) {
                      $classes = $conn->prepare('SELECT id, name FROM bible_classes WHERE church_id = ? ORDER BY name ASC');
                      $classes->bind_param('i', $user['church_id']);
                      $classes->execute();
                      $classes->bind_result($cid, $cname);
                      while ($classes->fetch()): ?>
                        <option value="<?= $cid ?>" <?= (isset($user['class_id']) && $user['class_id'] == $cid) ? 'selected' : '' ?>><?= htmlspecialchars($cname) ?></option>
                      <?php endwhile;
                      $classes->close();
                    }
                    ?>
                  </select>
                  <small class="form-text text-muted">Required. Classes are loaded based on selected church.</small>
                </div>
              </div>
              <div class="form-row">
                <div class="form-group col-md-6">
                  <label for="crn" class="font-weight-bold">CRN (Auto-generated)</label>
                  <input type="text" class="form-control" id="crn" name="crn" value="<?= htmlspecialchars($user['crn'] ?? '') ?>" readonly>
                  <small class="form-text text-muted">This will be assigned to the member when the user is created.</small>
                </div>
              </div>
            </div>
            <!-- Permissions/Role -->
            <div class="mb-4 pb-3 border-bottom">
              <h5 class="font-weight-bold mb-3"><i class="fas fa-key mr-2 text-primary"></i>Permissions</h5>
              <div class="form-row">
                <div class="form-group col-md-6">
                  <label for="role_id" class="font-weight-bold">Roles <span class="text-danger">*</span></label>
                  <select class="form-control" id="role_id" name="role_id[]" multiple required>
                    <?php
                    $roles2 = $conn->query("SELECT id, name FROM roles ORDER BY name ASC");
                    if ($roles2 && $roles2->num_rows > 0):
                        while($r = $roles2->fetch_assoc()): ?>
                      <option value="<?= $r['id'] ?>" <?= (in_array($r['id'], $user['role_id']) ? 'selected' : '') ?>><?= htmlspecialchars($r['name']) ?></option>
                    <?php endwhile; endif; ?>
                  </select>
                  <small class="form-text text-muted">Hold Ctrl (Windows) or Command (Mac) to select multiple roles.</small>
                </div>
                <div class="form-group col-md-6 d-flex align-items-end">
                  <div style="flex:1">
                    <label for="status" class="font-weight-bold">Status <span class="text-danger">*</span></label>
                    <select class="form-control" id="status" name="status" required>
                      <option value="active" <?= (isset($user['status']) && $user['status']==='active')?'selected':'' ?>>Active</option>
                      <option value="inactive" <?= (isset($user['status']) && $user['status']==='inactive')?'selected':'' ?>>Inactive</option>
                    </select>
                  </div>
                  <?php if (isset($editing) && $editing && isset($_GET['id']) && $user['status']==='active'): ?>
                    <a href="user_deactivate.php?id=<?= intval($_GET['id']) ?>" class="btn btn-outline-danger ml-3 mb-1" onclick="return confirm('Deactivate this user?')" title="Deactivate user">
                      <i class="fas fa-user-slash"></i> De-Activate
                    </a>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <!-- Save/Cancel Buttons -->
            <div class="d-flex justify-content-between align-items-center mt-4 pt-2 border-top sticky-bottom bg-white" style="z-index:10;">
              <button type="submit" id="submitBtn" class="btn btn-success px-4"><i class="fas fa-save mr-1"></i> Save</button>
              <a href="user_list.php" class="btn btn-secondary px-4"><i class="fas fa-arrow-left mr-1"></i> Cancel</a>
            </div>
          </form>
        </div>
      </div>
    </div>
    <!-- Summary Card (Desktop/Responsive) -->
    <div class="col-lg-5 col-md-12 mb-4">
      <div class="card border-info mb-4 shadow-sm">
        <div class="card-header bg-info text-white"><i class="fas fa-id-badge mr-2"></i>Member Preview</div>
        <div class="card-body">
          <ul class="list-unstyled mb-2">
            <li><strong>CRN:</strong> <span id="summary-crn"><?= htmlspecialchars($user['crn'] ?? '') ?></span></li>
            <li><strong>Name:</strong> <span id="summary-name"><?= htmlspecialchars($user['name'] ?? '') ?></span></li>
            <li><strong>Phone:</strong> <span id="summary-phone"><?= htmlspecialchars($user['phone'] ?? '') ?></span></li>
            <li><strong>Email:</strong> <span id="summary-email"><?= htmlspecialchars($user['email'] ?? '') ?></span></li>
            <li><strong>Church:</strong> <span id="summary-church"></span></li>
            <li><strong>Class:</strong> <span id="summary-class"></span></li>
          </ul>
          <div class="small text-muted">All details will be auto-populated for the member when this user is created.</div>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- jQuery and Bootstrap already loaded in layout.php -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(function() {
  $('#role_id').select2({width:'100%',placeholder:'Select roles...'});
});
function togglePassword() {
  var pwd = document.getElementById('password');
  var icon = document.getElementById('togglePasswordIcon');
  if (pwd.type === 'password') {
    pwd.type = 'text';
    icon.classList.remove('fa-eye');
    icon.classList.add('fa-eye-slash');
  } else {
    pwd.type = 'password';
    icon.classList.remove('fa-eye-slash');
    icon.classList.add('fa-eye');
  }
}
$(document).ready(function() {
  function loadClasses(churchId, selectedClassId) {
    if (!churchId) {
      $('#class_id').html('<option value="">-- Select Class --</option>');
      $('#class_id').val('').trigger('change');
      return;
    }
    $.get('ajax_get_classes_by_church.php', {church_id: churchId}, function(options) {
      $('#class_id').html(options);
      if (selectedClassId) {
        $('#class_id').val(selectedClassId).trigger('change');
      } else {
        $('#class_id').val('').trigger('change');
      }
    });
  }
  // On page load, pre-select class if editing
  var initialChurch = $('#church_id').val();
  var initialClass = "<?= isset($user['class_id']) ? htmlspecialchars($user['class_id']) : '' ?>";
  if (initialChurch && initialClass) {
    loadClasses(initialChurch, initialClass);
  } else if (initialChurch) {
    loadClasses(initialChurch, '');
  }
  $('#church_id').on('change', function() {
    loadClasses($(this).val(), '');
    $('#class_id').val('');
  });
  $('#class_id').select2({
    placeholder: '-- Select Class --',
    allowClear: true,
    width: '100%'
  });
  // Real-time email validation
  $('#email').on('blur change', function() {
    var email = $(this).val();
    var input = this;
    if (!email) {
      $(input).removeClass('is-valid is-invalid');
      $('#email-feedback').empty();
      return;
    }
    $.get('ajax_validate_user.php', {type: 'email', value: email, id: 0}, function(resp) {
      $('#email-feedback').empty();
      if (resp.valid) {
        $(input).removeClass('is-invalid').addClass('is-valid');
        $('#email-feedback').html('<div class="valid-feedback d-block">Looks good!</div>');
      } else {
        $(input).removeClass('is-valid').addClass('is-invalid');
        $('#email-feedback').html('<div class="invalid-feedback d-block">'+resp.msg+'</div>');
      }
    }, 'json');
  });
  // Real-time phone validation
  $('#phone').on('blur change', function() {
    var phone = $(this).val();
    var input = this;
    if (!phone) {
      $(input).removeClass('is-valid is-invalid');
      $('#phone-feedback').empty();
      return;
    }
    $.get('ajax_validate_user.php', {type: 'phone', value: phone, id: 0}, function(resp) {
      $('#phone-feedback').empty();
      if (resp.valid) {
        $(input).removeClass('is-invalid').addClass('is-valid');
        $('#phone-feedback').html('<div class="valid-feedback d-block">Looks good!</div>');
      } else {
        $(input).removeClass('is-valid').addClass('is-invalid');
        $('#phone-feedback').html('<div class="invalid-feedback d-block">'+resp.msg+'</div>');
      }
    }, 'json');
  });
  // CRN update logic
  function updateCRN() {
    var classId = $('#class_id').val();
    var churchId = $('#church_id').val();
    if(classId && churchId) {
      $.get('get_next_crn.php', {class_id: classId, church_id: churchId}, function(data) {
        $('#crn').val(data);
        $('#summary-crn').text(data);
      });
    } else {
      $('#crn').val('');
      $('#summary-crn').text('');
    }
  }
  $('#class_id, #church_id').change(updateCRN);
  // On page load, populate CRN if editing or after error
  updateCRN();
  // Live update summary card
  $('#name, #phone, #email').on('input', function() {
    $('#summary-name').text($('#name').val());
    $('#summary-phone').text($('#phone').val());
    $('#summary-email').text($('#email').val());
  });
  $('#church_id').on('change', function() {
    $('#summary-church').text($('#church_id option:selected').text());
  });
  $('#class_id').on('change', function() {
    $('#summary-class').text($('#class_id option:selected').text());
  });
  
  // Prevent multiple form submissions
  $('#userForm').on('submit', function(e) {
    var $submitBtn = $('#submitBtn');
    if ($submitBtn.prop('disabled')) {
      e.preventDefault();
      return false;
    }
    
    // Disable submit button and show loading state
    $submitBtn.prop('disabled', true);
    $submitBtn.html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving...');
    
    // Re-enable after 10 seconds as failsafe
    setTimeout(function() {
      $submitBtn.prop('disabled', false);
      $submitBtn.html('<i class="fas fa-save mr-1"></i> Save');
    }, 10000);
  });
});
</script>
<?php
$page_content = ob_get_clean();
require_once __DIR__.'/../includes/layout.php';
// --- END LAYOUT HOOK ---
?>
