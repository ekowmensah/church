<?php
require_once '../includes/admin_auth.php';
require_once '../config/database.php';
require_once '../helpers/HikVisionService.php';

$page_title = "HikVision Face Enrollment";
$current_page = "hikvision_enrollment";

// Initialize variables from auth
$is_super_admin = $_SESSION['is_super_admin'] ?? false;
$church_id = $_SESSION['church_id'] ?? 1;

// Check permissions
if (!$is_super_admin && !has_permission('manage_hikvision_devices')) {
    header('HTTP/1.0 403 Forbidden');
    include '../views/errors/403.php';
    exit;
}

$device_id = $_GET['device_id'] ?? null;
if (!$device_id) {
    header('Location: hikvision_devices.php');
    exit;
}

// Get device info
$device_stmt = $conn->prepare("SELECT * FROM hikvision_devices WHERE id = ? AND church_id = ?");
$device_stmt->bind_param("ii", $device_id, $church_id);
$device_stmt->execute();
$device = $device_stmt->get_result()->fetch_assoc();

if (!$device) {
    header('Location: hikvision_devices.php');
    exit;
}

$message = '';
$message_type = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'enroll_member':
                $result = enrollMember($conn, $device_id, $_POST['member_id']);
                $message = $result['message'];
                $message_type = $result['success'] ? 'success' : 'danger';
                break;
            case 'enroll_face':
                $result = enrollFace($conn, $device_id, $_POST['member_id'], $_FILES['face_image']);
                $message = $result['message'];
                $message_type = $result['success'] ? 'success' : 'danger';
                break;
            case 'remove_enrollment':
                $result = removeEnrollment($conn, $device_id, $_POST['member_id']);
                $message = $result['message'];
                $message_type = $result['success'] ? 'success' : 'danger';
                break;
        }
    }
}

// Get enrolled members
$enrolled_query = "
    SELECT m.id, m.first_name, m.last_name, m.member_number,
           mhd.hikvision_user_id, mhd.face_enrolled, mhd.enrollment_date
    FROM members m
    JOIN member_hikvision_data mhd ON m.id = mhd.member_id
    WHERE mhd.device_id = ?
    ORDER BY m.first_name, m.last_name
";
$enrolled_stmt = $conn->prepare($enrolled_query);
$enrolled_stmt->bind_param("i", $device_id);
$enrolled_stmt->execute();
$enrolled_members = $enrolled_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get non-enrolled members
$non_enrolled_query = "
    SELECT m.id, m.first_name, m.last_name, m.member_number
    FROM members m
    WHERE m.church_id = ?
    AND m.id NOT IN (
        SELECT member_id FROM member_hikvision_data WHERE device_id = ?
    )
    ORDER BY m.first_name, m.last_name
    LIMIT 50
";
$non_enrolled_stmt = $conn->prepare($non_enrolled_query);
$non_enrolled_stmt->bind_param("ii", $church_id, $device_id);
$non_enrolled_stmt->execute();
$non_enrolled_members = $non_enrolled_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

