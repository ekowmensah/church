<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';
require_once __DIR__.'/../helpers/church_helper.php';
require_once __DIR__.'/../includes/ZKTecoService.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Check permissions with super admin bypass
$is_super_admin = isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1;
if (!$is_super_admin && !has_permission('mark_attendance')) {
    header('Location: ' . BASE_URL . '/views/user_dashboard.php');
    exit;
}

$zkService = new ZKTecoService();
$message = '';
$messageType = '';
$device_id = isset($_GET['device_id']) ? intval($_GET['device_id']) : 0;

// Handle bulk enrollment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_enroll') {
    $device_id = intval($_POST['device_id']);
    $member_ids = isset($_POST['member_ids']) ? $_POST['member_ids'] : [];
    $fingerprint_enrolled = isset($_POST['fingerprint_enrolled']);
    $face_enrolled = isset($_POST['face_enrolled']);
    
    if (empty($member_ids)) {
        $message = 'Please select at least one member to enroll.';
        $messageType = 'error';
    } else {
        $success_count = 0;
        $error_count = 0;
        $errors = [];
        
        foreach ($member_ids as $member_id) {
            $member_id = intval($member_id);
            
            // Check if member is already enrolled on this device
            $check_stmt = $conn->prepare("
                SELECT id FROM member_biometric_data 
                WHERE member_id = ? AND device_id = ? AND is_active = TRUE
            ");
            $check_stmt->bind_param('ii', $member_id, $device_id);
            $check_stmt->execute();
            
            if ($check_stmt->get_result()->num_rows > 0) {
                $error_count++;
                // Get member name for error message
                $name_stmt = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) as name FROM members WHERE id = ?");
                $name_stmt->bind_param('i', $member_id);
                $name_stmt->execute();
                $member_name = $name_stmt->get_result()->fetch_assoc()['name'];
                $errors[] = "$member_name is already enrolled on this device";
                $name_stmt->close();
            } else {
                // Generate unique ZK User ID
                $zk_user_id = $zkService->generateUniqueZKUserId($device_id, $member_id);
                
                // Insert enrollment record
                $stmt = $conn->prepare("
                    INSERT INTO member_biometric_data 
                    (member_id, device_id, zk_user_id, fingerprint_enrolled, face_enrolled, is_active, enrollment_date) 
                    VALUES (?, ?, ?, ?, ?, TRUE, NOW())
                ");
                $stmt->bind_param('iiiii', $member_id, $device_id, $zk_user_id, $fingerprint_enrolled, $face_enrolled);
                
                if ($stmt->execute()) {
                    $success_count++;
                } else {
                    $error_count++;
                    $errors[] = "Database error for member ID $member_id";
                }
                $stmt->close();
            }
            $check_stmt->close();
        }
        
        if ($success_count > 0) {
            $message = "Successfully enrolled $success_count member(s).";
            if ($error_count > 0) {
                $message .= " $error_count enrollment(s) failed: " . implode(', ', $errors);
            }
            $messageType = 'success';
        } else {
            $message = "No members were enrolled. Errors: " . implode(', ', $errors);
            $messageType = 'error';
        }
    }
}

// Get filter parameters
$organization_id = isset($_GET['organization_id']) ? intval($_GET['organization_id']) : 0;
$bible_class_id = isset($_GET['bible_class_id']) ? intval($_GET['bible_class_id']) : 0;

// Get church selection for super admins
if ($is_super_admin) {
    $selected_church_id = isset($_GET['church_id']) ? intval($_GET['church_id']) : 0;
    if (!$selected_church_id) {
        $selected_church_id = get_user_church_id($conn); // Default to user's church
    }
    
    // Get all churches for super admin
    $churches_query = "SELECT id, name FROM churches ORDER BY name";
    $churches = $conn->query($churches_query)->fetch_all(MYSQLI_ASSOC);
} else {
    $selected_church_id = get_user_church_id($conn);
    $churches = [];
}

$church_id = $selected_church_id;

