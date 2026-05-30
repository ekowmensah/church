<?php
ob_start();
session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/bible_class_capacity.php';
require_once __DIR__.'/../helpers/spouse_link_helper.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$is_super_admin = (isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] === 3)
    || (isset($_SESSION['role_id']) && (int) $_SESSION['role_id'] === 1);
if (!$is_super_admin && !has_permission('edit_member')) {
    http_response_code(403);
    if (file_exists(__DIR__.'/errors/403.php')) {
        include __DIR__.'/errors/403.php';
    } elseif (file_exists(dirname(__DIR__).'/views/errors/403.php')) {
        include dirname(__DIR__).'/views/errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to edit member profiles.</p></div>';
    }
    exit;
}

function sync_member_user_account(mysqli $conn, int $member_id, string $full_name, string $email, string $phone, string $password_hash, string $photo, int $church_id): void
{
    $user_id = 0;

    // Prefer exact phone match first to avoid hitting unique-phone conflicts when
    // a member has multiple legacy user rows.
    if ($phone !== '') {
        $stmt = $conn->prepare('SELECT id, member_id FROM users WHERE phone = ? LIMIT 1');
        $stmt->bind_param('s', $phone);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $matched_member_id = isset($row['member_id']) ? (int) $row['member_id'] : 0;
            if ($matched_member_id > 0 && $matched_member_id !== $member_id) {
                throw new Exception('Phone number is already linked to another member account.');
            }
            $user_id = (int) $row['id'];
        }
        $stmt->close();
    }

    if ($user_id <= 0) {
        $stmt = $conn->prepare('SELECT id FROM users WHERE member_id = ? ORDER BY id ASC LIMIT 1');
        $stmt->bind_param('i', $member_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $user_id = (int) $row['id'];
        }
        $stmt->close();
    }

    if ($user_id <= 0 && $email !== '') {
        $stmt = $conn->prepare('SELECT id, member_id FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $matched_member_id = isset($row['member_id']) ? (int) $row['member_id'] : 0;
            if ($matched_member_id > 0 && $matched_member_id !== $member_id) {
                throw new Exception('Email is already linked to another member account.');
            }
            $user_id = (int) $row['id'];
        }
        $stmt->close();
    }

    if ($user_id > 0) {
        $stmt = $conn->prepare('UPDATE users SET member_id = ?, church_id = ?, name = ?, email = ?, phone = ?, password_hash = ?, status = \'active\', photo = ? WHERE id = ?');
        $stmt->bind_param('iisssssi', $member_id, $church_id, $full_name, $email, $phone, $password_hash, $photo, $user_id);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $user_status = 'active';
    $stmt = $conn->prepare('INSERT INTO users (member_id, church_id, name, email, phone, password_hash, status, photo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('iissssss', $member_id, $church_id, $full_name, $email, $phone, $password_hash, $user_status, $photo);
    $stmt->execute();
    $stmt->close();
}

function normalize_html_date(?string $dateValue): string
{
    $dateValue = trim((string) $dateValue);
    if ($dateValue === '' || $dateValue === '0000-00-00') {
        return '';
    }

    $date = DateTime::createFromFormat('Y-m-d', $dateValue);
    if ($date instanceof DateTime && $date->format('Y-m-d') === $dateValue) {
        return $dateValue;
    }

    return '';
}

function normalize_nullable_date_for_db($dateValue): ?string
{
    $dateValue = trim((string) $dateValue);
    if ($dateValue === '' || $dateValue === '0000-00-00') {
        return null;
    }

    $date = DateTime::createFromFormat('Y-m-d', $dateValue);
    if ($date instanceof DateTime && $date->format('Y-m-d') === $dateValue) {
        return $dateValue;
    }

    return null;
}

function get_members_table_columns(mysqli $conn): array
{
    static $columns = null;
    if (is_array($columns)) {
        return $columns;
    }

    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM members');
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if (isset($row['Field'])) {
                $columns[(string) $row['Field']] = true;
            }
        }
        $result->free();
    }

    return $columns;
}

function get_members_enum_values(mysqli $conn, string $columnName): array
{
    static $enumCache = [];
    if (isset($enumCache[$columnName])) {
        return $enumCache[$columnName];
    }

    $values = [];
    $stmt = $conn->prepare("
        SELECT COLUMN_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'members'
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('s', $columnName);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        $columnType = (string) ($row['COLUMN_TYPE'] ?? '');
        if (preg_match("/^enum\\((.*)\\)$/i", $columnType, $m)) {
            $rawItems = str_getcsv($m[1], ',', "'");
            foreach ($rawItems as $item) {
                $item = trim((string) $item);
                if ($item !== '') {
                    $values[] = $item;
                }
            }
        }
    }

    $enumCache[$columnName] = $values;
    return $values;
}

