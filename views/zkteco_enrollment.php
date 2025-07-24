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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'enroll_member':
                $member_id = intval($_POST['member_id']);
                $device_id = intval($_POST['device_id']);
                $enrollment_type = $_POST['enrollment_type'];
                
                $result = $zkService->enrollMember($member_id, $device_id, $enrollment_type);
                
                if ($result['success']) {
                    $message = $result['message'] . " (ZK User ID: {$result['zk_user_id']})";
                    $messageType = 'success';
                } else {
                    $message = $result['message'];
                    $messageType = 'error';
                }
                break;
                
            case 'update_enrollment':
                $enrollment_id = intval($_POST['enrollment_id']);
                $fingerprint_enrolled = isset($_POST['fingerprint_enrolled']) ? 1 : 0;
                $face_enrolled = isset($_POST['face_enrolled']) ? 1 : 0;
                $card_number = trim($_POST['card_number']);
                $notes = trim($_POST['notes']);
                
                $stmt = $conn->prepare("
                    UPDATE member_biometric_data 
                    SET fingerprint_enrolled = ?, face_enrolled = ?, card_number = ?, notes = ?, last_updated = NOW()
                    WHERE id = ?
                ");
                $stmt->bind_param('iissi', $fingerprint_enrolled, $face_enrolled, $card_number, $notes, $enrollment_id);
                
                if ($stmt->execute()) {
                    $message = 'Enrollment updated successfully!';
                    $messageType = 'success';
                } else {
                    $message = 'Error updating enrollment.';
                    $messageType = 'error';
                }
                break;
                
            case 'deactivate_enrollment':
                $enrollment_id = intval($_POST['enrollment_id']);
                
                $stmt = $conn->prepare("UPDATE member_biometric_data SET is_active = FALSE WHERE id = ?");
                $stmt->bind_param('i', $enrollment_id);
                
                if ($stmt->execute()) {
                    $message = 'Enrollment deactivated successfully!';
                    $messageType = 'success';
                } else {
                    $message = 'Error deactivating enrollment.';
                    $messageType = 'error';
                }
                break;
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

// Get active devices
$devices_query = "SELECT id, device_name, ip_address, location FROM zkteco_devices WHERE is_active = TRUE AND church_id = ? ORDER BY device_name";
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

// Get enrolled members with their enrollment details
$enrolled_query = "
    SELECT DISTINCT mbd.*, m.first_name, m.last_name, m.crn, m.phone, zd.device_name, zd.location
    FROM member_biometric_data mbd
    JOIN members m ON mbd.member_id = m.id
    JOIN zkteco_devices zd ON mbd.device_id = zd.id
";

// Add joins for filtering
$enrolled_joins = [];
$enrolled_where_conditions = ["m.church_id = ?", "mbd.is_active = TRUE"];
$enrolled_params = [$church_id];
$enrolled_param_types = 'i';

if ($organization_id) {
    $enrolled_joins[] = "INNER JOIN member_organizations mo ON m.id = mo.member_id";
    $enrolled_where_conditions[] = "mo.organization_id = ?";
    $enrolled_params[] = $organization_id;
    $enrolled_param_types .= 'i';
}

if ($bible_class_id) {
    $enrolled_where_conditions[] = "m.class_id = ?";
    $enrolled_params[] = $bible_class_id;
    $enrolled_param_types .= 'i';
}

if (!empty($enrolled_joins)) {
    $enrolled_query .= " " . implode(" ", $enrolled_joins);
}

$enrolled_query .= " WHERE " . implode(" AND ", $enrolled_where_conditions);
$enrolled_query .= " ORDER BY m.first_name, m.last_name, zd.device_name";

$stmt = $conn->prepare($enrolled_query);
$stmt->bind_param($enrolled_param_types, ...$enrolled_params);
$stmt->execute();
$enrolled_members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0 text-gray-800"><i class="fas fa-fingerprint mr-2"></i>Biometric Enrollment</h1>
    <div>
        <a href="zkteco_bulk_enrollment.php" class="btn btn-success mr-2"><i class="fas fa-users mr-1"></i> Bulk Enrollment</a>
        <a href="zkteco_devices.php" class="btn btn-secondary"><i class="fas fa-arrow-left mr-1"></i> Back to Devices</a>
    </div>
</div>
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?> alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if (empty($devices)): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> 
                    No active ZKTeco devices found. Please <a href="zkteco_devices.php">configure devices</a> first.
                </div>
            <?php else: ?>
                <!-- Filter Section -->
                <div class="card card-secondary mb-3">
                    <div class="card-header">
                        <h3 class="card-title">Filter Members</h3>
                    </div>
                    <div class="card-body">
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
                            
                            <?php if ($organization_id || $bible_class_id): ?>
                                <a href="zkteco_enrollment.php" class="btn btn-outline-secondary">Clear Filters</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                
                <!-- Enroll New Member Card -->
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title">Enroll Member for Biometric Attendance</h3>
                    </div>
                    <form method="POST">
                        <div class="card-body">
                            <input type="hidden" name="action" value="enroll_member">
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                <strong>Note:</strong> This creates an enrollment record in the system. 
                                You must complete the actual biometric enrollment (fingerprint/face) on the physical device.
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="member_id">Select Member *</label>
                                        <select class="form-control select2" id="member_id" name="member_id" required style="width: 100%;">
                                            <option value="">Choose a member...</option>
                                        </select>
                                        <small class="form-text text-muted">Search by name or CRN</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="device_id">Device *</label>
                                        <select class="form-control" id="device_id" name="device_id" required>
                                            <option value="">Select device...</option>
                                            <?php foreach ($devices as $device): ?>
                                                <option value="<?php echo $device['id']; ?>">
                                                    <?php echo htmlspecialchars($device['device_name']); ?>
                                                    <?php if ($device['location']): ?>
                                                        (<?php echo htmlspecialchars($device['location']); ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="enrollment_type">Enrollment Type *</label>
                                        <select class="form-control" id="enrollment_type" name="enrollment_type" required>
                                            <option value="fingerprint">Fingerprint</option>
                                            <option value="face">Face Recognition</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-user-plus"></i> Create Enrollment Record
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Enrolled Members List -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Enrolled Members</h3>
                    <div class="card-tools">
                        <span class="badge badge-info"><?php echo count($enrolled_members); ?> enrolled</span>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($enrolled_members)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No members enrolled yet. Start by enrolling members above.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped" id="enrolledTable">
                                <thead>
                                    <tr>
                                        <th>Member</th>
                                        <th>CRN</th>
                                        <th>Device</th>
                                        <th>ZK User ID</th>
                                        <th>Biometric Types</th>
                                        <th>Card Number</th>
                                        <th>Enrolled Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($enrolled_members as $enrollment): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($enrollment['first_name'] . ' ' . $enrollment['last_name']); ?></strong>
                                                <?php if ($enrollment['phone']): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($enrollment['phone']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($enrollment['crn']); ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($enrollment['device_name']); ?></strong>
                                                <?php if ($enrollment['location']): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($enrollment['location']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <code><?php echo htmlspecialchars($enrollment['zk_user_id']); ?></code>
                                            </td>
                                            <td>
                                                <?php if ($enrollment['fingerprint_enrolled']): ?>
                                                    <span class="badge badge-success">Fingerprint</span>
                                                <?php endif; ?>
                                                <?php if ($enrollment['face_enrolled']): ?>
                                                    <span class="badge badge-primary">Face</span>
                                                <?php endif; ?>
                                                <?php if (!$enrollment['fingerprint_enrolled'] && !$enrollment['face_enrolled']): ?>
                                                    <span class="badge badge-warning">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo $enrollment['card_number'] ? htmlspecialchars($enrollment['card_number']) : '-'; ?>
                                            </td>
                                            <td>
                                                <small><?php echo date('M j, Y', strtotime($enrollment['enrollment_date'])); ?></small>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-info" 
                                                            onclick="editEnrollment(<?php echo $enrollment['id']; ?>)"
                                                            title="Edit Enrollment">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-danger" 
                                                            onclick="deactivateEnrollment(<?php echo $enrollment['id']; ?>)"
                                                            title="Deactivate Enrollment">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

<!-- Select2 CSS and JS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#enrolledTable').DataTable({
        responsive: true,
        order: [[0, 'asc']]
    });
    
    // Initialize filter cascade
    updateFilterStates();
    
    // Initialize Select2 for member search
    $('#member_id').select2({
        ajax: {
            url: 'ajax_members_by_church.php',
            dataType: 'json',
            delay: 250,
            data: function (params) {
                return {
                    q: params.term,
                    church_id: <?php echo $church_id; ?>,
                    organization_id: <?php echo $organization_id; ?>,
                    bible_class_id: <?php echo $bible_class_id; ?>
                };
            },
            processResults: function (data) {
                return {
                    results: data.results || []
                };
            },
            cache: true
        },
        minimumInputLength: 1,
        placeholder: 'Search members...'
    });
});

