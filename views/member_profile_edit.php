<?php
require_once __DIR__.'/../includes/member_auth.php';
require_once __DIR__.'/../helpers/spouse_link_helper.php';
if (file_exists(__DIR__.'/../helpers/permissions_v2.php')) {
    require_once __DIR__.'/../helpers/permissions_v2.php';
}

// Only members can edit their own profile
if (!isset($_SESSION['member_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
// Prevent admins/managers from editing via this page
if (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1) {
    header('Location: member_form.php?id=' . intval($_SESSION['member_id']));
    exit;
}
if (function_exists('has_permission') && has_permission('manage_members')) {
    header('Location: member_form.php?id=' . intval($_SESSION['member_id']));
    exit;
}
$member_id = intval($_SESSION['member_id']);

// Fetch member data
$stmt = $conn->prepare('SELECT * FROM members WHERE id = ?');
$stmt->bind_param('i', $member_id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();

// Fetch organizations
$orgs = $conn->query('SELECT * FROM organizations ORDER BY name ASC')->fetch_all(MYSQLI_ASSOC);
$member_orgs = [];
$res = $conn->query('SELECT organization_id FROM member_organizations WHERE member_id = '.$member_id);
while ($row = $res->fetch_assoc()) $member_orgs[] = $row['organization_id'];

// Fetch emergency contacts
$emergency_contacts = [];
$res = $conn->query('SELECT * FROM member_emergency_contacts WHERE member_id = '.$member_id);
while ($row = $res->fetch_assoc()) $emergency_contacts[] = $row;

$spouse_name_value = trim((string) ($member['spouse_name'] ?? ''));
$spouse_crn_value = trim((string) ($member['spouse_crn'] ?? ''));
$marriage_type_value = trim((string) ($member['marriage_type'] ?? ''));
$marital_status_value = trim((string) ($member['marital_status'] ?? ''));
$is_married_selected = strtolower($marital_status_value) === 'married';
$show_spouse_fields = $is_married_selected || $spouse_crn_value !== '' || $spouse_name_value !== '' || $marriage_type_value !== '';
$spouse_initial_value = '';
$spouse_initial_label = '';
if ($spouse_crn_value !== '') {
    $spouse_initial_value = $spouse_crn_value;
    $spouse_initial_label = $spouse_name_value !== '' ? ($spouse_name_value . ' (' . $spouse_crn_value . ')') : $spouse_crn_value;
} elseif ($spouse_name_value !== '') {
    $spouse_initial_value = $spouse_name_value;
    $spouse_initial_label = $spouse_name_value;
}

function bind_member_profile_edit_params(mysqli_stmt $stmt, string $types, array &$values): bool
{
    $refs = [];
    $refs[] = $types;
    foreach ($values as $idx => $_) {
        $refs[] = &$values[$idx];
    }
    return (bool) call_user_func_array([$stmt, 'bind_param'], $refs);
}

$success = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $update_password = false;
    $password_hash = $member['password_hash'] ?? '';
    if (!empty($password)) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $update_password = true;
    }
    // Gather all fields
    $sms_notifications_enabled = isset($_POST['sms_notifications_enabled']) ? 1 : 0;
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
    $marriage_type = trim((string) ($_POST['marriage_type'] ?? ''));
    $spouse_crn = trim((string) ($_POST['spouse_crn'] ?? ''));
    $spouse_name = trim((string) ($_POST['spouse_name'] ?? ''));
    $home_town = trim($_POST['home_town'] ?? '');
    $region = trim($_POST['region'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $employment_status = $_POST['employment_status'] ?? '';
    $profession = trim($_POST['profession'] ?? '');
    $organizations = $_POST['organizations'] ?? [];
    $emergency_contacts_post = $_POST['emergency_contacts'] ?? [];
    // Always start with the current DB value
    $photo = $member['photo'] ?? '';
    $photo_data = $_POST['photo_data'] ?? '';
    if ($photo_data && strpos($photo_data, 'data:image') === 0) {
        $img_parts = explode(',', $photo_data);
        if (count($img_parts) === 2) {
            $img_base64 = base64_decode($img_parts[1]);
            $filename = uniqid('member_').'.png';
            $dest = __DIR__.'/../uploads/members/'.$filename;
            file_put_contents($dest, $img_base64);
            $photo = $filename;
        }
    } else if (isset($_FILES['photo']) && isset($_FILES['photo']['tmp_name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK && is_uploaded_file($_FILES['photo']['tmp_name'])) {
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
    $allowed_marriage_types = ['Customary', 'Ordinance', 'Blessing', 'Court Registration'];
    if ($marital_status !== 'Married') {
        $marriage_type = '';
        $spouse_crn = '';
        $spouse_name = '';
    } elseif (!in_array($marriage_type, $allowed_marriage_types, true)) {
        $marriage_type = '';
    }

    $spouse_name_value = $spouse_name;
    $spouse_crn_value = $spouse_crn;
    $marriage_type_value = $marriage_type;
    $marital_status_value = $marital_status;
    if ($spouse_crn_value !== '') {
        $spouse_initial_value = $spouse_crn_value;
        $spouse_initial_label = $spouse_name_value !== '' ? ($spouse_name_value . ' (' . $spouse_crn_value . ')') : $spouse_crn_value;
    } elseif ($spouse_name_value !== '') {
        $spouse_initial_value = $spouse_name_value;
        $spouse_initial_label = $spouse_name_value;
    } else {
        $spouse_initial_value = '';
        $spouse_initial_label = '';
    }

    // If no new photo is uploaded or captured, $photo remains as the DB value
    $valid_contacts = array_filter($emergency_contacts_post, function($c) {
        return !empty($c['name']) && !empty($c['mobile']) && !empty($c['relationship']);
    });
    if (!$first_name || !$last_name || !$gender || !$dob || !$place_of_birth || !$home_town || !$region || !$phone || count($valid_contacts) === 0 || !$employment_status || ($marital_status === 'Married' && $marriage_type === '')) {
        $error = 'Please fill in all required fields (at least one emergency contact).';
    } else {
        $update_pairs = [
            ['first_name', $first_name],
            ['middle_name', $middle_name],
            ['last_name', $last_name],
            ['gender', $gender],
            ['dob', $dob],
            ['day_born', $day_born],
            ['place_of_birth', $place_of_birth],
            ['address', $address],
            ['gps_address', $gps_address],
            ['marital_status', $marital_status],
            ['spouse_crn', $spouse_crn],
            ['spouse_name', $spouse_name],
            ['marriage_type', $marriage_type],
            ['home_town', $home_town],
            ['region', $region],
            ['phone', $phone],
            ['telephone', $telephone],
            ['email', $email],
            ['employment_status', $employment_status],
            ['profession', $profession],
            ['photo', $photo],
        ];
        if ($update_password) {
            $update_pairs[] = ['password_hash', $password_hash];
        }

        $set_sql = [];
        $update_values = [];
        foreach ($update_pairs as [$column, $value]) {
            $set_sql[] = $column . ' = ?';
            $update_values[] = $value;
        }
        $update_sql = 'UPDATE members SET ' . implode(', ', $set_sql) . ' WHERE id = ?';
        $stmt = $conn->prepare($update_sql);
        $types = str_repeat('s', count($update_values)) . 'i';
        $update_values[] = $member_id;
        bind_member_profile_edit_params($stmt, $types, $update_values);

        if ($stmt->execute()) {
            // Update SMS notifications opt-in/out
            $stmt_sms = $conn->prepare('UPDATE members SET sms_notifications_enabled = ? WHERE id = ?');
            $stmt_sms->bind_param('ii', $sms_notifications_enabled, $member_id);
            $stmt_sms->execute();
            // Emergency Contacts: Remove all old, insert all new
            $conn->query("DELETE FROM member_emergency_contacts WHERE member_id = $member_id");
            if (!empty($valid_contacts)) {
                $ec_stmt = $conn->prepare("INSERT INTO member_emergency_contacts (member_id, name, mobile, relationship) VALUES (?, ?, ?, ?)");
                foreach ($valid_contacts as $c) {
                    $ec_stmt->bind_param('isss', $member_id, $c['name'], $c['mobile'], $c['relationship']);
                    $ec_stmt->execute();
                }
                $ec_stmt->close();
            }
            // Organizations: Remove all old, insert all new
            $conn->query("DELETE FROM member_organizations WHERE member_id = $member_id");
            if (!empty($organizations)) {
                $org_stmt = $conn->prepare("INSERT INTO member_organizations (member_id, organization_id) VALUES (?, ?)");
                foreach ($organizations as $org_id) {
                    $org_stmt->bind_param('ii', $member_id, $org_id);
                    $org_stmt->execute();
                }
                $org_stmt->close();
            }
            if ($marital_status === 'Married' && $spouse_crn !== '') {
                spouse_link_create_request_by_crn($conn, (int) $member_id, (string) $spouse_crn);
            }
            header('Location: ' . BASE_URL . '/views/member_profile.php');
            exit;
        } else {
            $error = 'Failed to update profile.';
        }
    }
}

ob_start();
?>
<!--<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Edit My Profile</h1>
</div> -->
<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="card shadow mb-4">
            <div class="card-header py-3 bg-primary text-white">
                <h6 class="m-0 font-weight-bold">Member Profile</h6>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger mb-4"> <?= htmlspecialchars($error) ?> </div>
                <?php elseif ($success): ?>
                    <div class="alert alert-success mb-4"> <?= htmlspecialchars($success) ?> </div>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data" autocomplete="off">
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label>CRN</label>
                            <input type="text" class="form-control" value="<?=htmlspecialchars($member['crn'])?>" readonly>
                        </div>
                        <div class="form-group col-md-4">
                            <label for="photo">Picture</label>
                            <?php if (!empty($member['photo'])): ?>
                                <div class="mb-2 p-2 bg-white border rounded" style="display:inline-block;">
    <img src="<?= !empty($member['photo']) && file_exists(__DIR__.'/../uploads/members/' . $member['photo']) ? BASE_URL . '/uploads/members/' . rawurlencode($member['photo']) . '?v=' . time() : BASE_URL . '/assets/img/undraw_profile.svg' ?>" alt="Photo" style="height:70px;width:70px;object-fit:cover;border-radius:8px;box-shadow:0 2px 6px rgba(0,0,0,0.07);">
</div>
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
                            <label for="password">Change Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="password" id="password" minlength="6" autocomplete="new-password" placeholder="Leave blank to keep current">
                                <div class="input-group-append">
                                    <span class="input-group-text toggle-password" style="cursor:pointer;"><i class="fa fa-eye"></i></span>
                                </div>
                            </div>
                            <small class="form-text text-muted">Password will be used to log in with your CRN as username.</small>
                        </div>
                    </div>
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
                        <div class="form-group col-md-4">
                            <label for="last_name">Surname <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="last_name" id="last_name" value="<?=htmlspecialchars($member['last_name'])?>" required>
                        </div>
                        <div class="form-group col-md-4">
                            <label for="first_name">First Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="first_name" id="first_name" value="<?=htmlspecialchars($member['first_name'])?>" required>
                        </div>
                        <div class="form-group col-md-4 d-flex align-items-center" style="margin-top:32px;">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="sms_notifications_enabled" id="sms_notifications_enabled" value="1" <?=!isset($member['sms_notifications_enabled']) || $member['sms_notifications_enabled'] ? 'checked' : ''?> />
                                <label class="form-check-label" for="sms_notifications_enabled">
                                    Receive Payment SMS Notifications
                                </label>
                            </div>
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
                                <option value="">Select</option>
                                <option value="Single" <?=strtolower($marital_status_value)==='single'?'selected':''?>>Single</option>
                                <option value="Married" <?=strtolower($marital_status_value)==='married'?'selected':''?>>Married</option>
                                <option value="Divorced" <?=strtolower($marital_status_value)==='divorced'?'selected':''?>>Divorced</option>
                                <option value="Widowed" <?=strtolower($marital_status_value)==='widowed'?'selected':''?>>Widowed</option>
                            </select>
                        </div>
                        <div class="form-group col-md-4" id="spouse-group" style="<?= $show_spouse_fields ? '' : 'display:none;' ?>">
                            <label for="spouse_crn">Spouse Name or CRN</label>
                            <select class="form-control" name="spouse_crn" id="spouse_crn">
                                <option value="">-- Search by CRN/name/phone or type name --</option>
                                <?php if ($spouse_initial_value !== ''): ?>
                                    <option value="<?= htmlspecialchars($spouse_initial_value) ?>" selected><?= htmlspecialchars($spouse_initial_label) ?></option>
                                <?php endif; ?>
                            </select>
                            <input type="hidden" name="spouse_name" id="spouse_name" value="<?= htmlspecialchars($spouse_name_value) ?>">
                            <small class="form-text text-muted">Selecting an existing member sends a spouse approval request.</small>
                        </div>
                        <div class="form-group col-md-4" id="marriage-type-group" style="<?= $show_spouse_fields ? '' : 'display:none;' ?>">
                            <label for="marriage_type">Nature of Marriage <span class="text-danger">*</span></label>
                            <select class="form-control" name="marriage_type" id="marriage_type">
                                <option value="">-- Select --</option>
                                <option value="Customary" <?=$marriage_type_value==='Customary'?'selected':''?>>Customary</option>
                                <option value="Ordinance" <?=$marriage_type_value==='Ordinance'?'selected':''?>>Ordinance</option>
                                <option value="Blessing" <?=$marriage_type_value==='Blessing'?'selected':''?>>Blessing</option>
                                <option value="Court Registration" <?=$marriage_type_value==='Court Registration'?'selected':''?>>Court Registration</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label for="home_town">Home Town <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="home_town" id="home_town" value="<?=htmlspecialchars($member['home_town'])?>" required>
                        </div>
                        <div class="form-group col-md-4">
                            <label for="region">Region <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="region" id="region" value="<?=htmlspecialchars($member['region'])?>" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label for="phone">Mobile <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="phone" id="phone" value="<?=htmlspecialchars($member['phone'])?>" required>
                        </div>
                        <div class="form-group col-md-4">
                            <label for="telephone">Telephone</label>
                            <input type="text" class="form-control" name="telephone" id="telephone" value="<?=htmlspecialchars($member['telephone'])?>">
                        </div>
                        <div class="form-group col-md-4">
                            <label for="email">Email</label>
                            <input type="email" class="form-control" name="email" id="email" value="<?=htmlspecialchars($member['email'])?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label for="employment_status">Employment Status <span class="text-danger">*</span></label>
                            <select class="form-control" name="employment_status" id="employment_status" required>
                                <option value="">Select</option>
                                <?php
                                $employment_options = ["Formal", "Informal", "Self Employed", "Retired", "Student"];
                                $current_employment = $member['employment_status'] ?? '';
                                if ($current_employment && !in_array($current_employment, $employment_options)) {
                                    echo '<option value="'.htmlspecialchars($current_employment).'" selected>'.htmlspecialchars($current_employment).'</option>';
                                }
                                foreach ($employment_options as $opt) {
                                    $selected = ($current_employment == $opt) ? 'selected' : '';
                                    echo '<option value="'.htmlspecialchars($opt).'" '.$selected.'>'.htmlspecialchars($opt).'</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group col-md-4">
                            <label for="profession">Profession</label>
                            <input type="text" class="form-control" name="profession" id="profession" value="<?=htmlspecialchars($member['profession'])?>">
                        </div>
                        <div class="form-group col-md-4">
                            <label for="organizations">Organizations</label>
                            <select class="form-control" name="organizations[]" id="organizations" multiple>
                                <?php foreach ($orgs as $org): ?>
                                    <option value="<?=$org['id']?>" <?=in_array($org['id'], $member_orgs)?'selected':''?>><?=htmlspecialchars($org['name'])?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <hr>
                    <h5>Emergency Contacts <span class="text-danger">*</span></h5>
                    <div id="emergency-contacts-list">
                        <?php
                        $ec_i = 0;
                        if (!empty($emergency_contacts)):
                            foreach ($emergency_contacts as $ec): ?>
                                <div class="form-row emergency-contact-row mb-2">
                                    <div class="form-group col-md-4">
                                        <input type="text" class="form-control" name="emergency_contacts[<?=$ec_i?>][name]" placeholder="Name" value="<?=htmlspecialchars($ec['name'])?>" required>
                                    </div>
                                    <div class="form-group col-md-4">
                                        <input type="text" class="form-control" name="emergency_contacts[<?=$ec_i?>][mobile]" placeholder="Mobile" value="<?=htmlspecialchars($ec['mobile'])?>" required>
                                    </div>
                                    <div class="form-group col-md-3">
                                        <input type="text" class="form-control" name="emergency_contacts[<?=$ec_i?>][relationship]" placeholder="Relationship" value="<?=htmlspecialchars($ec['relationship'])?>" required>
                                    </div>
                                    <div class="form-group col-md-1">
                                        <button type="button" class="btn btn-danger btn-sm remove-ec-btn"><i class="fa fa-trash"></i></button>
                                    </div>
                                </div>
                        <?php $ec_i++; endforeach; else: ?>
                            <div class="form-row emergency-contact-row mb-2">
                                <div class="form-group col-md-4">
                                    <input type="text" class="form-control" name="emergency_contacts[0][name]" placeholder="Name" required>
                                </div>
                                <div class="form-group col-md-4">
                                    <input type="text" class="form-control" name="emergency_contacts[0][mobile]" placeholder="Mobile" required>
                                </div>
                                <div class="form-group col-md-3">
                                    <input type="text" class="form-control" name="emergency_contacts[0][relationship]" placeholder="Relationship" required>
                                </div>
                                <div class="form-group col-md-1">
                                    <button type="button" class="btn btn-danger btn-sm remove-ec-btn"><i class="fa fa-trash"></i></button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn btn-info btn-sm mb-3" id="add-ec-btn"><i class="fa fa-plus"></i> Add Emergency Contact</button>
                    <button type="submit" class="btn btn-primary btn-block">Save Changes</button>
                    <a href="<?php echo BASE_URL; ?>/views/member_profile.php" class="btn btn-secondary btn-block">Cancel</a>
                </form>
            </div>
        </div>
    </div>
</div>
<?php $page_content = ob_get_clean(); include '../includes/layout.php'; ?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.full.min.js"></script>
<!-- Camera Modal -->
<div class="modal fade" id="cameraModal" tabindex="-1" role="dialog" aria-labelledby="cameraModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="cameraModalLabel">Take a Photo</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body text-center">
        <video id="camera-video" width="100%" height="240" autoplay style="border-radius:8px; background:#222;"></video>
        <canvas id="camera-canvas" style="display:none;"></canvas>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" id="capture-btn"><i class="fa fa-camera"></i> Capture</button>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
      </div>
    </div>
  </div>
</div>

<script>
$(function(){
    function normalizeBaseUrl(base) {
        if (typeof base !== 'string') return '';
        base = base.trim();
        if (base === '' || base === '/') return '';
        return base.replace(/\/+$/, '');
    }
    function joinBasePath(base, path) {
        var cleanBase = normalizeBaseUrl(base);
        var cleanPath = (path || '').replace(/^\/+/, '');
        return (cleanBase ? cleanBase + '/' : '/') + cleanPath;
    }
    var BASE = normalizeBaseUrl('<?= htmlspecialchars((string) BASE_URL, ENT_QUOTES, 'UTF-8') ?>');

    // Emergency contacts dynamic add/remove
    let ecIndex = $('#emergency-contacts-list .emergency-contact-row').length;
    $('#add-ec-btn').on('click', function(){
        let html = `<div class="form-row emergency-contact-row mb-2">
            <div class="form-group col-md-4">
                <input type="text" class="form-control" name="emergency_contacts[${ecIndex}][name]" placeholder="Name" required>
            </div>
            <div class="form-group col-md-4">
                <input type="text" class="form-control" name="emergency_contacts[${ecIndex}][mobile]" placeholder="Mobile" required>
            </div>
            <div class="form-group col-md-3">
                <input type="text" class="form-control" name="emergency_contacts[${ecIndex}][relationship]" placeholder="Relationship" required>
            </div>
            <div class="form-group col-md-1">
                <button type="button" class="btn btn-danger btn-sm remove-ec-btn"><i class="fa fa-trash"></i></button>
            </div>
        </div>`;
        $('#emergency-contacts-list').append(html);
        ecIndex++;
    });
    $(document).on('click', '.remove-ec-btn', function(){
        $(this).closest('.emergency-contact-row').remove();
    });

    // Spouse search + marriage fields
    function toggleMarriageFields() {
        if ($('#marital_status').val() === 'Married') {
            $('#spouse-group').show();
            $('#marriage-type-group').show();
            $('#marriage_type').prop('required', true);
        } else {
            $('#spouse-group').hide();
            $('#marriage-type-group').hide();
            $('#marriage_type').prop('required', false).val('');
            $('#spouse_name').val('');
            if ($('#spouse_crn').data('select2')) {
                $('#spouse_crn').val(null).trigger('change');
            } else {
                $('#spouse_crn').val('');
            }
        }
    }

    if ($('#spouse_crn').length) {
        $('#spouse_crn').select2({
            placeholder: 'Search by CRN, name, or phone',
            allowClear: true,
            width: '100%',
            tags: true,
            minimumInputLength: 2,
            ajax: {
                url: joinBasePath(BASE, '/api/search_member_registration.php'),
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return { q: params.term };
                },
                processResults: function (data) {
                    return { results: data.results || [] };
                }
            }
        });

        $('#spouse_crn').on('select2:select', function (e) {
            var data = e.params.data || {};
            if (data.full_name) {
                $('#spouse_name').val(data.full_name);
            } else {
                $('#spouse_name').val(data.text || data.id || '');
            }
        });

        $('#spouse_crn').on('select2:clear', function () {
            $('#spouse_name').val('');
        });
    }

    $('#marital_status').on('change', toggleMarriageFields);
    toggleMarriageFields();

    // Camera/photo preview logic (reuse from complete_registration.js if needed)
    $('#camera-btn').on('click', function(e){
        e.preventDefault();
        $('#cameraModal').modal('show');
        startCamera();
    });

    function startCamera() {
        const video = document.getElementById('camera-video');
        if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            navigator.mediaDevices.getUserMedia({ video: true })
                .then(function(stream) {
                    video.srcObject = stream;
                    video.play();
                })
                .catch(function(err) {
                    alert('Camera not accessible: ' + err.message);
                });
        } else {
            alert('Camera not supported in this browser.');
        }
    }

    $('#capture-btn').on('click', function(){
        const video = document.getElementById('camera-video');
        const canvas = document.getElementById('camera-canvas');
        const context = canvas.getContext('2d');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        context.drawImage(video, 0, 0, canvas.width, canvas.height);
        const dataUrl = canvas.toDataURL('image/png');
        $('#photo-preview').attr('src', dataUrl);
        $('#photo-preview-wrap').show();
        $('#photo-data').val(dataUrl);
        // Stop camera
        if (video.srcObject) {
            video.srcObject.getTracks().forEach(track => track.stop());
            video.srcObject = null;
        }
        $('#cameraModal').modal('hide');
    });

    $('#cameraModal').on('hidden.bs.modal', function(){
        const video = document.getElementById('camera-video');
        if (video.srcObject) {
            video.srcObject.getTracks().forEach(track => track.stop());
            video.srcObject = null;
        }
    });
    $('#photo').on('change', function(){
        if (this.files && this.files[0]) {
            let reader = new FileReader();
            reader.onload = function(e) {
                $('#photo-preview').attr('src', e.target.result);
                $('#photo-preview-wrap').show();
            }
            reader.readAsDataURL(this.files[0]);
        }
    });
    $('#remove-photo-btn').on('click', function(){
        $('#photo').val('');
        $('#photo-preview').attr('src', '#');
        $('#photo-preview-wrap').hide();
    });
    // Toggle password view
    $('.toggle-password').on('click', function(){
        let input = $(this).closest('.input-group').find('input');
        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            $(this).find('i').removeClass('fa-eye').addClass('fa-eye-slash');
        } else {
            input.attr('type', 'password');
            $(this).find('i').removeClass('fa-eye-slash').addClass('fa-eye');
        }
    });
});
</script>