function bind_dynamic_params(mysqli_stmt $stmt, string $types, array &$values): bool
{
    $refs = [];
    $refs[] = $types;
    foreach ($values as $idx => $_) {
        $refs[] = &$values[$idx];
    }

    return (bool) call_user_func_array([$stmt, 'bind_param'], $refs);
}

//require_once __DIR__.'/../includes/header.php';
//require_once __DIR__.'/../includes/sidebar.php';


// Ensure BASE_URL is always set before any output
$script_name = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);
$base_url = rtrim(dirname(dirname($script_name)), '/\\');
if ($base_url === '/' || $base_url === '' || $base_url === '.') $base_url = '';
$logo_url = $base_url . '/assets/logo.png';

    $member_id = isset($_GET['id']) ? intval($_GET['id']) : intval($_POST['id'] ?? 0);
$is_admin_edit = (string) ($_GET['admin_edit'] ?? $_POST['admin_edit'] ?? '0') === '1';
$page_title = $is_admin_edit ? 'Admin: Edit Member Profile' : 'Admin: Complete Member Registration';
$submit_label = $is_admin_edit ? 'Save Profile Changes' : 'Complete Registration';
$error = '';
$success = '';
$member = null;
$is_password_required = !$is_admin_edit;
if ($member_id > 0) {
    $member_lookup_sql = $is_admin_edit
        ? 'SELECT * FROM members WHERE id = ? LIMIT 1'
        : 'SELECT * FROM members WHERE id = ? AND status = "pending" LIMIT 1';
    $stmt = $conn->prepare($member_lookup_sql);
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $member = $result->fetch_assoc();
    $stmt->close();
    if (!$member) {
        $error = $is_admin_edit ? 'Member not found.' : 'Invalid or expired registration link.';
    } elseif ($is_admin_edit) {
        $is_password_required = ((string) ($member['password_hash'] ?? '')) === '';
    }
} else {
    $error = 'Missing member ID.';
}

$relationship_options = [];
$relationship_defaults = ['Husband', 'Wife', 'Son', 'Daughter', 'Mother', 'Father', 'Brother', 'Sister', 'Uncle', 'Auntie', 'Grandfather', 'Grandmother', 'Other'];
$relationship_query = $conn->query("SELECT name FROM relationship_types WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
if ($relationship_query) {
    while ($relationship_row = $relationship_query->fetch_assoc()) {
        $relationship_name = trim((string) ($relationship_row['name'] ?? ''));
        if ($relationship_name !== '') {
            $relationship_options[] = $relationship_name;
        }
    }
}
if (empty($relationship_options)) {
    $relationship_options = $relationship_defaults;
}
$relationship_options = array_values(array_unique($relationship_options));
$relationship_options_html = '<option value="">-- Select Relationship --</option>';
foreach ($relationship_options as $relationship_option) {
    $safe_relationship_option = htmlspecialchars($relationship_option, ENT_QUOTES, 'UTF-8');
    $relationship_options_html .= '<option value="' . $safe_relationship_option . '">' . $safe_relationship_option . '</option>';
}

$form_emergency_contacts = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_contacts = $_POST['emergency_contacts'] ?? [];
    if (is_array($posted_contacts)) {
        foreach ($posted_contacts as $posted_contact) {
            if (!is_array($posted_contact)) {
                continue;
            }
            $entry = [
                'crn' => trim((string) ($posted_contact['crn'] ?? '')),
                'name' => trim((string) ($posted_contact['name'] ?? '')),
                'mobile' => trim((string) ($posted_contact['mobile'] ?? '')),
                'relationship' => trim((string) ($posted_contact['relationship'] ?? '')),
            ];
            if ($entry['crn'] !== '' || $entry['name'] !== '' || $entry['mobile'] !== '' || $entry['relationship'] !== '') {
                $form_emergency_contacts[] = $entry;
            }
        }
    }
} elseif ($member && $member_id > 0) {
    $emergency_stmt = $conn->prepare('SELECT name, mobile, relationship FROM member_emergency_contacts WHERE member_id = ? ORDER BY id ASC');
    if ($emergency_stmt) {
        $emergency_stmt->bind_param('i', $member_id);
        $emergency_stmt->execute();
        $emergency_result = $emergency_stmt->get_result();
        while ($emergency_result && ($emergency_row = $emergency_result->fetch_assoc())) {
            $form_emergency_contacts[] = [
                'crn' => '',
                'name' => trim((string) ($emergency_row['name'] ?? '')),
                'mobile' => trim((string) ($emergency_row['mobile'] ?? '')),
                'relationship' => trim((string) ($emergency_row['relationship'] ?? '')),
            ];
        }
        $emergency_stmt->close();
    }
}