function editEnrollment(enrollmentId) {
    // Find enrollment data from the table
    const row = $(`button[onclick="editEnrollment(${enrollmentId})"]`).closest('tr');
    const cells = row.find('td');
    
    // Populate modal with current data
    $('#edit_enrollment_id').val(enrollmentId);
    $('#edit_member_name').text(cells.eq(0).find('strong').text());
    $('#edit_device_name').text(cells.eq(2).find('strong').text());
    $('#edit_zk_user_id').text(cells.eq(3).text());
    
    // Check biometric types
    const biometricBadges = cells.eq(4).find('.badge');
    $('#edit_fingerprint_enrolled').prop('checked', biometricBadges.filter('.badge-success').length > 0);
    $('#edit_face_enrolled').prop('checked', biometricBadges.filter('.badge-primary').length > 0);
    
    // Set card number
    const cardNumber = cells.eq(5).text();
    $('#edit_card_number').val(cardNumber === '-' ? '' : cardNumber);
    
    $('#editEnrollmentModal').modal('show');
}

function deactivateEnrollment(enrollmentId) {
    if (confirm('Are you sure you want to deactivate this enrollment? The member will no longer be able to use biometric attendance.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="deactivate_enrollment">
            <input type="hidden" name="enrollment_id" value="${enrollmentId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function handleChurchChange() {
    const churchId = $('#church_id').val();
    
    if (!churchId) {
        // Reset dependent filters when no church is selected
        $('#organization_id').val('').prop('disabled', true);
        $('#bible_class_id').val('').prop('disabled', true);
        return;
    }
    
    // Enable dependent filters and submit form
    $('#organization_id').prop('disabled', false);
    $('#bible_class_id').prop('disabled', false);
    
    // Submit form to reload data for selected church
    $('#church_id').closest('form').submit();
}

function updateFilterStates() {
    <?php if ($is_super_admin): ?>
    const churchId = $('#church_id').val();
    
    if (!churchId) {
        $('#organization_id').prop('disabled', true);
        $('#bible_class_id').prop('disabled', true);
    } else {
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

<!-- Edit Enrollment Modal -->
<div class="modal fade" id="editEnrollmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Edit Enrollment</h4>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" id="editEnrollmentForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_enrollment">
                    <input type="hidden" name="enrollment_id" id="edit_enrollment_id">
                    
                    <div class="form-group">
                        <label>Member:</label>
                        <p id="edit_member_name" class="form-control-plaintext"></p>
                    </div>
                    
                    <div class="form-group">
                        <label>Device:</label>
                        <p id="edit_device_name" class="form-control-plaintext"></p>
                    </div>
                    
                    <div class="form-group">
                        <label>ZK User ID:</label>
                        <p id="edit_zk_user_id" class="form-control-plaintext"></p>
                    </div>
                    
                    <div class="form-group">
                        <label>Biometric Types Enrolled:</label>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="edit_fingerprint_enrolled" name="fingerprint_enrolled">
                            <label class="form-check-label" for="edit_fingerprint_enrolled">Fingerprint</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="edit_face_enrolled" name="face_enrolled">
                            <label class="form-check-label" for="edit_face_enrolled">Face Recognition</label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_card_number">Card Number:</label>
                        <input type="text" class="form-control" id="edit_card_number" name="card_number">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_notes">Notes:</label>
                        <textarea class="form-control" id="edit_notes" name="notes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Enrollment</button>
                </div>
            </form>
        </div>
    </div>
</div>
