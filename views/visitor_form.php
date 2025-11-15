<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

// Check if editing or creating
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$editing = $id > 0;
$required_permission = $editing ? 'edit_visitor' : 'create_visitor';

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
$can_add = $is_super_admin || has_permission('create_visitor');
$can_edit = $is_super_admin || has_permission('edit_visitor');
$can_view = true; // Already validated above

$errors = [];
$name = $phone = $email = $address = $visit_date = $invited_by = $purpose = '';
$gender = $home_town = $region = $occupation = $marital_status = $want_member = '';
$church_id = '';
$members = [];
$churches = [];
$cres = $conn->query("SELECT id, name FROM churches ORDER BY name");
if ($cres && $cres->num_rows > 0) {
    while ($c = $cres->fetch_assoc()) {
        $churches[] = $c;
    }
}
$mres = $conn->query("SELECT id, crn, CONCAT(last_name, ' ', first_name, ' ', middle_name) as name FROM members WHERE status='active' ORDER BY last_name, first_name");
if ($mres && $mres->num_rows > 0) {
    while ($m = $mres->fetch_assoc()) {
        $members[] = $m;
    }
}

// Region options (can be moved to config if needed)
$regions = [
    'Greater Accra', 'Ashanti', 'Central', 'Eastern', 'Western', 'Volta', 'Northern', 'Upper East', 'Upper West', 'Bono', 'Bono East', 'Ahafo', 'Oti', 'Savannah', 'North East', 'Western North'
];
// Marital status options
$marital_statuses = ['Single', 'Married', 'Divorced', 'Widowed'];
// Gender options
$genders = ['Male', 'Female', 'Other'];

