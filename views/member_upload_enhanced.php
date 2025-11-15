<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Only allow logged-in users with correct permission
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$is_super_admin = (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
$can_upload = $is_super_admin || (has_permission('manage_members'));
if (!$can_upload) {
    die('No permission to upload members.');
}

$success = '';
$error = '';
$upload_errors = [];
$warnings = [];

// Auto-creation helper functions
function autoCreateClassGroup($group_name, $church_id, $conn, $row_num, &$warnings) {
    // Generate a simple code from name (first 3 letters + numbers)
    $base_code = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $group_name), 0, 3));
    $code = $base_code;
    $counter = 1;
    
    // Ensure unique code
    while (true) {
        $stmt = $conn->prepare('SELECT id FROM class_groups WHERE name = ? LIMIT 1');
        $stmt->bind_param('s', $code);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            break;
        }
        $code = $base_code . str_pad($counter, 2, '0', STR_PAD_LEFT);
        $counter++;
        $stmt->close();
    }
    
    // Create class group
    $stmt = $conn->prepare('INSERT INTO class_groups (name, church_id) VALUES (?, ?)');
    $stmt->bind_param('si', $group_name, $church_id);
    if ($stmt->execute()) {
        $group_id = $conn->insert_id;
        $warnings[] = "Row $row_num: Auto-created class group '$group_name' (ID: $group_id).";
        $stmt->close();
        return ['id' => $group_id, 'name' => $group_name];
    }
    $stmt->close();
    return null;
}