function enrollMember($conn, $device_id, $member_id) {
    try {
        // Get member info
        $member_stmt = $conn->prepare("SELECT first_name, last_name FROM members WHERE id = ?");
        $member_stmt->bind_param("i", $member_id);
        $member_stmt->execute();
        $member = $member_stmt->get_result()->fetch_assoc();
        
        if (!$member) {
            return ['success' => false, 'message' => 'Member not found'];
        }
        
        $service = new HikVisionService($conn, $device_id);
        $full_name = $member['first_name'] . ' ' . $member['last_name'];
        $result = $service->addUser($member_id, $full_name);
        
        if ($result['success']) {
            return ['success' => true, 'message' => 'Member enrolled successfully. User ID: ' . $result['hikvision_user_id']];
        } else {
            return ['success' => false, 'message' => 'Enrollment failed: ' . $result['error']];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

function enrollFace($conn, $device_id, $member_id, $face_file) {
    try {
        if ($face_file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'File upload error'];
        }
        
        // Validate file type
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png'];
        if (!in_array($face_file['type'], $allowed_types)) {
            return ['success' => false, 'message' => 'Only JPEG and PNG images are allowed'];
        }
        
        // Create upload directory if not exists
        $upload_dir = '../uploads/faces/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        // Save uploaded file
        $file_extension = pathinfo($face_file['name'], PATHINFO_EXTENSION);
        $filename = 'face_' . $member_id . '_' . time() . '.' . $file_extension;
        $file_path = $upload_dir . $filename;
        
        if (!move_uploaded_file($face_file['tmp_name'], $file_path)) {
            return ['success' => false, 'message' => 'Failed to save uploaded file'];
        }
        
        $service = new HikVisionService($conn, $device_id);
        $result = $service->enrollFace($member_id, $file_path);
        
        // Clean up uploaded file
        unlink($file_path);
        
        if ($result['success']) {
            return ['success' => true, 'message' => 'Face enrolled successfully'];
        } else {
            return ['success' => false, 'message' => 'Face enrollment failed: ' . $result['error']];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

function removeEnrollment($conn, $device_id, $member_id) {
    try {
        // Remove from database
        $stmt = $conn->prepare("DELETE FROM member_hikvision_data WHERE member_id = ? AND device_id = ?");
        $stmt->bind_param("ii", $member_id, $device_id);
        
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'Enrollment removed successfully'];
        } else {
            return ['success' => false, 'message' => 'Failed to remove enrollment'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

include '../includes/header.php';
?>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0">Face Enrollment - <?php echo htmlspecialchars($device['device_name']); ?></h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="../index.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="hikvision_devices.php">HikVision Devices</a></li>
                        <li class="breadcrumb-item active">Face Enrollment</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>

            <div class="row">
                <!-- Enroll New Member -->
                <div class="col-md-6">
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-user-plus"></i> Enroll New Member</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($non_enrolled_members)): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> All members are already enrolled or no members found.
                                </div>
                            <?php else: ?>
                                <form method="POST">
                                    <input type="hidden" name="action" value="enroll_member">
                                    <div class="form-group">
                                        <label for="member_id">Select Member</label>
                                        <select class="form-control" name="member_id" required>
                                            <option value="">-- Select Member --</option>
                                            <?php foreach ($non_enrolled_members as $member): ?>
                                                <option value="<?php echo $member['id']; ?>">
                                                    <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>
                                                    (<?php echo htmlspecialchars($member['member_number']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-user-plus"></i> Enroll Member
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Device Info -->
                <div class="col-md-6">
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle"></i> Device Information</h3>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm">
                                <tr>
                                    <td><strong>Device Name:</strong></td>
                                    <td><?php echo htmlspecialchars($device['device_name']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Model:</strong></td>
                                    <td><?php echo htmlspecialchars($device['device_model']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>IP Address:</strong></td>
                                    <td><?php echo htmlspecialchars($device['ip_address']) . ':' . $device['port']; ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Location:</strong></td>
                                    <td><?php echo htmlspecialchars($device['location'] ?? 'N/A'); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Status:</strong></td>
                                    <td>
                                        <span class="badge badge-<?php echo $device['sync_status'] === 'connected' ? 'success' : 'secondary'; ?>">
                                            <?php echo ucfirst($device['sync_status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Enrolled Users:</strong></td>
                                    <td><?php echo count($enrolled_members); ?> / <?php echo $device['max_users']; ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Enrolled Members -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-users"></i> Enrolled Members</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($enrolled_members)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No members enrolled yet.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>Member</th>
                                        <th>Member Number</th>
                                        <th>HikVision User ID</th>
                                        <th>Face Status</th>
                                        <th>Enrollment Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($enrolled_members as $member): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></td>
                                            <td><?php echo htmlspecialchars($member['member_number']); ?></td>
                                            <td><code><?php echo htmlspecialchars($member['hikvision_user_id']); ?></code></td>
                                            <td>
                                                <?php if ($member['face_enrolled']): ?>
                                                    <span class="badge badge-success"><i class="fas fa-check"></i> Enrolled</span>
                                                <?php else: ?>
                                                    <span class="badge badge-warning"><i class="fas fa-clock"></i> Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                echo $member['enrollment_date'] 
                                                    ? date('M j, Y g:i A', strtotime($member['enrollment_date']))
                                                    : 'N/A';
                                                ?>
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <?php if (!$member['face_enrolled']): ?>
                                                        <button type="button" class="btn btn-sm btn-success" 
                                                                onclick="showFaceEnrollModal(<?php echo $member['id']; ?>, '<?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>')"
                                                                title="Enroll Face">
                                                            <i class="fas fa-camera"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    
                                                    <form method="POST" style="display: inline;" 
                                                          onsubmit="return confirm('Are you sure you want to remove this enrollment?')">
                                                        <input type="hidden" name="action" value="remove_enrollment">
                                                        <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger" title="Remove Enrollment">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
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
        </div>
    </section>
</div>

<!-- Face Enrollment Modal -->
<div class="modal fade" id="faceEnrollModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h4 class="modal-title">Enroll Face</h4>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="enroll_face">
                    <input type="hidden" name="member_id" id="face_member_id">
                    
                    <p>Enroll face for <strong id="face_member_name"></strong></p>
                    
                    <div class="form-group">
                        <label for="face_image">Select Face Image</label>
                        <input type="file" class="form-control-file" name="face_image" id="face_image" 
                               accept="image/jpeg,image/jpg,image/png" required>
                        <small class="form-text text-muted">
                            Upload a clear front-facing photo. Supported formats: JPEG, PNG. Max size: 5MB.
                        </small>
                    </div>
                    
                    <div class="alert alert-info">
                        <h6><i class="fas fa-lightbulb"></i> Tips for best results:</h6>
                        <ul class="mb-0">
                            <li>Use good lighting</li>
                            <li>Face should be clearly visible</li>
                            <li>Look directly at the camera</li>
                            <li>Remove glasses if possible</li>
                            <li>Neutral expression works best</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-camera"></i> Enroll Face
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showFaceEnrollModal(memberId, memberName) {
    document.getElementById('face_member_id').value = memberId;
    document.getElementById('face_member_name').textContent = memberName;
    $('#faceEnrollModal').modal('show');
}
</script>

<?php include '../includes/footer.php'; ?>