// If editing, fetch visitor data
if ($editing) {
    $result = $conn->query("SELECT * FROM visitors WHERE id = $id LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $v = $result->fetch_assoc();
        $church_id = $v['church_id'] ?? '';
        $name = $v['name'];
        $phone = $v['phone'];
        $email = $v['email'];
        $address = $v['address'];
        $purpose = $v['purpose'];
        $gender = $v['gender'] ?? '';
        $home_town = $v['home_town'] ?? '';
        $region = $v['region'] ?? '';
        $occupation = $v['occupation'] ?? '';
        $marital_status = $v['marital_status'] ?? '';
        $want_member = $v['want_member'] ?? '';
        $visit_date = $v['visit_date'];
        $invited_by = $v['invited_by'];
    } else {
        $errors[] = "Visitor not found.";
        $editing = false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $church_id = trim($_POST['church_id'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $visit_date = trim($_POST['visit_date'] ?? '');
    $invited_by = trim($_POST['invited_by'] ?? ''); // will be member_id
    if ($invited_by === '' || !is_numeric($invited_by)) {
        $invited_by = null;
    } else {
        $invited_by = (int)$invited_by;
    }
    $purpose = trim($_POST['purpose'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $home_town = trim($_POST['home_town'] ?? '');
    $region = trim($_POST['region'] ?? '');
    $occupation = trim($_POST['occupation'] ?? '');
    $marital_status = trim($_POST['marital_status'] ?? '');
    $want_member = trim($_POST['want_member'] ?? '');

    // Validation
    if ($church_id === '' || !is_numeric($church_id)) $errors[] = 'Church is required.';
    if ($name === '') $errors[] = 'Name is required.';
    if ($visit_date === '') {
        $errors[] = 'Visit date is required.';
    } else if ($visit_date > date('Y-m-d')) {
        $errors[] = 'Visit date cannot be in the future.';
    }
    if ($purpose === '') {
        $errors[] = 'Purpose is required.';
    }
    // Optionally require gender, marital_status, want_member

    // Check for duplicate phone number (normalize and trim)
    $phone_trimmed = preg_replace('/\D+/', '', $phone); // remove non-digits for comparison
    if ($phone_trimmed !== '') {
        $dup_sql = $editing ? "SELECT id FROM visitors WHERE REPLACE(phone, ' ', '') = ? AND id != ? LIMIT 1" : "SELECT id FROM visitors WHERE REPLACE(phone, ' ', '') = ? LIMIT 1";
        $dup_stmt = $conn->prepare($dup_sql);
        if ($dup_stmt) {
            if ($editing) {
                $dup_stmt->bind_param('si', $phone_trimmed, $id);
            } else {
                $dup_stmt->bind_param('s', $phone_trimmed);
            }
            $dup_stmt->execute();
            $dup_stmt->store_result();
            if ($dup_stmt->num_rows > 0) {
                $errors[] = 'A visitor with this phone number already exists.';
            }
            $dup_stmt->close();
        } else {
            error_log('Duplicate phone check prepare failed: ' . $conn->error);
        }
    }

    if (empty($errors)) {
        if ($editing) {
            $stmt = $conn->prepare("UPDATE visitors SET church_id=?, name=?, phone=?, email=?, address=?, purpose=?, gender=?, home_town=?, region=?, occupation=?, marital_status=?, want_member=?, visit_date=?, invited_by=? WHERE id=?");
            $stmt->bind_param('issssssssssssii', $church_id, $name, $phone, $email, $address, $purpose, $gender, $home_town, $region, $occupation, $marital_status, $want_member, $visit_date, $invited_by, $id);
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("INSERT INTO visitors (church_id, name, phone, email, address, purpose, gender, home_town, region, occupation, marital_status, want_member, visit_date, invited_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('issssssssssssi', $church_id, $name, $phone, $email, $address, $purpose, $gender, $home_town, $region, $occupation, $marital_status, $want_member, $visit_date, $invited_by);
            $stmt->execute();
            // Send welcome SMS if phone is not empty
            if (!empty($phone)) {
                require_once __DIR__.'/../includes/sms.php';
                $first_name = explode(' ', trim($name))[0];
                $welcome_msg = "Hello $first_name, we warmly welcome you to Freeman Methodist Chapel, Kwesimintsim. We pray that the good Lord will meet you at the point of your spiritual and physical needs. Enjoy your stay with us. Freeman...Christ Mu Adehye!";
                $debug_file = __DIR__.'/../logs/visitor_sms_debug.log';
                file_put_contents($debug_file, date('c') . " - Attempting to send visitor SMS to $phone: $welcome_msg\n", FILE_APPEND);
                try {
                    $sms_result = log_sms($phone, $welcome_msg, null, 'visitor_welcome');
                    file_put_contents($debug_file, date('c') . " - SMS result: " . json_encode($sms_result) . "\n", FILE_APPEND);
                } catch (Exception $e) {
                    $err = 'Visitor SMS failed: ' . $e->getMessage();
                    file_put_contents($debug_file, date('c') . " - $err\n", FILE_APPEND);
                    error_log($err);
                }
            }
        }
        header('Location: visitor_list.php');
        exit;
    }
}

ob_start();
?>
<div class="container mt-4">
  <div class="row justify-content-center">
    <div class="col-md-8">
      <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
          <h4 class="mb-0"><?php echo $editing ? 'Edit Visitor' : 'Add Visitor'; ?></h4>
        </div>
        <div class="card-body">
          <?php if ($errors): ?>
            <div class="alert alert-danger">
              <ul class="mb-0">
                <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
          <form method="post" autocomplete="off">
            <div class="form-group">
              <label for="church_id">Church <span class="text-danger">*</span></label>
              <select class="form-control" name="church_id" id="church_id" required>
                <option value="">-- Select Church --</option>
                <?php foreach($churches as $c): ?>
                  <option value="<?= $c['id'] ?>" <?= $church_id == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="name">Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="name" id="name" value="<?= htmlspecialchars($name) ?>" required>
            </div>
            <div class="form-row">
              <div class="form-group col-md-6">
                <label for="phone">Phone</label>
                <input type="text" class="form-control" name="phone" id="phone" value="<?= htmlspecialchars($phone) ?>">
              </div>
              <div class="form-group col-md-6">
                <label for="email">Email</label>
                <input type="email" class="form-control" name="email" id="email" value="<?= htmlspecialchars($email) ?>">
              </div>
            </div>
            <div class="form-row">
              <div class="form-group col-md-4">
                <label for="gender">Gender</label>
                <select class="form-control" name="gender" id="gender">
                  <option value="">-- Select Gender --</option>
                  <?php foreach($genders as $g): ?>
                    <option value="<?= $g ?>" <?= $gender == $g ? 'selected' : '' ?>><?= $g ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group col-md-4">
                <label for="home_town">Home Town</label>
                <input type="text" class="form-control" name="home_town" id="home_town" value="<?= htmlspecialchars($home_town) ?>">
              </div>
              <div class="form-group col-md-4">
                <label for="region">Region</label>
                <select class="form-control" name="region" id="region">
                  <option value="">-- Select Region --</option>
                  <?php foreach($regions as $r): ?>
                    <option value="<?= $r ?>" <?= $region == $r ? 'selected' : '' ?>><?= $r ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group col-md-6">
                <label for="occupation">Occupation</label>
                <input type="text" class="form-control" name="occupation" id="occupation" value="<?= htmlspecialchars($occupation) ?>">
              </div>
              <div class="form-group col-md-6">
                <label for="marital_status">Marital Status</label>
                <select class="form-control" name="marital_status" id="marital_status">
                  <option value="">-- Select Marital Status --</option>
                  <?php foreach($marital_statuses as $ms): ?>
                    <option value="<?= $ms ?>" <?= $marital_status == $ms ? 'selected' : '' ?>><?= $ms ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="form-group">
              <label>Do you want to be a member?</label><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="want_member" id="want_member_yes" value="Yes" <?= $want_member == 'Yes' ? 'checked' : '' ?>>
                <label class="form-check-label" for="want_member_yes">Yes</label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="want_member" id="want_member_no" value="No" <?= $want_member == 'No' ? 'checked' : '' ?>>
                <label class="form-check-label" for="want_member_no">No</label>
              </div>
            </div>
            <div class="form-group">
              <label for="address">Address</label>
              <input type="text" class="form-control" name="address" id="address" value="<?= htmlspecialchars($address) ?>">
            </div>
            <div class="form-row">
              <div class="form-group col-md-6">
                <label for="visit_date">Visit Date <span class="text-danger">*</span></label>
                <input type="date" class="form-control" name="visit_date" id="visit_date" value="<?= htmlspecialchars($visit_date) ?>" required max="<?= date('Y-m-d') ?>">
              </div>
              <div class="form-group col-md-6">
                <label for="invited_by">Invited By</label>
                <select class="form-control" name="invited_by" id="invited_by">
                  <option value="">-- Select Member --</option>
                  <?php foreach($members as $m): ?>
                  <option value="<?= $m['id'] ?>" <?= $invited_by == $m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?> (<?= htmlspecialchars($m['crn'] ?? '-') ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
            <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
            <script>$(function() { $('#invited_by').select2({width:'100%',placeholder:'Select member'}); });</script>
<script>$(function() { $('#church_id').select2({width:'100%',placeholder:'Select church'}); });</script>
            <div class="form-group">
              <label for="purpose">Purpose</label>
              <textarea class="form-control" name="purpose" id="purpose" rows="2" required><?= htmlspecialchars($purpose) ?></textarea>
            </div>
            <div class="d-flex justify-content-between">
              <a href="visitor_list.php" class="btn btn-secondary">Cancel</a>
              <button type="submit" class="btn btn-primary"><?php echo $editing ? 'Update' : 'Add'; ?> Visitor</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