// Get device information
$device = null;
if ($device_id) {
    $stmt = $conn->prepare("SELECT * FROM zkteco_devices WHERE id = ? AND church_id = ? AND is_active = TRUE");
    $stmt->bind_param('ii', $device_id, $church_id);
    $stmt->execute();
    $device = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Get all active devices if no specific device selected
$devices_query = "SELECT id, device_name, location FROM zkteco_devices WHERE is_active = TRUE AND church_id = ? ORDER BY device_name";
$stmt = $conn->prepare($devices_query);
$stmt->bind_param('i', $church_id);
$stmt->execute();
$devices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get organizations for the current church
$organizations_query = "SELECT id, name FROM organizations WHERE church_id = ? ORDER BY name";
$stmt = $conn->prepare($organizations_query);
$stmt->bind_param('i', $church_id);
$stmt->execute();
$organizations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get bible classes for the current church
$bible_classes_query = "SELECT id, name FROM bible_classes WHERE church_id = ? ORDER BY name";
$stmt = $conn->prepare($bible_classes_query);
$stmt->bind_param('i', $church_id);
$stmt->execute();
$bible_classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get members not yet enrolled on the selected device (or any device if none selected)
$members_query = "
    SELECT DISTINCT m.id, m.first_name, m.last_name, m.crn, m.phone
    FROM members m
";

// Add joins for filtering
$joins = [];
$where_conditions = ["m.church_id = ?", "m.status = 'active'"];
$params = [$church_id];
$param_types = 'i';

if ($organization_id) {
    $joins[] = "INNER JOIN member_organizations mo ON m.id = mo.member_id";
    $where_conditions[] = "mo.organization_id = ?";
    $params[] = $organization_id;
    $param_types .= 'i';
}

if ($bible_class_id) {
    $where_conditions[] = "m.class_id = ?";
    $params[] = $bible_class_id;
    $param_types .= 'i';
}

if (!empty($joins)) {
    $members_query .= " " . implode(" ", $joins);
}

$members_query .= " WHERE " . implode(" AND ", $where_conditions);

if ($device_id) {
    $members_query .= " AND m.id NOT IN (
        SELECT mbd.member_id 
        FROM member_biometric_data mbd 
        WHERE mbd.device_id = ? AND mbd.is_active = TRUE
    )";
    $params[] = $device_id;
    $param_types .= 'i';
}

$members_query .= " ORDER BY m.first_name, m.last_name";

$stmt = $conn->prepare($members_query);
if (!$stmt) {
    error_log("Bulk Enrollment - SQL prepare failed: " . $conn->error);
    error_log("Query: " . $members_query);
}
$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$available_members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Debug output
error_log("Bulk Enrollment Debug:");
error_log("Device ID: " . $device_id);
error_log("Church ID: " . $church_id);
error_log("Organization ID: " . $organization_id);
error_log("Bible Class ID: " . $bible_class_id);
error_log("Query: " . $members_query);
error_log("Params: " . print_r($params, true));
error_log("Available members count: " . count($available_members));
error_log("Device found: " . ($device ? 'Yes' : 'No'));

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0 text-gray-800"><i class="fas fa-users mr-2"></i>Bulk Biometric Enrollment</h1>
    <div>
        <a href="zkteco_devices.php" class="btn btn-secondary mr-2"><i class="fas fa-microchip mr-1"></i> Devices</a>
        <a href="zkteco_enrollment.php" class="btn btn-secondary"><i class="fas fa-fingerprint mr-1"></i> Individual Enrollment</a>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?> alert-dismissible">
        <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<!-- Device Selection -->
