<?php
ob_start();
require_once __DIR__.'/../config/config.php';
// Self-registration: member finds self by registration_token, not by admin ID
$token = $_GET['token'] ?? '';
$error = '';
$success = '';
$member = null;
if ($token) {
    $stmt = $conn->prepare('SELECT * FROM members WHERE registration_token = ? AND status = "pending" LIMIT 1');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $member = $result->fetch_assoc();
    if ($member) {
        $member_id = $member['id'];
    } else {
        $error = 'Invalid or expired registration link.';
    }
} else {
    $error = 'Missing registration token.';
} 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $member) {
    // Gather all fields
    $password = $_POST['password'] ?? '';
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $dob = $_POST['dob'] ?? '';
    $day_born = $dob ? date('l', strtotime($dob)) : '';
    $place_of_birth = trim($_POST['place_of_birth'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $gps_address = trim($_POST['gps_address'] ?? '');
    $marital_status = $_POST['marital_status'] ?? '';
    $home_town = trim($_POST['home_town'] ?? '');
    $region = trim($_POST['region'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $employment_status = $_POST['employment_status'] ?? '';
    $profession = trim($_POST['profession'] ?? '');
    $baptized = $_POST['baptized'] ?? '';
    $confirmed = $_POST['confirmed'] ?? '';
    $date_of_baptism = $_POST['date_of_baptism'] ?? null;
    $date_of_confirmation = $_POST['date_of_confirmation'] ?? null;
    $membership_status = $_POST['membership_status'] ?? '';
    $date_of_enrollment = $_POST['date_of_enrollment'] ?? null;
    $status = 'active';
    // Org(s) multiple select
    $organizations = $_POST['organizations'] ?? [];
    // Roles of Serving multiple select
    $roles_of_serving = $_POST['roles_of_serving'] ?? [];
    // Emergency contacts (dynamic)
    $emergency_contacts = $_POST['emergency_contacts'] ?? [];
    // Photo upload
    $photo = $member['photo'] ?? '';
    $photo_data = $_POST['photo_data'] ?? '';
    if ($photo_data && strpos($photo_data, 'data:image') === 0) {
        // Camera base64 image
        $img_parts = explode(',', $photo_data);
        if (count($img_parts) === 2) {
            $img_base64 = base64_decode($img_parts[1]);
            $filename = uniqid('member_').'.png';
            $dest_dir = __DIR__.'/../uploads/members/';
            if (!is_dir($dest_dir)) {
                mkdir($dest_dir, 0777, true);
            }
            $dest = $dest_dir . $filename;
            file_put_contents($dest, $img_base64);
            $photo = $filename;
        }
    } else if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
        $filename = uniqid('member_').'.'.$ext;
        $dest_dir = __DIR__.'/../uploads/members/';
        if (!is_dir($dest_dir)) {
            mkdir($dest_dir, 0777, true);
        }
        $dest = $dest_dir . $filename;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
            $photo = $filename;
        }
    }
    // Validate required fields (add more as needed)
    // Ensure $emergency_contacts is always an array of arrays
    if (!is_array($emergency_contacts)) $emergency_contacts = [];
    $emergency_contacts = array_filter($emergency_contacts, function($c) {
        return is_array($c) && (isset($c['name']) || isset($c['mobile']) || isset($c['relationship']));
    });
    $valid_contacts = array_filter($emergency_contacts, function($c) {
        return !empty($c['name']) && !empty($c['mobile']) && !empty($c['relationship']);
    });
    // Remove $membership_status from required fields (field removed from form)
    if (!$first_name || !$last_name || !$gender || !$dob || !$place_of_birth || !$marital_status || !$home_town || !$region || !$phone || count($valid_contacts) === 0 || !$employment_status || !$baptized || !$confirmed || !$password) {
        // Debug output for troubleshooting
        error_log('DEBUG: valid_contacts count: ' . count($valid_contacts));
        error_log('DEBUG: emergency_contacts: ' . print_r($emergency_contacts, true));
        $error = 'Please fill in all required fields (at least one emergency contact).';
    } else {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE members SET first_name=?, middle_name=?, last_name=?, gender=?, dob=?, day_born=?, place_of_birth=?, address=?, gps_address=?, marital_status=?, home_town=?, region=?, phone=?, telephone=?, email=?, employment_status=?, profession=?, baptized=?, confirmed=?, date_of_baptism=?, date_of_confirmation=?, membership_status=?, date_of_enrollment=?, photo=?, status=?, password_hash=?, registration_token=NULL WHERE id=?');
        $stmt->bind_param('ssssssssssssssssssssssssssi',
            $first_name, $middle_name, $last_name, $gender, $dob, $day_born, $place_of_birth, $address, $gps_address, $marital_status, $home_town, $region, $phone, $telephone, $email,
            $employment_status, $profession, $baptized, $confirmed, $date_of_baptism, $date_of_confirmation, $membership_status, $date_of_enrollment, $photo, $status, $password_hash, $member_id
        );
        $stmt->execute();
        $affected_rows = $stmt->affected_rows;
        $stmt->close();
        // Update emergency contacts (delete old, insert new)
        $conn->query("DELETE FROM member_emergency_contacts WHERE member_id=" . intval($member['id']));
        if (!empty($valid_contacts)) {
            $ec_stmt = $conn->prepare("INSERT INTO member_emergency_contacts (member_id, name, mobile, relationship) VALUES (?, ?, ?, ?)");
            foreach ($valid_contacts as $c) {
                $ec_stmt->bind_param('isss', $member['id'], $c['name'], $c['mobile'], $c['relationship']);
                $ec_stmt->execute();
            }
            $ec_stmt->close();
        }
        // Handle organization membership requests (pending approval workflow)
        $safe_member_id = isset($member['id']) ? intval($member['id']) : 0;
if ($safe_member_id > 0) {
    // Clear any existing pending requests for this member
    $conn->query("DELETE FROM organization_membership_approvals WHERE member_id=" . $safe_member_id);
} else {
    throw new Exception('Invalid member_id for organization update.');
}
        if (!empty($organizations)) {
            // Insert organization selections as pending approval requests
            $approval_stmt = $conn->prepare("INSERT INTO organization_membership_approvals (member_id, organization_id, status) VALUES (?, ?, 'pending')");
            foreach ($organizations as $org_id) {
                $approval_stmt->bind_param('ii', $member['id'], $org_id);
                $approval_stmt->execute();
            }
            $approval_stmt->close();
        }
        // Update roles of serving (delete old, insert new)
        if ($safe_member_id > 0) {
    $conn->query("DELETE FROM member_roles_of_serving WHERE member_id=" . $safe_member_id);
} else {
    throw new Exception('Invalid member_id for roles of serving update.');
}
        if (!empty($roles_of_serving)) {
            $role_stmt = $conn->prepare("INSERT INTO member_roles_of_serving (member_id, role_id) VALUES (?, ?)");
            foreach ($roles_of_serving as $role_id) {
                $role_stmt->bind_param('ii', $member['id'], $role_id);
                $role_stmt->execute();
            }
            $role_stmt->close();
        }
        if ($affected_rows >= 0) {
            // Send SMS with CRN
            require_once __DIR__.'/../includes/sms.php';
            $login_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $base_url . '/login.php';
            $sms_message = "Dear {$first_name}, your registration is complete. Your CRN is: {$member['crn']}. Login at: $login_url";
            try {
                send_sms($phone, $sms_message);
            } catch (Exception $ex) {
                error_log('SMS send failed: ' . $ex->getMessage());
            }
            // Self-registration: auto-login member and redirect to dashboard
            session_regenerate_id(true);
            $_SESSION['member_id'] = $member['id'];
            $_SESSION['crn'] = $member['crn'];
            $_SESSION['member_name'] = $first_name.' '.$last_name;
            $_SESSION['role'] = 'member';
            $base_url = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
            if ($base_url === '' || $base_url === '.') $base_url = '/myfreeman';
            // Set flag to show toast after redirect
            $_SESSION['show_registration_toast'] = true;
            header('Location: ' . BASE_URL . '/views/member_dashboard.php');
            exit;
        } else {
            $error = 'Database error. Please try again.';
        }
    }
}

ob_start();
?>
<!-- <div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Admin: Complete Member Registration</h1>
</div>  -->
<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="card shadow mb-4" style="background:#fff;z-index:2;position:relative;max-width:900px;margin:40px auto 40px auto;">
            <div class="card-header py-3 bg-primary text-white">
                <h6 class="m-0 font-weight-bold">Admin: Complete Member Registration</h6>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger mb-4"> <?= htmlspecialchars($error) ?> </div>
                <?php elseif ($success): ?>
                    <div class="alert alert-success mb-4"> <?= htmlspecialchars($success) ?> </div>
                <?php endif; ?>
                <?php if ($member && !$success): ?>
                <form method="post" enctype="multipart/form-data" autocomplete="off">

<!-- SECTION: Account Credentials -->
<div class="card mb-4 border-primary">
  <div class="card-header bg-light border-primary"><strong>Account Credentials</strong></div>
  <div class="card-body p-3">
    <div class="form-row">
      <div class="form-group col-md-4">
        <label>CRN</label>
        <div class="input-group">
          <input type="text" class="form-control" id="crn-field" value="<?=htmlspecialchars($member['crn'])?>" readonly>
          <div class="input-group-append">
            <button class="btn btn-outline-secondary" type="button" id="copy-crn-btn" data-toggle="tooltip" data-placement="top" title="Copy"><i class="fa fa-copy"></i></button>
          </div>
        </div>

        <?php
          // Fetch class name if not already present
          $class_name = isset($member['class_name']) ? $member['class_name'] : '';
          if (!$class_name && !empty($member['class_id'])) {
            $stmt_class = $conn->prepare('SELECT name FROM bible_classes WHERE id = ?');
            $stmt_class->bind_param('i', $member['class_id']);
            $stmt_class->execute();
            $res_class = $stmt_class->get_result();
            if ($row_class = $res_class->fetch_assoc()) {
              $class_name = $row_class['name'];
            }
            $stmt_class->close();
          }
        ?>
        <div class="mb-2">
          <label class="font-weight-bold mb-1">Bible Class:</label>
          <span><?= htmlspecialchars($class_name ?: '-') ?></span>
        </div>
        
      </div>
      <div class="form-group col-md-4">
        <label for="photo">Picture</label>
        <?php if (!empty($member['photo'])): ?>
          <div class="mb-2 p-2 bg-white border rounded" style="display:inline-block;"><img src="<?= BASE_URL ?>/uploads/members/<?=rawurlencode($member['photo'])?>" alt="Photo" style="height:70px;width:70px;object-fit:cover;border-radius:8px;box-shadow:0 2px 6px rgba(0,0,0,0.07);"></div>
        <?php endif; ?>
        <div id="photo-upload-group">
          <div class="input-group mb-2" id="photo-upload-section">
            <input type="file" class="form-control" name="photo" id="photo" accept="image/*">
            <div class="input-group-append">
              <button class="btn btn-outline-secondary" type="button" id="camera-btn"><i class="fa fa-camera"></i> Camera</button>
            </div>
          </div>
          <small class="form-text text-muted">Choose either to upload a photo or take a picture, not both.</small>
          <div id="photo-preview-wrap" style="display:none;">
            <img id="photo-preview" src="#" style="max-width:120px;max-height:120px;margin-top:10px;border-radius:8px;" />
            <button type="button" class="btn btn-sm btn-danger ml-2" id="remove-photo-btn"><i class="fa fa-times"></i> Change Photo</button>
          </div>
          <input type="hidden" name="photo_data" id="photo-data">
        </div>
      </div>
      <div class="form-group col-md-4">
        <label for="password">Create Password <span class="text-danger">*</span></label>
        <div class="input-group">
          <input type="password" class="form-control" name="password" id="password" required minlength="6" autocomplete="new-password" placeholder="Create a password">
          <div class="input-group-append">
            <span class="input-group-text toggle-password" style="cursor:pointer;"><i class="fa fa-eye"></i></span>
          </div>
        </div>
        <small class="form-text text-muted">Password will be used to log in with your CRN as username.</small>
      </div>
    </div>
  </div>
</div>

<!-- SECTION: Personal Information -->
<div class="card mb-4 border-primary">
  <div class="card-header bg-light border-primary"><strong>Personal Information</strong></div>
  <div class="card-body p-3">
    <div class="form-row">
      <div class="form-group col-md-4">
        <label for="last_name">Surname <span class="text-danger">*</span></label>
        <input type="text" class="form-control" name="last_name" id="last_name" value="<?=htmlspecialchars($member['last_name'])?>" required>
      </div>
      <div class="form-group col-md-4">
        <label for="middle_name">Other Name</label>
        <input type="text" class="form-control" name="middle_name" id="middle_name" value="<?=htmlspecialchars($member['middle_name'])?>">
      </div>
      <div class="form-group col-md-4">
        <label for="first_name">First Name <span class="text-danger">*</span></label>
        <input type="text" class="form-control" name="first_name" id="first_name" value="<?=htmlspecialchars($member['first_name'])?>" required>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group col-md-3">
        <label>Gender <span class="text-danger">*</span></label><br>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="gender" id="gender_male" value="Male" <?=($member['gender']=='Male')?'checked':''?>>
          <label class="form-check-label" for="gender_male">Male</label>
        </div>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="gender" id="gender_female" value="Female" <?=($member['gender']=='Female')?'checked':''?>>
          <label class="form-check-label" for="gender_female">Female</label>
        </div>
      </div>
      <div class="form-group col-md-3">
        <label for="dob">Date of Birth <span class="text-danger">*</span></label>
        <input type="date" class="form-control" name="dob" id="dob" value="<?=htmlspecialchars($member['dob'])?>" required>
      </div>
      <div class="form-group col-md-3">
        <label for="day_born">Day Born</label>
        <input type="text" class="form-control" name="day_born" id="day_born" value="<?=htmlspecialchars($member['day_born'])?>" readonly>
      </div>
      <div class="form-group col-md-3">
        <label for="place_of_birth">Place of Birth <span class="text-danger">*</span></label>
        <input type="text" class="form-control" name="place_of_birth" id="place_of_birth" value="<?=htmlspecialchars($member['place_of_birth'])?>" required>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group col-md-6">
        <label for="address">Location Address</label>
        <input type="text" class="form-control" name="address" id="address" value="<?=htmlspecialchars($member['address'])?>">
      </div>
      <div class="form-group col-md-6">
        <label for="gps_address">GPS Address</label>
        <input type="text" class="form-control" name="gps_address" id="gps_address" value="<?=htmlspecialchars($member['gps_address'])?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group col-md-4">
        <label for="marital_status">Marital Status <span class="text-danger">*</span></label>
        <select class="form-control" name="marital_status" id="marital_status" required>
          <option value="">-- Select --</option>
          <option value="Married" <?=$member['marital_status']=='Married'?'selected':''?>>Married</option>
          <option value="Single" <?=$member['marital_status']=='Single'?'selected':''?>>Single</option>
          <option value="Widowed" <?=$member['marital_status']=='Widowed'?'selected':''?>>Widowed</option>
          <option value="Divorced" <?=$member['marital_status']=='Divorced'?'selected':''?>>Divorced</option>
        </select>
      </div>
      <div class="form-group col-md-4">
        <label for="home_town">Home Town <span class="text-danger">*</span></label>
        <input type="text" class="form-control" name="home_town" id="home_town" value="<?=htmlspecialchars($member['home_town'])?>" required>
      </div>
      <div class="form-group col-md-4">
        <label for="region">Region <span class="text-danger">*</span></label>
        <select class="form-control" name="region" id="region" data-selected="<?=htmlspecialchars($member['region'])?>" required>
          <option value="">-- Select Region --</option>
          <option value="Ahafo">Ahafo</option>
          <option value="Ashanti">Ashanti</option>
          <option value="Bono">Bono</option>
          <option value="Bono East">Bono East</option>
          <option value="Central">Central</option>
          <option value="Eastern">Eastern</option>
          <option value="Greater Accra">Greater Accra</option>
          <option value="North East">North East</option>
          <option value="Northern">Northern</option>
          <option value="Oti">Oti</option>
          <option value="Savannah">Savannah</option>
          <option value="Upper East">Upper East</option>
          <option value="Upper West">Upper West</option>
          <option value="Volta">Volta</option>
          <option value="Western">Western</option>
          <option value="Western North">Western North</option>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group col-md-4">
        <label for="phone">Mobile No. <span class="text-danger">*</span></label>
        <input type="text" class="form-control" name="phone" id="phone" value="<?=htmlspecialchars($member['phone'])?>" required>
      </div>
      <div class="form-group col-md-4">
        <label for="telephone">Telephone No.</label>
        <input type="text" class="form-control" name="telephone" id="telephone" value="<?=htmlspecialchars($member['telephone'])?>">
      </div>
      <div class="form-group col-md-4">
        <label for="email">Email</label>
        <input type="email" class="form-control" name="email" id="email" value="<?=htmlspecialchars($member['email'])?>">
      </div>
    </div>
  </div>
</div>

<!-- SECTION: Emergency Contacts -->
<div class="card mb-4 border-primary">
  <div class="card-header bg-light border-primary"><strong>Emergency Contacts</strong></div>
  <div class="card-body p-3">
    <div id="emergency-contacts-list">
      <div class="form-row emergency-contact-row">
        <div class="form-group col-md-4">
          <input type="text" class="form-control" name="emergency_contacts[1][name]" placeholder="Contact Name" value="<?=htmlspecialchars($member['emergency_contact1_name'] ?? '')?>" required>
        </div>
        <div class="form-group col-md-4">
          <input type="text" class="form-control" name="emergency_contacts[1][mobile]" placeholder="Mobile" value="<?=htmlspecialchars($member['emergency_contact1_mobile'] ?? '')?>" required>
        </div>
        <div class="form-group col-md-3">
          <input type="text" class="form-control" name="emergency_contacts[1][relationship]" placeholder="Relationship" value="<?=htmlspecialchars($member['emergency_contact1_relationship'] ?? '')?>" required>
        </div>
        <div class="form-group col-md-1">
          <button class="btn btn-danger remove-emergency-contact" type="button" style="margin-top:2px;"><i class="fa fa-trash"></i></button>
        </div>
      </div>
      <?php if (!empty($member['emergency_contact2_name']) || !empty($member['emergency_contact2_mobile']) || !empty($member['emergency_contact2_relationship'])): ?>
      <div class="form-row emergency-contact-row">
        <div class="form-group col-md-4">
          <input type="text" class="form-control" name="emergency_contacts[2][name]" placeholder="Contact Name" value="<?=htmlspecialchars($member['emergency_contact2_name'])?>">
        </div>
        <div class="form-group col-md-4">
          <input type="text" class="form-control" name="emergency_contacts[2][mobile]" placeholder="Mobile" value="<?=htmlspecialchars($member['emergency_contact2_mobile'])?>">
        </div>
        <div class="form-group col-md-3">
          <input type="text" class="form-control" name="emergency_contacts[2][relationship]" placeholder="Relationship" value="<?=htmlspecialchars($member['emergency_contact2_relationship'])?>">
        </div>
        <div class="form-group col-md-1">
          <button class="btn btn-danger remove-emergency-contact" type="button" style="margin-top:2px;"><i class="fa fa-trash"></i></button>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <button class="btn btn-outline-primary mb-3" id="add-emergency-contact" type="button"><i class="fa fa-plus"></i> Add Emergency Contact</button>
  </div>
</div>

<!-- SECTION: Employment & Profession -->
<div class="card mb-4 border-primary">
  <div class="card-header bg-light border-primary"><strong>Employment & Profession</strong></div>
  <div class="card-body p-3">
    <div class="form-row">
      <div class="form-group col-md-6">
        <label for="employment_status">Current Employment Status <span class="text-danger">*</span></label>
        <select class="form-control" name="employment_status" id="employment_status" required>
          <option value="">-- Select --</option>
          <option value="Formal" <?=$member['employment_status']=='Formal'?'selected':''?>>Formal</option>
          <option value="Informal" <?=$member['employment_status']=='Informal'?'selected':''?>>Informal</option>
          <option value="Self Employed" <?=$member['employment_status']=='Self Employed'?'selected':''?>>Self Employed</option>
          <option value="Retired" <?=$member['employment_status']=='Retired'?'selected':''?>>Retired</option>
          <option value="Student" <?=$member['employment_status']=='Student'?'selected':''?>>Student</option>
        </select>
      </div>
      <div class="form-group col-md-6">
        <label for="profession">Profession</label>
        <input type="text" class="form-control" name="profession" id="profession" value="<?=htmlspecialchars($member['profession'])?>">
      </div>
    </div>
  </div>
</div>

<!-- SECTION: Baptism & Confirmation -->
<div class="card mb-4 border-primary">
  <div class="card-header bg-light border-primary"><strong>Baptism & Confirmation</strong></div>
  <div class="card-body p-3">
    <div class="form-row">
      <div class="form-group col-md-3">
        <label>Have you been baptized? <span class="text-danger">*</span></label><br>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="baptized" id="baptized_yes" value="Yes" <?=$member['baptized']=='Yes'?'checked':''?>>
          <label class="form-check-label" for="baptized_yes">Yes</label>
        </div>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="baptized" id="baptized_no" value="No" <?=$member['baptized']=='No'?'checked':''?>>
          <label class="form-check-label" for="baptized_no">No</label>
        </div>
      </div>
      <div class="form-group col-md-3">
        <label>Have you been confirmed? <span class="text-danger">*</span></label><br>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="confirmed" id="confirmed_yes" value="Yes" <?=$member['confirmed']=='Yes'?'checked':''?>>
          <label class="form-check-label" for="confirmed_yes">Yes</label>
        </div>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="confirmed" id="confirmed_no" value="No" <?=$member['confirmed']=='No'?'checked':''?>>
          <label class="form-check-label" for="confirmed_no">No</label>
        </div>
      </div>
      <div class="form-group col-md-3" style="display:none;">
        <label for="date_of_baptism">Date of Baptism</label>
        <input type="date" class="form-control" name="date_of_baptism" id="date_of_baptism" value="<?=htmlspecialchars($member['date_of_baptism'])?>">
      </div>
      <div class="form-group col-md-3" style="display:none;">
        <label for="date_of_confirmation">Date of Confirmation</label>
        <input type="date" class="form-control" name="date_of_confirmation" id="date_of_confirmation" value="<?=htmlspecialchars($member['date_of_confirmation'])?>">
      </div>
    </div>
  </div>
</div>

<!-- SECTION: Membership & Organizations -->
<div class="card mb-4 border-primary">
  <div class="card-header bg-light border-primary"><strong>Membership & Organizations</strong></div>
  <div class="card-body p-3">
    <div class="form-row">

      <div class="form-group col-md-4">
        <label for="date_of_enrollment">Date of Enrollment at Freeman Society</label>
        <input type="date" class="form-control" name="date_of_enrollment" id="date_of_enrollment" value="<?=htmlspecialchars($member['date_of_enrollment'])?>">
      </div>
      <div class="form-group col-md-4">
        <label for="organizations">Organization(s) <span class="badge badge-info">Requires Approval</span></label>
        <select class="form-control" name="organizations[]" id="organizations" multiple>
          <?php
          $orgs = $conn->query("SELECT id, name FROM organizations ORDER BY name ASC");
          $member_orgs = [];
          if (isset($member['id'])) {
            // Load pending approval requests instead of current memberships
            $orgq = $conn->query("SELECT organization_id FROM organization_membership_approvals WHERE member_id=".intval($member['id'])." AND status='pending'");
            while($oo = $orgq->fetch_assoc()) $member_orgs[] = $oo['organization_id'];
          }
          while($org = $orgs->fetch_assoc()): ?>
            <option value="<?=$org['id']?>" <?=in_array($org['id'], $member_orgs)?'selected':''?>><?=htmlspecialchars($org['name'])?></option>
          <?php endwhile; ?>
        </select>
        <small class="form-text text-muted">Selected organizations will require approval from Organization Leaders before membership is confirmed.</small>
      </div>
      <div class="form-group col-md-4">
        <label for="roles_of_serving">Roles of Serving</label>
        <select class="form-control" name="roles_of_serving[]" id="roles_of_serving" multiple>
          <?php
          $roles = $conn->query("SELECT id, name FROM roles_of_serving ORDER BY name ASC");
          $member_roles = [];
          if (isset($member['id'])) {
            $roleq = $conn->query("SELECT role_id FROM member_roles_of_serving WHERE member_id=".intval($member['id']));
            while($ro = $roleq->fetch_assoc()) $member_roles[] = $ro['role_id'];
          }
          while($role = $roles->fetch_assoc()): ?>
            <option value="<?=$role['id']?>" <?=in_array($role['id'], $member_roles)?'selected':''?>><?=htmlspecialchars($role['name'])?></option>
          <?php endwhile; ?>
        </select>
        <small class="form-text text-muted">Hold Ctrl or use search to select multiple roles of serving.</small>
      </div>
    </div>
  </div>
</div>

                <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.full.min.js"></script>
                <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
                <script src="/myfreeman/assets/registration.js"></script>
<script>
$(function(){
    // Click to copy CRN
    $('#copy-crn-btn').tooltip();
    $('#copy-crn-btn').on('click', function(){
        const crn = $('#crn-field').val();
        navigator.clipboard.writeText(crn).then(function(){
            $('#copy-crn-btn').attr('data-original-title', 'Copied!').tooltip('show');
            setTimeout(function(){
                $('#copy-crn-btn').attr('data-original-title', 'Copy');
            }, 1200);
        });
    });
    // Enable Select2 for organizations and roles of serving with identical options
    $('#organizations').select2({
        placeholder: 'Select organizations',
        allowClear: true,
        width: '100%',
        minimumResultsForSearch: 0
    });
    $('#roles_of_serving').select2({
        placeholder: 'Select roles of serving',
        allowClear: true,
        width: '100%',
        minimumResultsForSearch: 0
    });
});
</script>
<script>
$(function(){
    // Show preview and hide upload/camera after file select
    $('#photo').on('change', function(){
        if (this.files && this.files[0]) {
            let reader = new FileReader();
            reader.onload = function(e) {
                $('#photo-preview').attr('src', e.target.result);
                $('#photo-preview-wrap').show();
                $('#photo-upload-section').hide();
                $('#photo-data').val(''); // clear camera
            };
            reader.readAsDataURL(this.files[0]);
        }
    });
    // Show preview and hide upload/camera after camera capture (handled by registration.js)
    $(document).on('change input', '#photo-data', function(){
        if ($(this).val()) {
            $('#photo-preview').attr('src', $(this).val());
            $('#photo-preview-wrap').show();
            $('#photo-upload-section').hide();
            $('#photo').val('');
        }
    });
    // Remove photo, re-enable both options
    $('#photo-upload-group').on('click', '#remove-photo-btn', function(){
        $('#photo-preview').attr('src', '#');
        $('#photo-preview-wrap').hide();
        $('#photo-upload-section').show();
        $('#photo').val('');
        $('#photo-data').val('');
    });
    // When camera modal closes, if no photo-data, re-enable file input
    $('#camera-modal').on('hidden.bs.modal', function(){
        if (!$('#photo-data').val() && !$('#photo').val()) {
            $('#photo-upload-section').show();
            $('#photo-preview-wrap').hide();
        }
    });
});
</script>
                <script>
                document.getElementById('dob').addEventListener('change', function() {
                    var dob = this.value;
                    if (dob) {
                        var day = new Date(dob).toLocaleDateString('en-US', { weekday: 'long' });
                        document.getElementById('day_born').value = day;
                    } else {
                        document.getElementById('day_born').value = '';
                    }
                });

                // Real-time validation for phone and email
                function validatePhone(phone) {
                    // Ghanaian numbers: 10 digits, starts with 0
                    return /^0\d{9}$/.test(phone);
                }
                function validateEmail(email) {
                    // Simple email regex
                    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
                }
                function showFeedback(input, valid, message) {
                    let feedback = input.parentNode.querySelector('.invalid-feedback');
                    if (!feedback) {
                        feedback = document.createElement('div');
                        feedback.className = 'invalid-feedback';
                        input.parentNode.appendChild(feedback);
                    }
                    feedback.textContent = message;
                    if (valid) {
                        input.classList.remove('is-invalid');
                        input.classList.add('is-valid');
                        feedback.style.display = 'none';
                    } else {
                        input.classList.add('is-invalid');
                        input.classList.remove('is-valid');
                        feedback.style.display = 'block';
                    }
                }
                document.getElementById('phone').addEventListener('input', function() {
                    if (this.value.trim() === '') {
                        showFeedback(this, false, 'Mobile number is required.');
                    } else if (!validatePhone(this.value.trim())) {
                        showFeedback(this, false, 'Enter a valid 10-digit Ghanaian mobile number.');
                    } else {
                        showFeedback(this, true, '');
                    }
                });
                document.getElementById('email').addEventListener('input', function() {
                    if (this.value.trim() === '') {
                        this.classList.remove('is-invalid');
                        this.classList.remove('is-valid');
                        let feedback = this.parentNode.querySelector('.invalid-feedback');
                        if (feedback) feedback.style.display = 'none';
                        return;
                    }
                    if (!validateEmail(this.value.trim())) {
                        showFeedback(this, false, 'Enter a valid email address.');
                    } else {
                        showFeedback(this, true, '');
                    }
                });
                // Prevent form submit if invalid
                document.querySelector('form').addEventListener('submit', function(e) {
                    let phoneInput = document.getElementById('phone');
                    let emailInput = document.getElementById('email');
                    let phoneValid = validatePhone(phoneInput.value.trim());
                    let emailValid = emailInput.value.trim() === '' || validateEmail(emailInput.value.trim());
                    if (!phoneValid) {
                        showFeedback(phoneInput, false, 'Enter a valid 10-digit Ghanaian mobile number.');
                    }
                    if (emailInput.value.trim() !== '' && !emailValid) {
                        showFeedback(emailInput, false, 'Enter a valid email address.');
                    }
                    if (!phoneValid || !emailValid) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                });
                </script>
                <!-- Camera Modal -->
                <div class="modal fade" id="camera-modal" tabindex="-1" role="dialog" aria-labelledby="camera-modal-label" aria-hidden="true">
                  <div class="modal-dialog modal-dialog-centered" role="document">
                    <div class="modal-content">
                      <div class="modal-header">
                        <h5 class="modal-title" id="camera-modal-label">Take Photo</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                          <span aria-hidden="true">&times;</span>
                        </button>
                      </div>
                      <div class="modal-body text-center">
                        <video id="camera-video" autoplay playsinline style="width:100%;max-width:320px;border-radius:8px;"></video>
                      </div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-primary" id="capture-btn"><i class="fa fa-camera"></i> Capture</button>
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                      </div>
                    </div>
                  </div>
                </div>
                <?php endif; ?>
                <div class="form-group text-center mt-4">
                    <button type="submit" class="btn btn-success btn-lg px-5">
                        <i class="fa fa-check-circle mr-2"></i> Complete Registration
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<style>
    .select2-container--default .select2-selection--multiple {
        border-radius: 0.35rem; min-height: 38px; border: 1px solid #d1d3e2;
    }
    .emergency-contact-row+.emergency-contact-row { margin-top: 10px; }
</style>
<?php
$page_content = ob_get_clean();
$base_url = BASE_URL;
$logo_url = $base_url . '/assets/logo.png';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Registration</title>
    <base href="<?=$base_url?>/">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" />
    <style>
        body {background: linear-gradient(135deg, #f8fafc 60%, #e3e9f6 100%); min-height:100vh;}
        .bg-gradient-primary {background: linear-gradient(90deg, #3b5998 70%, #6d84b4 100%) !important;}
        .card {border-radius:1.2rem;}
        .card-header {border-top-left-radius:1.2rem;border-top-right-radius:1.2rem;}
        .form-control:focus {border-color:#3b5998;box-shadow:0 0 0 0.2rem rgba(59,89,152,.08);}
        .input-group-text, .btn-outline-secondary {border-radius:0.35rem;}
        .btn-success {background:#3b5998;border:none;}
        .btn-success:hover {background:#29487d;}
        .select2-container--default .select2-selection--multiple {border-radius: 0.35rem; min-height: 38px; border: 1px solid #d1d3e2;}
        .emergency-contact-row+.emergency-contact-row { margin-top: 10px; }
        @media (max-width: 767px) {.card {padding:0;}.card .card-body{padding:1.1rem;}}
    </style>
</head>
<body>
<div class="d-flex align-items-center justify-content-center min-vh-100 bg-light">
  <div class="col-12 col-md-11 col-lg-10 col-xl-9" style="max-width:1100px;">
    <div class="card shadow-lg rounded-lg border-0 animate__animated animate__fadeIn">
      <div class="card-header bg-gradient-primary text-white d-flex align-items-center">
        <span class="mr-2"><i class="fas fa-user-plus fa-lg"></i></span>
        <h4 class="mb-0 ml-2">Complete Your Registration</h4>
      </div>
      <div class="card-body p-4">
        <div class="text-center mb-4"><img src="<?=$logo_url?>" alt="Logo" style="max-height:70px;"></div>
        <?=$page_content?>
      </div>
    </div>
  </div>
</div>
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.full.min.js"></script>
<script src="<?=$base_url?>/assets/registration.js"></script>
<!-- Registration Success Toast -->
<?php if (isset($_SESSION['show_registration_toast']) && $_SESSION['show_registration_toast']): unset($_SESSION['show_registration_toast']); ?>
<div aria-live="polite" aria-atomic="true" style="position: fixed; top: 1rem; right: 1rem; min-width: 320px; z-index: 9999;">
  <div class="toast bg-success text-white" id="regSuccessToast" role="alert" data-delay="6000" style="box-shadow:0 0.5rem 1rem rgba(0,0,0,0.15);">
    <div class="toast-header bg-success text-white">
      <i class="fas fa-check-circle mr-2"></i>
      <strong class="mr-auto">Registration Complete</strong>
      <small>Now</small>
      <button type="button" class="ml-2 mb-1 close text-white" data-dismiss="toast" aria-label="Close" style="outline:none;">
        <span aria-hidden="true">&times;</span>
      </button>
    </div>
    <div class="toast-body">
      Registration complete! Your CRN has been sent to your phone via SMS.
    </div>
  </div>
</div>
<script>
$(function(){
  $('#regSuccessToast').toast('show');
});
</script>
<?php endif; ?>
</body>
</html>