if (empty($form_emergency_contacts)) {
    $form_emergency_contacts[] = ['crn' => '', 'name' => '', 'mobile' => '', 'relationship' => ''];
}

$spouse_name_value = trim((string) (($_SERVER['REQUEST_METHOD'] === 'POST')
    ? ($_POST['spouse_name'] ?? '')
    : ($member['spouse_name'] ?? '')));
$spouse_crn_value = trim((string) (($_SERVER['REQUEST_METHOD'] === 'POST')
    ? ($_POST['spouse_crn'] ?? '')
    : ($member['spouse_crn'] ?? '')));
$spouse_initial_value = '';
$spouse_initial_label = '';

if ($spouse_crn_value !== '') {
    $spouse_initial_value = $spouse_crn_value;
    $spouse_initial_label = $spouse_name_value !== '' ? ($spouse_name_value . ' (' . $spouse_crn_value . ')') : $spouse_crn_value;
} elseif ($spouse_name_value !== '') {
    $spouse_initial_value = $spouse_name_value;
    $spouse_initial_label = $spouse_name_value;
}

$dob_input_value = normalize_html_date($member['dob'] ?? '');
$date_of_baptism_input_value = normalize_html_date($member['date_of_baptism'] ?? '');
$date_of_confirmation_input_value = normalize_html_date($member['date_of_confirmation'] ?? '');
$date_of_enrollment_input_value = normalize_html_date($member['date_of_enrollment'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $member && $member_id > 0) {
    // Gather all fields
    $affected_rows = -1;
    $members_columns = get_members_table_columns($conn);
    $status_enum_values = get_members_enum_values($conn, 'status');
    $employment_enum_values = get_members_enum_values($conn, 'employment_status');
    $membership_enum_values = get_members_enum_values($conn, 'membership_status');
    $password = $_POST['password'] ?? '';
    $current_password_hash = (string) ($member['password_hash'] ?? '');
    $is_password_required = !$is_admin_edit || $current_password_hash === '';
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
    $marriage_type = trim($_POST['marriage_type'] ?? '');
    $spouse_crn = trim($_POST['spouse_crn'] ?? '');
    $spouse_name = trim($_POST['spouse_name'] ?? '');
    $home_town = trim($_POST['home_town'] ?? '');
    $region = trim($_POST['region'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $employment_status = trim((string) ($_POST['employment_status'] ?? ''));
    if (!empty($employment_enum_values) && !in_array($employment_status, $employment_enum_values, true)) {
        if ($employment_status === 'Unemployed' && in_array('Informal', $employment_enum_values, true)) {
            $employment_status = 'Informal';
        } elseif (isset($member['employment_status']) && in_array((string) $member['employment_status'], $employment_enum_values, true)) {
            $employment_status = (string) $member['employment_status'];
        } else {
            $employment_status = $employment_enum_values[0] ?? '';
        }
    }
    $profession = trim($_POST['profession'] ?? '');
    $occupation = trim($_POST['occupation'] ?? '');
    $baptized = $_POST['baptized'] ?? '';
    $confirmed = $_POST['confirmed'] ?? '';
    $date_of_baptism = normalize_nullable_date_for_db($_POST['date_of_baptism'] ?? null);
    $date_of_confirmation = normalize_nullable_date_for_db($_POST['date_of_confirmation'] ?? null);
    $membership_status = isset($_POST['membership_status']) ? trim((string) $_POST['membership_status']) : '';
    if ($membership_status === '') {
        $membership_status = isset($member['membership_status']) && $member['membership_status'] !== '' ? $member['membership_status'] : null;
    }
    if ($membership_status !== null && !empty($membership_enum_values) && !in_array((string) $membership_status, $membership_enum_values, true)) {
        if (isset($member['membership_status']) && in_array((string) $member['membership_status'], $membership_enum_values, true)) {
            $membership_status = (string) $member['membership_status'];
        } else {
            $membership_status = null;
        }
    }
    $date_of_enrollment = normalize_nullable_date_for_db($_POST['date_of_enrollment'] ?? null);
    $status = $is_admin_edit ? (string) ($member['status'] ?? 'active') : 'active';
    if ($status === '') {
        $status = 'active';
    }
    if (!empty($status_enum_values) && !in_array($status, $status_enum_values, true)) {
        if (in_array('active', $status_enum_values, true)) {
            $status = 'active';
        } else {
            $status = $status_enum_values[0];
        }
    }
    $allowed_marriage_types = ['Customary', 'Ordinance', 'Blessing', 'Court Registration'];
    if ($marital_status !== 'Married') {
        $marriage_type = null;
        $spouse_crn = '';
        $spouse_name = '';
    } elseif (!in_array($marriage_type, $allowed_marriage_types, true)) {
        $marriage_type = '';
    }
    // Org(s) multiple select
    $organizations = array_values(array_filter(array_map('intval', (array) ($_POST['organizations'] ?? [])), static function ($id) {
        return $id > 0;
    }));
    // Roles of Serving multiple select
    $roles_of_serving = array_values(array_filter(array_map('intval', (array) ($_POST['roles_of_serving'] ?? [])), static function ($id) {
        return $id > 0;
    }));
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
    if (!$first_name || !$last_name || !$gender || !$dob || !$place_of_birth || !$marital_status || ($marital_status === 'Married' && !$marriage_type) || !$home_town || !$region || !$phone || count($valid_contacts) === 0 || !$employment_status || !$baptized || !$confirmed || ($is_password_required && !$password)) {
        // Debug output for troubleshooting
        error_log('DEBUG: valid_contacts count: ' . count($valid_contacts));
        error_log('DEBUG: emergency_contacts: ' . print_r($emergency_contacts, true));
        $error = 'Please fill in all required fields (at least one emergency contact).';
    } else {
        // Enforce phone uniqueness at member level.
        $stmt_phone_member = $conn->prepare('SELECT id, crn, first_name, last_name FROM members WHERE phone = ? AND id <> ? LIMIT 1');
        if ($stmt_phone_member) {
            $stmt_phone_member->bind_param('si', $phone, $member_id);
            $stmt_phone_member->execute();
            $res_phone_member = $stmt_phone_member->get_result();
            $phone_conflict_member = $res_phone_member ? $res_phone_member->fetch_assoc() : null;
            $stmt_phone_member->close();
            if ($phone_conflict_member) {
                $conflict_name = trim((string) (($phone_conflict_member['first_name'] ?? '') . ' ' . ($phone_conflict_member['last_name'] ?? '')));
                $conflict_crn = trim((string) ($phone_conflict_member['crn'] ?? ''));
                $error = 'Phone number already belongs to another member'
                    . ($conflict_name !== '' ? ' (' . $conflict_name . ')' : '')
                    . ($conflict_crn !== '' ? ' - CRN: ' . $conflict_crn : '')
                    . '.';
            }
        }

        // Enforce phone uniqueness at login-account level as well.
        if (!$error) {
            $stmt_phone_user = $conn->prepare('SELECT id, member_id FROM users WHERE phone = ? LIMIT 1');
            if ($stmt_phone_user) {
                $stmt_phone_user->bind_param('s', $phone);
                $stmt_phone_user->execute();
                $res_phone_user = $stmt_phone_user->get_result();
                $phone_conflict_user = $res_phone_user ? $res_phone_user->fetch_assoc() : null;
                $stmt_phone_user->close();
                if ($phone_conflict_user) {
                    $existing_user_member_id = isset($phone_conflict_user['member_id']) ? (int) $phone_conflict_user['member_id'] : 0;
                    if ($existing_user_member_id > 0 && $existing_user_member_id !== $member_id) {
                        $error = 'Phone number is already linked to another member account. Use a different number.';
                    }
                }
            }
        }

        if ($error) {
            // stop further checks
        } else {
        $target_class_id = (int) ($member['class_id'] ?? 0);
        $capacity = bible_class_validate_capacity($conn, $target_class_id, $member_id);
        if (!$capacity['allowed']) {
            $error = 'Registration cannot be completed: ' . bible_class_capacity_error_message();
        }
        }
    }

    if (!$error) {
        $password_hash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : $current_password_hash;
        try {
            $conn->begin_transaction();

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
                ['occupation', $occupation],
                ['baptized', $baptized],
                ['confirmed', $confirmed],
                ['date_of_baptism', $date_of_baptism],
                ['date_of_confirmation', $date_of_confirmation],
                ['membership_status', $membership_status],
                ['date_of_enrollment', $date_of_enrollment],
                ['photo', $photo],
                ['status', $status],
                ['password_hash', $password_hash],
            ];

            $update_sql_parts = [];
            $member_update_values = [];
            $member_update_types = '';
            foreach ($update_pairs as [$column, $value]) {
                if (!isset($members_columns[$column])) {
                    continue;
                }
                $update_sql_parts[] = $column . ' = ?';
                $member_update_values[] = $value;
                $member_update_types .= 's';
            }

            if (!$is_admin_edit && isset($members_columns['registration_token'])) {
                $update_sql_parts[] = 'registration_token = NULL';
            }

            if (empty($update_sql_parts)) {
                throw new Exception('No updatable members columns found for this schema.');
            }

            $member_update_sql = 'UPDATE members SET ' . implode(', ', $update_sql_parts) . ' WHERE id = ?';
            $stmt = $conn->prepare($member_update_sql);
            if (!$stmt) {
                throw new Exception($conn->error ?: 'Failed to prepare member update statement.');
            }

            $member_update_types .= 'i';
            $member_update_values[] = $member_id;
            if (!bind_dynamic_params($stmt, $member_update_types, $member_update_values)) {
                throw new Exception($stmt->error ?: 'Failed to bind member update params.');
            }
            if (!$stmt->execute()) {
                throw new Exception($stmt->error ?: 'Failed to update member during registration.');
            }
            $affected_rows = $stmt->affected_rows;
            $stmt->close();

            // Update emergency contacts (delete old, insert new)
            $conn->query("DELETE FROM member_emergency_contacts WHERE member_id=" . $member_id);
            if (!empty($valid_contacts)) {
                $ec_stmt = $conn->prepare("INSERT INTO member_emergency_contacts (member_id, name, mobile, relationship) VALUES (?, ?, ?, ?)");
                foreach ($valid_contacts as $c) {
                    $contact_name = $c['name'];
                    $contact_mobile = $c['mobile'];
                    $contact_relationship = $c['relationship'];
                    $ec_stmt->bind_param('isss', $member_id, $contact_name, $contact_mobile, $contact_relationship);
                    $ec_stmt->execute();
                }
                $ec_stmt->close();
            }

            // Admin completion assigns organizations directly.
            $conn->query("DELETE FROM member_organizations WHERE member_id=" . $member_id);
            if (!empty($organizations)) {
                $org_stmt = $conn->prepare("INSERT INTO member_organizations (member_id, organization_id) VALUES (?, ?)");
                foreach ($organizations as $org_id) {
                    $organization_id = (int) $org_id;
                    $org_stmt->bind_param('ii', $member_id, $organization_id);
                    $org_stmt->execute();
                }
                $org_stmt->close();
            }

            // Update roles of serving (delete old, insert new)
            $conn->query("DELETE FROM member_roles_of_serving WHERE member_id=" . $member_id);
            if (!empty($roles_of_serving)) {
                $role_stmt = $conn->prepare("INSERT INTO member_roles_of_serving (member_id, role_id) VALUES (?, ?)");
                foreach ($roles_of_serving as $role_id) {
                    $serving_role_id = (int) $role_id;
                    $role_stmt->bind_param('ii', $member_id, $serving_role_id);
                    $role_stmt->execute();
                }
                $role_stmt->close();
            }

            $full_name = trim(preg_replace('/\s+/', ' ', $first_name . ' ' . $middle_name . ' ' . $last_name));
            $church_id = isset($member['church_id']) ? (int) $member['church_id'] : 0;
            sync_member_user_account($conn, $member_id, $full_name, $email, $phone, $password_hash, $photo, $church_id);

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('Admin complete registration DB sync failed: ' . $e->getMessage());
            if (is_bible_class_capacity_error($e->getMessage())) {
                $error = 'Registration cannot be completed: ' . bible_class_capacity_error_message();
            } elseif ($is_admin_edit) {
                $error = 'Database error: ' . $e->getMessage();
            } else {
                $error = 'Database error. Please try again.';
            }
            $affected_rows = -1;
        }
    }

    if ($affected_rows >= 0 && !$error) {
        $spouse_request_notice = '';
        if (
            isset($marital_status, $spouse_crn)
            && $marital_status === 'Married'
            && trim((string) $spouse_crn) !== ''
        ) {
            $spouse_request_result = spouse_link_create_request_by_crn($conn, (int) $member_id, (string) $spouse_crn);
            if (!empty($spouse_request_result['ok']) && in_array(($spouse_request_result['status'] ?? ''), ['created', 'pending_exists'], true)) {
                $spouse_request_notice = ' ' . ($spouse_request_result['message'] ?? '');
            }
        }

        if ($is_admin_edit) {
            $success = 'Member profile updated successfully.' . $spouse_request_notice;
            echo '<script>setTimeout(function(){ window.location.href = "member_list.php"; }, 1500);</script>';
        } else {
            require_once __DIR__.'/../includes/sms.php';
            $sms_sent = true;
            $sms_message = "Dear {$first_name}, your registration is complete. CRN: {$member['crn']}, Password: {$password}";
            try {
                send_sms($phone, $sms_message);
            } catch (Throwable $ex) {
                $sms_sent = false;
                error_log('Admin registration SMS send failed: ' . $ex->getMessage());
            }
            $success = $sms_sent
                ? 'Registration complete! SMS sent to member.'
                : 'Registration complete! SMS could not be sent right now.';
            $success .= $spouse_request_notice;
            echo '<script>setTimeout(function(){ window.location.href = "register_member.php"; }, 2000);</script>';
        }
    } elseif (!$error) {
        $error = 'Database error. Please try again.';
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
                <h6 class="m-0 font-weight-bold"><?= htmlspecialchars($page_title) ?></h6>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger mb-4"> <?= htmlspecialchars($error) ?> </div>
                <?php elseif ($success): ?>
                    <div class="alert alert-success mb-4"> <?= htmlspecialchars($success) ?> </div>
                <?php endif; ?>
                <?php if ($member && !$success): ?>
                <form method="post" action="<?= htmlspecialchars('complete_registration_admin.php?id=' . $member_id . '&admin_edit=' . ($is_admin_edit ? '1' : '0')) ?>" enctype="multipart/form-data" autocomplete="off">
                    <input type="hidden" name="id" value="<?= (int) $member_id ?>">
                    <input type="hidden" name="admin_edit" value="<?= $is_admin_edit ? '1' : '0' ?>">

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
        <label for="password">
          <?= $is_admin_edit ? 'Change Password' : 'Create Password <span class="text-danger">*</span>' ?>
        </label>
        <div class="input-group">
          <input type="password" class="form-control" name="password" id="password" <?= $is_password_required ? 'required' : '' ?> minlength="6" autocomplete="new-password" placeholder="<?= $is_admin_edit ? 'Leave blank to keep current password' : 'Create a password' ?>">
          <div class="input-group-append">
            <span class="input-group-text toggle-password" style="cursor:pointer;"><i class="fa fa-eye"></i></span>
          </div>
        </div>
        <small class="form-text text-muted">
          <?= $is_admin_edit ? 'Leave blank to keep current password.' : 'Password will be used to log in with your CRN as username.' ?>
        </small>
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
        <input type="date" class="form-control" name="dob" id="dob" value="<?=htmlspecialchars($dob_input_value)?>" required>
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
        <label for="gps_char_0">GPS Number</label>
        <div class="gps-address-boxes gps-char-wrap">
          <?php for ($gps_index = 0; $gps_index < 10; $gps_index++): ?>
            <?php if ($gps_index === 2 || $gps_index === 6): ?>
              <span class="gps-separator">-</span>
            <?php endif; ?>
            <input
              type="text"
              class="form-control gps-char"
              id="gps_char_<?= $gps_index ?>"
              data-index="<?= $gps_index ?>"
              maxlength="1"
              inputmode="<?= $gps_index < 2 ? 'text' : 'numeric' ?>"
              autocomplete="off"
              aria-label="GPS character <?= $gps_index + 1 ?>">
          <?php endfor; ?>
        </div>
        <small class="form-text text-muted">Format: 2 letters - 3 or 4 digits - 4 digits (example: GA-123-4567 or GA-1234-4567)</small>
        <input type="hidden" name="gps_address" id="gps_address" value="<?=htmlspecialchars($member['gps_address'])?>">
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
      <div class="form-group col-md-4" id="spouse-group" style="display:none;">
        <label for="spouse_crn">Spouse Name or CRN</label>
        <select class="form-control" name="spouse_crn" id="spouse_crn">
          <option value="">-- Search by CRN/name/phone or type name --</option>
          <?php if ($spouse_initial_value !== ''): ?>
            <option value="<?= htmlspecialchars($spouse_initial_value) ?>" selected><?= htmlspecialchars($spouse_initial_label) ?></option>
          <?php endif; ?>
        </select>
        <input type="hidden" name="spouse_name" id="spouse_name" value="<?=htmlspecialchars($spouse_name_value)?>">
        <small class="form-text text-muted">Search for member or type spouse's full name</small>
      </div>
      <div class="form-group col-md-4" id="marriage-type-group" style="display:none;">
        <label for="marriage_type">Nature of Marriage <span class="text-danger">*</span></label>
        <select class="form-control" name="marriage_type" id="marriage_type">
          <option value="">-- Select --</option>
          <option value="Customary" <?=($member['marriage_type'] ?? '') === 'Customary' ? 'selected' : ''?>>Customary</option>
          <option value="Ordinance" <?=($member['marriage_type'] ?? '') === 'Ordinance' ? 'selected' : ''?>>Ordinance</option>
          <option value="Blessing" <?=($member['marriage_type'] ?? '') === 'Blessing' ? 'selected' : ''?>>Blessing</option>
          <option value="Court Registration" <?=($member['marriage_type'] ?? '') === 'Court Registration' ? 'selected' : ''?>>Court Registration</option>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group col-md-6">
        <label for="home_town">Home Town <span class="text-danger">*</span></label>
        <input type="text" class="form-control" name="home_town" id="home_town" value="<?=htmlspecialchars($member['home_town'])?>" required>
      </div>
      <div class="form-group col-md-6">
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
      <?php foreach ($form_emergency_contacts as $contact_idx_zero => $contact_entry): ?>
      <?php
        $contact_idx = $contact_idx_zero + 1;
        $contact_crn = trim((string) ($contact_entry['crn'] ?? ''));
        $contact_name = trim((string) ($contact_entry['name'] ?? ''));
        $contact_mobile = trim((string) ($contact_entry['mobile'] ?? ''));
        $contact_relationship = trim((string) ($contact_entry['relationship'] ?? ''));
        $contact_select_value = $contact_crn !== '' ? $contact_crn : $contact_name;
        $contact_select_label = $contact_name !== '' ? $contact_name : $contact_select_value;
      ?>
      <div class="form-row emergency-contact-row">
        <div class="form-group col-md-7">
          <label>Contact Person (Name or Member CRN)</label>
          <select class="form-control emergency-contact-search" name="emergency_contacts[<?= (int) $contact_idx ?>][crn]" data-idx="<?= (int) $contact_idx ?>">
            <option value="">-- Search by CRN/name/phone or type name --</option>
            <?php if ($contact_select_value !== ''): ?>
              <option value="<?= htmlspecialchars($contact_select_value) ?>" selected><?= htmlspecialchars($contact_select_label) ?></option>
            <?php endif; ?>
          </select>
          <input type="hidden" class="emergency-contact-name" name="emergency_contacts[<?= (int) $contact_idx ?>][name]" value="<?= htmlspecialchars($contact_name) ?>">
          <input type="hidden" class="emergency-contact-mobile" name="emergency_contacts[<?= (int) $contact_idx ?>][mobile]" value="<?= htmlspecialchars($contact_mobile) ?>">
          <small class="form-text text-muted">Search for member or type full name</small>
        </div>
        <div class="form-group col-md-4">
          <label>Relationship</label>
          <select class="form-control emergency-contact-relationship" name="emergency_contacts[<?= (int) $contact_idx ?>][relationship]" <?= $contact_idx === 1 ? 'required' : '' ?>>
            <option value="">-- Select Relationship --</option>
            <?php foreach ($relationship_options as $relationship_option): ?>
              <option value="<?= htmlspecialchars($relationship_option) ?>" <?= $contact_relationship === $relationship_option ? 'selected' : '' ?>><?= htmlspecialchars($relationship_option) ?></option>
            <?php endforeach; ?>
            <?php if ($contact_relationship !== '' && !in_array($contact_relationship, $relationship_options, true)): ?>
              <option value="<?= htmlspecialchars($contact_relationship) ?>" selected><?= htmlspecialchars($contact_relationship) ?> (Custom)</option>
            <?php endif; ?>
          </select>
        </div>
        <div class="form-group col-md-1">
          <label>&nbsp;</label>
          <button class="btn btn-danger remove-emergency-contact" type="button" style="margin-top:2px;"><i class="fa fa-trash"></i></button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-outline-primary mb-3" id="add-emergency-contact" type="button"><i class="fa fa-plus"></i> Add Emergency Contact</button>
  </div>
</div>

<!-- SECTION: Employment & Profession -->
<div class="card mb-4 border-primary">
  <div class="card-header bg-light border-primary"><strong>Employment & Profession</strong></div>
  <div class="card-body p-3">
    <div class="form-row">
      <div class="form-group col-md-4">
        <label for="employment_status">Current Employment Status <span class="text-danger">*</span></label>
        <select class="form-control" name="employment_status" id="employment_status" required>
          <option value="">-- Select --</option>
          <option value="Formal" <?=$member['employment_status']=='Formal'?'selected':''?>>Formal</option>
          <option value="Informal" <?=$member['employment_status']=='Informal'?'selected':''?>>Informal</option>
          <option value="Self Employed" <?=$member['employment_status']=='Self Employed'?'selected':''?>>Self Employed</option>
          <option value="Unemployed" <?=$member['employment_status']=='Unemployed'?'selected':''?>>Unemployed</option>
          <option value="Retired" <?=$member['employment_status']=='Retired'?'selected':''?>>Retired</option>
          <option value="Student" <?=$member['employment_status']=='Student'?'selected':''?>>Student</option>
        </select>
      </div>
      <div class="form-group col-md-4">
        <label for="profession">Profession</label>
        <input type="text" class="form-control" name="profession" id="profession" value="<?=htmlspecialchars($member['profession'])?>">
      </div>
      <div class="form-group col-md-4">
        <label for="occupation">Occupation</label>
        <input type="text" class="form-control" name="occupation" id="occupation" value="<?=htmlspecialchars($member['occupation'] ?? '')?>">
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
        <input type="date" class="form-control" name="date_of_baptism" id="date_of_baptism" value="<?=htmlspecialchars($date_of_baptism_input_value)?>">
      </div>
      <div class="form-group col-md-3" style="display:none;">
        <label for="date_of_confirmation">Date of Confirmation</label>
        <input type="date" class="form-control" name="date_of_confirmation" id="date_of_confirmation" value="<?=htmlspecialchars($date_of_confirmation_input_value)?>">
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
        <input type="date" class="form-control" name="date_of_enrollment" id="date_of_enrollment" value="<?=htmlspecialchars($date_of_enrollment_input_value)?>">
      </div>
      <div class="form-group col-md-4">
        <label for="organizations">Organization(s)</label>

        <select class="form-control" name="organizations[]" id="organizations" multiple>
          <?php
          $orgs = $conn->query("SELECT id, name FROM organizations ORDER BY name ASC");
          $member_orgs = [];
          if ($member_id > 0) {
            $orgq = $conn->query("SELECT organization_id FROM member_organizations WHERE member_id=".$member_id);
            while($oo = $orgq->fetch_assoc()) $member_orgs[] = $oo['organization_id'];
          }
          while($org = $orgs->fetch_assoc()): ?>
            <option value="<?=$org['id']?>" <?=in_array($org['id'], $member_orgs)?'selected':''?>><?=htmlspecialchars($org['name'])?></option>
          <?php endwhile; ?>
        </select>
        <small class="form-text text-muted">Selected organizations will be assigned immediately.</small>
      </div>
      <div class="form-group col-md-4">
        <label for="roles_of_serving">Roles of Serving</label>
        <select class="form-control" name="roles_of_serving[]" id="roles_of_serving" multiple>
          <?php
          $roles = $conn->query("SELECT id, name FROM roles_of_serving ORDER BY name ASC");
          $member_roles = [];
          if ($member_id > 0) {
            $roleq = $conn->query("SELECT role_id FROM member_roles_of_serving WHERE member_id=".$member_id);
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
                <script>window.RELATIONSHIP_OPTIONS_HTML = <?= json_encode($relationship_options_html) ?>;</script>
                <script src="<?= $base_url ?>/assets/registration.js"></script>
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
    // Enable Select2 for roles of serving
    $('#roles_of_serving').select2({
        placeholder: 'Select roles of serving',
        allowClear: true,
        width: '100%',
        minimumResultsForSearch: 0
    });
    // Enable Select2 for organizations if not already
    $('#organizations').select2({
        placeholder: 'Select organizations',
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
                <div class="form-group text-center mt-4">
                    <button type="submit" class="btn btn-success btn-lg px-5">
                        <i class="fa fa-check-circle mr-2"></i> <?= htmlspecialchars($submit_label) ?>
                    </button>
                </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<style>
    .select2-container--default .select2-selection--multiple {
        border-radius: 0.35rem; min-height: 38px; border: 1px solid #d1d3e2;
    }
    .emergency-contact-row+.emergency-contact-row { margin-top: 10px; }
    .gps-address-boxes {
        display: flex;
        align-items: center;
        gap: 2px;
        flex-wrap: nowrap;
    }
    .gps-char {
        width: 24px;
        height: 30px;
        padding: 0.1rem 0.05rem;
        text-align: center;
        text-transform: uppercase;
        letter-spacing: 0;
        font-weight: 600;
        font-size: 0.82rem;
        background-image: none !important;
        padding-right: 0.1rem;
    }
    .gps-char.is-invalid,
    .gps-char.is-valid {
        background-image: none !important;
    }
    .gps-separator {
        font-size: 0.85rem;
        line-height: 1;
        font-weight: 700;
        color: #111827;
    }
    @media (max-width: 575.98px) {
        .gps-address-boxes {
            gap: 2px;
        }
        .gps-char {
            width: 20px;
            height: 26px;
            padding-left: 0;
            padding-right: 0;
            font-size: 0.75rem;
        }
    }
</style>
<?php
$page_content = ob_get_clean();
$script_name = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);
$base_url = rtrim(dirname(dirname($script_name)), '/\\');
if ($base_url === '/' || $base_url === '' || $base_url === '.') $base_url = '';
$logo_url = $base_url . '/assets/logo.png';
?>
<!-- Inject BASE_URL for JS -->
<script>window.BASE_URL = <?= json_encode($base_url) ?>;</script>
<?php
require_once __DIR__.'/../includes/layout.php';
?>