<div class="card card-primary mb-4">
    <div class="card-header">
        <h3 class="card-title">Select Device</h3>
    </div>
    <div class="card-body">
        <?php if (empty($devices)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i> No active ZKTeco devices found. 
                <a href="zkteco_devices.php">Add a device</a> first.
            </div>
        <?php else: ?>
            <form method="GET" class="form-inline">
                <?php if ($is_super_admin): ?>
                <div class="form-group mr-3">
                    <label for="church_id" class="mr-2">Church:</label>
                    <select class="form-control" id="church_id" name="church_id" onchange="handleChurchChange()">
                        <option value="">Select Church...</option>
                        <?php foreach ($churches as $church): ?>
                            <option value="<?php echo $church['id']; ?>" <?php echo $church_id == $church['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($church['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="form-group mr-3">
                    <label for="device_id" class="mr-2">Device:</label>
                    <select class="form-control" id="device_id" name="device_id" onchange="this.form.submit()">
                        <option value="">Select a device...</option>
                        <?php foreach ($devices as $dev): ?>
                            <option value="<?php echo $dev['id']; ?>" <?php echo $device_id == $dev['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dev['device_name']); ?>
                                <?php if ($dev['location']): ?>
                                    (<?php echo htmlspecialchars($dev['location']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group mr-3">
                    <label for="organization_id" class="mr-2">Organization:</label>
                    <select class="form-control" id="organization_id" name="organization_id" onchange="this.form.submit()">
                        <option value="">All Organizations</option>
                        <?php foreach ($organizations as $org): ?>
                            <option value="<?php echo $org['id']; ?>" <?php echo $organization_id == $org['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($org['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group mr-3">
                    <label for="bible_class_id" class="mr-2">Bible Class:</label>
                    <select class="form-control" id="bible_class_id" name="bible_class_id" onchange="this.form.submit()">
                        <option value="">All Bible Classes</option>
                        <?php foreach ($bible_classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $bible_class_id == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Hidden inputs to preserve filter values -->
                <!-- Removed duplicate hidden inputs - dropdowns handle their own values -->
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($device): ?>
<!-- Bulk Enrollment Form -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">
            Bulk Enroll Members to: <?php echo htmlspecialchars($device['device_name']); ?>
            <?php if ($device['location']): ?>
                <small class="text-muted">(<?php echo htmlspecialchars($device['location']); ?>)</small>
            <?php endif; ?>
        </h3>
    </div>
    <form method="POST">
        <div class="card-body">
            <input type="hidden" name="action" value="bulk_enroll">
            <input type="hidden" name="device_id" value="<?php echo $device_id; ?>">
            
            <?php if (empty($available_members)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> All active members are already enrolled on this device.
                </div>
            <?php else: ?>
                <!-- Biometric Options -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h5>Biometric Types to Enroll:</h5>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="fingerprint_enrolled" name="fingerprint_enrolled" value="1" checked>
                            <label class="form-check-label" for="fingerprint_enrolled">Fingerprint</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="face_enrolled" name="face_enrolled" value="1">
                            <label class="form-check-label" for="face_enrolled">Face Recognition</label>
                        </div>
                    </div>
                </div>
                
                <!-- Member Selection -->
                <div class="row">
                    <div class="col-12">
                        <h5>Select Members to Enroll:</h5>
                        <div class="mb-3">
                            <button type="button" class="btn btn-sm btn-secondary" onclick="selectAll()">Select All</button>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="selectNone()">Select None</button>
                            <span class="ml-3 text-muted" id="selectedCount">0 members selected</span>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th width="50">
                                            <input type="checkbox" id="selectAllCheckbox" onchange="toggleAll()">
                                        </th>
                                        <th>Name</th>
                                        <th>CRN</th>
                                        <th>Phone</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($available_members as $member): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="member-checkbox" name="member_ids[]" 
                                                       value="<?php echo $member['id']; ?>" onchange="updateCount()">
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($member['crn']); ?></td>
                                            <td><?php echo htmlspecialchars($member['phone'] ?: '-'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (!empty($available_members)): ?>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-fingerprint mr-1"></i> Enroll Selected Members
                    </button>
                    <a href="zkteco_devices.php" class="btn btn-secondary">Cancel</a>
                </div>
            <?php endif; ?>
        </form>
    </div>
<?php endif; ?>

<script>
function selectAll() {
    const checkboxes = document.querySelectorAll('.member-checkbox');
    checkboxes.forEach(cb => cb.checked = true);
    document.getElementById('selectAllCheckbox').checked = true;
    updateCount();
}

function selectNone() {
    const checkboxes = document.querySelectorAll('.member-checkbox');
    checkboxes.forEach(cb => cb.checked = false);
    document.getElementById('selectAllCheckbox').checked = false;
    updateCount();
}

function toggleAll() {
    const selectAll = document.getElementById('selectAllCheckbox').checked;
    const checkboxes = document.querySelectorAll('.member-checkbox');
    checkboxes.forEach(cb => cb.checked = selectAll);
    updateCount();
}

function updateCount() {
    const selectedCountElement = document.getElementById('selectedCount');
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    
    // Only proceed if the elements exist (i.e., when a device is selected and member list is shown)
    if (!selectedCountElement || !selectAllCheckbox) {
        return;
    }
    
    const checked = document.querySelectorAll('.member-checkbox:checked').length;
    selectedCountElement.textContent = checked + ' member' + (checked !== 1 ? 's' : '') + ' selected';
    
    // Update select all checkbox state
    const total = document.querySelectorAll('.member-checkbox').length;
    if (checked === 0) {
        selectAllCheckbox.indeterminate = false;
        selectAllCheckbox.checked = false;
    } else if (checked === total) {
        selectAllCheckbox.indeterminate = false;
        selectAllCheckbox.checked = true;
    } else {
        selectAllCheckbox.indeterminate = true;
    }
}

// Initialize count on page load
document.addEventListener('DOMContentLoaded', function() {
    updateCount();
    updateFilterStates();
});

function handleChurchChange() {
    const churchId = $('#church_id').val();
    
    if (!churchId) {
        // Reset dependent filters when no church is selected
        $('#device_id').val('').prop('disabled', true);
        $('#organization_id').val('').prop('disabled', true);
        $('#bible_class_id').val('').prop('disabled', true);
        return;
    }
    
    // Enable dependent filters and submit form
    $('#device_id').prop('disabled', false);
    $('#organization_id').prop('disabled', false);
    $('#bible_class_id').prop('disabled', false);
    
    // Submit form to reload data for selected church
    $('#church_id').closest('form').submit();
}

function updateFilterStates() {
    <?php if ($is_super_admin): ?>
    const churchId = $('#church_id').val();
    
    if (!churchId) {
        $('#device_id').prop('disabled', true);
        $('#organization_id').prop('disabled', true);
        $('#bible_class_id').prop('disabled', true);
    } else {
        $('#device_id').prop('disabled', false);
        $('#organization_id').prop('disabled', false);
        $('#bible_class_id').prop('disabled', false);
    }
    <?php endif; ?>
}
</script>

<?php
$page_content = ob_get_clean();
require_once __DIR__.'/../includes/layout.php';
?>