function autoCreateBibleClass($class_name, $church_id, $conn, $row_num, &$warnings) {
    // Extract or generate class group from class name
    $class_group_name = null;
    $class_group_id = null;
    
    // Try to extract group from class name patterns
    if (preg_match('/^(.+?)\s+(\d+)$/', $class_name, $matches)) {
        // Pattern: "GROUP NAME 01" -> Group: "GROUP NAME"
        $class_group_name = trim($matches[1]);
    } elseif (preg_match('/^(.+?)\s+CLASS/i', $class_name, $matches)) {
        // Pattern: "FREEMAN CLASS" -> Group: "FREEMAN"
        $class_group_name = trim($matches[1]);
    } else {
        // Default: use first word as group
        $words = explode(' ', $class_name);
        $class_group_name = $words[0];
    }
    
    // Look for existing class group
    $stmt = $conn->prepare('SELECT id FROM class_groups WHERE name = ? AND (church_id = ? OR church_id IS NULL) LIMIT 1');
    $stmt->bind_param('si', $class_group_name, $church_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $group = $result->fetch_assoc();
    $stmt->close();
    
    if ($group) {
        $class_group_id = $group['id'];
    } else {
        // Auto-create class group
        $created_group = autoCreateClassGroup($class_group_name, $church_id, $conn, $row_num, $warnings);
        if ($created_group) {
            $class_group_id = $created_group['id'];
        }
    }
    
    // Generate class code
    $base_code = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $class_group_name), 0, 3));
    
    // Get next sequential number for this group
    $stmt = $conn->prepare('SELECT COUNT(*) as cnt FROM bible_classes WHERE church_id = ? AND code LIKE ?');
    $code_pattern = $base_code . '%';
    $stmt->bind_param('is', $church_id, $code_pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->fetch_assoc()['cnt'];
    $stmt->close();
    
    $class_code = $base_code . str_pad($count + 1, 2, '0', STR_PAD_LEFT);
    
    // Ensure code uniqueness
    $counter = 1;
    $original_code = $class_code;
    while (true) {
        $stmt = $conn->prepare('SELECT id FROM bible_classes WHERE code = ? AND church_id = ? LIMIT 1');
        $stmt->bind_param('si', $class_code, $church_id);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            break;
        }
        $class_code = $base_code . str_pad($count + $counter, 2, '0', STR_PAD_LEFT);
        $counter++;
        $stmt->close();
    }
    
    // Create bible class
    $stmt = $conn->prepare('INSERT INTO bible_classes (name, code, church_id, class_group_id) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('ssii', $class_name, $class_code, $church_id, $class_group_id);
    if ($stmt->execute()) {
        $class_id = $conn->insert_id;
        $warnings[] = "Row $row_num: Auto-created bible class '$class_name' with code '$class_code' (ID: $class_id).";
        $stmt->close();
        return ['id' => $class_id, 'code' => $class_code, 'name' => $class_name];
    }
    $stmt->close();
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['members_file'])) {
    $file = $_FILES['members_file'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $error = 'Only CSV files are allowed.';
        } else {
            $csv = fopen($file['tmp_name'], 'r');
            $header = fgetcsv($csv);
            
            // Flexible header validation - support multiple formats
            $header_lower = array_map('strtolower', $header);
            
            // Field mapping - separated into required and optional
            $required_fields = [
                'first_name' => ['first_name', 'firstname', 'fname'],
                'last_name' => ['last_name', 'lastname', 'lname', 'surname'],
                'gender' => ['gender', 'sex'],
                'church_code' => ['church_code', 'church'],
                'class_identifier' => ['class_code', 'class_name', 'bible_class', 'class']
            ];
            
            $optional_fields = [
                'middle_name' => ['middle_name', 'middlename', 'mname'],
                'dob' => ['dob', 'date_of_birth', 'birth_date', 'birthdate'],
                'phone' => ['phone', 'phone_number', 'mobile', 'contact'],
                'crn' => ['crn', 'church_registration_number', 'registration_number', 'member_id']
            ];
            
            $field_mapping = array_merge($required_fields, $optional_fields);
            
            // Map CSV columns to our fields
            $column_map = [];
            $missing_fields = [];
            
            // Check required fields
            foreach ($required_fields as $required_field => $possible_names) {
                $found = false;
                foreach ($possible_names as $possible_name) {
                    $index = array_search($possible_name, $header_lower);
                    if ($index !== false) {
                        $column_map[$required_field] = $index;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $missing_fields[] = $required_field;
                }
            }
            
            // Map optional fields (no error if missing)
            foreach ($optional_fields as $optional_field => $possible_names) {
                foreach ($possible_names as $possible_name) {
                    $index = array_search($possible_name, $header_lower);
                    if ($index !== false) {
                        $column_map[$optional_field] = $index;
                        break;
                    }
                }
            }
            
            if (!empty($missing_fields)) {
                $error = 'Missing required columns: ' . implode(', ', $missing_fields) . 
                        '. Supported column names: ' . json_encode($required_fields);
            } else {
                $count = 0;
                $row_num = 1;
                
                while ($row = fgetcsv($csv)) {
                    $row_num++;
                    if (empty(array_filter($row))) continue; // Skip empty rows
                    
                    // Extract data using column mapping
                    $first_name = trim($row[$column_map['first_name']] ?? '');
                    $middle_name = trim($row[$column_map['middle_name']] ?? '');
                    $last_name = trim($row[$column_map['last_name']] ?? '');
                    $gender = trim($row[$column_map['gender']] ?? '');
                    $dob = trim($row[$column_map['dob']] ?? '');
                    $phone = trim($row[$column_map['phone']] ?? '');
                    $church_code = trim($row[$column_map['church_code']] ?? '');
                    $class_identifier = trim($row[$column_map['class_identifier']] ?? '');
                    $existing_crn = trim($row[$column_map['crn']] ?? '');
                    
                    // Validate required fields
                    if (!$first_name || !$last_name || !$church_code || !$class_identifier) {
                        $upload_errors[] = "Row $row_num: Missing required fields (first_name, last_name, gender, church_code, class_identifier).";
                        continue;
                    }
                    
                    // Lookup church_id and circuit_code from church_code
                    $stmt2 = $conn->prepare('SELECT id, circuit_code FROM churches WHERE church_code = ? LIMIT 1');
                    $stmt2->bind_param('s', $church_code);
                    $stmt2->execute();
                    $result2 = $stmt2->get_result();
                    $church = $result2->fetch_assoc();
                    $stmt2->close();
                    
                    if (!$church) {
                        $upload_errors[] = "Row $row_num: church_code '$church_code' not found.";
                        continue;
                    }
                    
                    $church_id = $church['id'];
                    $circuit_code = $church['circuit_code'];
                    
                    // Flexible bible class lookup with auto-creation
                    $class_id = null;
                    $class_code_for_crn = null;
                    
                    // Try as class code first
                    $stmt1 = $conn->prepare('SELECT id, code FROM bible_classes WHERE code = ? AND church_id = ? LIMIT 1');
                    $stmt1->bind_param('si', $class_identifier, $church_id);
                    $stmt1->execute();
                    $result1 = $stmt1->get_result();
                    $class = $result1->fetch_assoc();
                    $stmt1->close();
                    
                    if ($class) {
                        $class_id = $class['id'];
                        $class_code_for_crn = $class['code'];
                    } else {
                        // Try as class name
                        $stmt1 = $conn->prepare('SELECT id, code FROM bible_classes WHERE name = ? AND church_id = ? LIMIT 1');
                        $stmt1->bind_param('si', $class_identifier, $church_id);
                        $stmt1->execute();
                        $result1 = $stmt1->get_result();
                        $class = $result1->fetch_assoc();
                        $stmt1->close();
                        
                        if ($class) {
                            $class_id = $class['id'];
                            $class_code_for_crn = $class['code'];
                            $warnings[] = "Row $row_num: Matched bible class by name '$class_identifier' to code '$class_code_for_crn'.";
                        } else {
                            // Auto-create bible class if missing
                            $auto_created = autoCreateBibleClass($class_identifier, $church_id, $conn, $row_num, $warnings);
                            if ($auto_created) {
                                $class_id = $auto_created['id'];
                                $class_code_for_crn = $auto_created['code'];
                            }
                        }
                    }
                    
                    if (!$class_id) {
                        $upload_errors[] = "Row $row_num: Failed to find or create bible class '$class_identifier' for church '$church_code'.";
                        continue;
                    }
                    
                    // Handle CRN - use existing or generate new
                    $crn = null;
                    if ($existing_crn) {
                        // Validate existing CRN format and check for duplicates
                        if (preg_match('/^[A-Z0-9]+-[A-Z0-9]+-[A-Z0-9]+$/', $existing_crn)) {
                            // Check if CRN already exists
                            $stmt_check = $conn->prepare('SELECT id FROM members WHERE crn = ? LIMIT 1');
                            $stmt_check->bind_param('s', $existing_crn);
                            $stmt_check->execute();
                            $duplicate = $stmt_check->get_result()->fetch_assoc();
                            $stmt_check->close();
                            
                            if ($duplicate) {
                                $warnings[] = "Row $row_num: CRN '$existing_crn' already exists, generating new CRN.";
                                $existing_crn = null; // Force generation of new CRN
                            } else {
                                $crn = $existing_crn;
                                $warnings[] = "Row $row_num: Using existing CRN '$existing_crn'.";
                            }
                        } else {
                            $warnings[] = "Row $row_num: Invalid CRN format '$existing_crn', generating new CRN.";
                            $existing_crn = null;
                        }
                    }
                    
                    // Generate new CRN if none provided or existing is invalid/duplicate
                    if (!$crn) {
                        // Get next sequential number for this church/class
                        $stmt3 = $conn->prepare('SELECT COUNT(*) as cnt FROM members WHERE class_id = ? AND church_id = ?');
                        $stmt3->bind_param('ii', $class_id, $church_id);
                        $stmt3->execute();
                        $result3 = $stmt3->get_result();
                        $count_existing = $result3->fetch_assoc()['cnt'];
                        $stmt3->close();
                        
                        $seq = str_pad($count_existing + 1, 2, '0', STR_PAD_LEFT);
                        $crn = $church_code . '-' . $class_code_for_crn . $seq . '-' . $circuit_code;
                        
                        // Ensure CRN uniqueness
                        $counter = 1;
                        $original_crn = $crn;
                        while (true) {
                            $stmt_check = $conn->prepare('SELECT id FROM members WHERE crn = ? LIMIT 1');
                            $stmt_check->bind_param('s', $crn);
                            $stmt_check->execute();
                            if (!$stmt_check->get_result()->fetch_assoc()) {
                                break;
                            }
                            $seq = str_pad($count_existing + $counter, 2, '0', STR_PAD_LEFT);
                            $crn = $church_code . '-' . $class_code_for_crn . $seq . '-' . $circuit_code;
                            $counter++;
                            $stmt_check->close();
                        }
                        
                        if ($existing_crn) {
                            $warnings[] = "Row $row_num: Generated new CRN '$crn'.";
                        }
                    }
                    
                    // Data validation and cleaning
                    $gender = ucfirst(strtolower($gender));
                    if (!in_array($gender, ['Male', 'Female', 'Other'])) {
                        $gender = 'Other';
                        $warnings[] = "Row $row_num: Invalid gender value, defaulted to 'Other'.";
                    }
                    
                    // Date validation
                    if ($dob && !DateTime::createFromFormat('Y-m-d', $dob)) {
                        $warnings[] = "Row $row_num: Invalid date format '$dob', please use YYYY-MM-DD.";
                        $dob = null;
                    }
                    
                    // Phone cleaning
                    $phone = preg_replace('/[^0-9+]/', '', $phone);
                    
                    // Insert member
                    $stmt = $conn->prepare('INSERT INTO members (crn, first_name, middle_name, last_name, gender, dob, phone, class_id, church_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $status = 'pending';
                    $stmt->bind_param('sssssssiis', $crn, $first_name, $middle_name, $last_name, $gender, $dob, $phone, $class_id, $church_id, $status);
                    
                    if ($stmt->execute()) {
                        $count++;
                    } else {
                        $upload_errors[] = "Row $row_num: Database error - " . $stmt->error;
                    }
                    $stmt->close();
                }
                
                $success = "Successfully uploaded $count members.";
                if (!empty($warnings)) {
                    $success .= " " . count($warnings) . " warnings generated.";
                }
            }
            fclose($csv);
        }
    } else {
        $error = 'File upload error.';
    }
}

ob_start();
?>
<div class="container mt-4">
    <div class="card shadow" style="max-width:800px;margin:auto;">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="fas fa-upload mr-2"></i>Enhanced Member Bulk Upload</h5>
            <small>Supports bible class codes/names with auto-creation of missing classes</small>
        </div>
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($error) ?>
                </div>
            <?php elseif ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($warnings)): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <strong><i class="fas fa-info-circle mr-2"></i>Processing Warnings:</strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($warnings as $warning): ?>
                            <li><?= htmlspecialchars($warning) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($upload_errors)): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <strong><i class="fas fa-exclamation-triangle mr-2"></i>Some rows were skipped:</strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($upload_errors as $e): ?>
                            <li><?= htmlspecialchars($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            <?php endif; ?>
            
            <form method="post" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="members_file"><i class="fas fa-file-csv mr-2"></i>Select CSV File</label>
                    <input type="file" class="form-control" name="members_file" id="members_file" accept=".csv" required>
                </div>
                
                <div class="card mt-3 mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-info-circle mr-2"></i>Supported CSV Formats</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Required Columns (flexible names):</strong>
                                <ul class="small">
                                    <li><strong>First Name:</strong> first_name, firstname, fname</li>
                                    <li><strong>Last Name:</strong> last_name, lastname, surname</li>
                                    <li><strong>Gender:</strong> gender, sex</li>
                                    <li><strong>Church:</strong> church_code, church</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <strong>Optional Columns:</strong>
                                <ul class="small">
                                    <li><strong>Middle Name:</strong> middle_name, middlename</li>
                                    <li><strong>Date of Birth:</strong> dob, date_of_birth</li>
                                    <li><strong>Phone:</strong> phone, phone_number, mobile</li>
                                    <li><strong>Bible Class:</strong> class_code, class_name, bible_class</li>
                                    <li><strong>CRN:</strong> crn, church_registration_number (preserves existing)</li>
                                </ul>
                            </div>
                        </div>
                        <div class="alert alert-info mt-2 mb-0">
                            <small><i class="fas fa-lightbulb mr-1"></i><strong>Smart Processing:</strong> 
                            • <strong>CRN Handling:</strong> Uses existing CRNs from your data or generates new ones<br>
                            • <strong>Class Matching:</strong> Matches by code/name or auto-creates missing classes<br>
                            • <strong>Duplicate Detection:</strong> Prevents duplicate CRNs and provides warnings</small>
                        </div>
                    </div>
                </div>
                
                <div class="text-center">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="fas fa-upload mr-2"></i>Upload Members
                    </button>
                    <a href="member_list.php" class="btn btn-secondary ml-2">
                        <i class="fas fa-list mr-2"></i>Back to List
                    </a>
                </div>
                
                <div class="mt-3 text-center">
                    <a href="sample_member_upload_enhanced.csv" class="btn btn-link">
                        <i class="fas fa-download mr-2"></i>Download Sample CSV (Enhanced)
                    </a>
                    <a href="sample_member_upload_legacy.csv" class="btn btn-link">
                        <i class="fas fa-download mr-2"></i>Download Sample CSV (Legacy Format)
                    </a>

                    <a href="sample_member_upload_auto_create.csv" class="btn btn-link">
                        <i class="fas fa-download mr-2"></i>Download Sample CSV (Auto Create)
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.card-header small {
    opacity: 0.9;
    font-size: 0.85em;
}
.alert ul {
    max-height: 200px;
    overflow-y: auto;
}
</style>

<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
