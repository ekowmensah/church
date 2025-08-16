<?php
/**
 * Hikvision Devices Management
 * 
 * This page allows administrators to manage Hikvision face recognition devices,
 * including adding new devices, editing existing ones, generating API keys,
 * and viewing sync history.
 */
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';
require_once __DIR__.'/../helpers/church_helper.php';
require_once __DIR__.'/../includes/HikvisionService.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Check permissions with super admin bypass
$is_super_admin = isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1;
if (!$is_super_admin && !has_permission('manage_hikvision_devices')) {
    header('Location: ' . BASE_URL . '/views/user_dashboard.php');
    exit;
}

$hikvisionService = new HikvisionService();
$message = '';
$messageType = '';

// Handle success messages from redirects
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'device_added':
            $message = 'Device added successfully!';
            $messageType = 'success';
            break;
        case 'device_updated':
            $message = 'Device updated successfully!';
            $messageType = 'success';
            break;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_device':
                $device_name = trim($_POST['name']);
                $ip_address = trim($_POST['ip_address']);
                $port = intval($_POST['port']);
                $username = trim($_POST['username']);
                $password = trim($_POST['password']);
                $location = trim($_POST['location']);
                
                // Get church_id from form (if super admin) or from user's church
                if ($is_super_admin && isset($_POST['church_id']) && !empty($_POST['church_id'])) {
                    $church_id = intval($_POST['church_id']);
                    // Validate that the selected church exists
                    $church_check = $conn->prepare('SELECT id FROM churches WHERE id = ?');
                    $church_check->bind_param('i', $church_id);
                    $church_check->execute();
                    if ($church_check->get_result()->num_rows === 0) {
                        $message = 'Selected church does not exist.';
                        $messageType = 'error';
                        $church_check->close();
                        break;
                    }
                    $church_check->close();
                } else {
                    $church_id = get_user_church_id($conn);
                    if (!$church_id) {
                        $message = 'No church found. Please ensure at least one church exists in the system.';
                        $messageType = 'error';
                        break;
                    }
                }
                
                if (empty($device_name) || empty($ip_address) || empty($username) || empty($password)) {
                    $message = 'Device name, IP address, username and password are required.';
                    $messageType = 'error';
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO hikvision_devices (name, ip_address, port, username, password, location, church_id, is_active) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, TRUE)
                    ");
                    $stmt->bind_param('ssisssi', $device_name, $ip_address, $port, $username, $password, $location, $church_id);
                    
                    if ($stmt->execute()) {
                        $message = 'Device added successfully!';
                        $messageType = 'success';
                        // Redirect to prevent duplicate submissions
                        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=device_added');
                        exit;
                    } else {
                        $message = 'Error adding device: ' . $conn->error;
                        $messageType = 'error';
                    }
                }
                break;
                
            case 'edit_device':
                $device_id = intval($_POST['device_id']);
                $device_name = trim($_POST['name']);
                $ip_address = trim($_POST['ip_address']);
                $port = intval($_POST['port']);
                $username = trim($_POST['username']);
                $password = trim($_POST['password']);
                $location = trim($_POST['location']);
                
                // For super admin, allow updating church_id
                if ($is_super_admin && isset($_POST['church_id'])) {
                    $church_id = intval($_POST['church_id']);
                    // Validate church exists
                    $church_check = $conn->prepare('SELECT id FROM churches WHERE id = ?');
                    $church_check->bind_param('i', $church_id);
                    $church_check->execute();
                    if ($church_check->get_result()->num_rows === 0) {
                        $message = 'Selected church does not exist.';
                        $messageType = 'error';
                        $church_check->close();
                        break;
                    }
                    $church_check->close();
                }
                
                if (empty($device_name) || empty($ip_address) || empty($username)) {
                    $message = 'Device name, IP address, and username are required.';
                    $messageType = 'error';
                } else {
                    // For super admin, allow updating church_id; for others, maintain existing church restriction
                    if ($is_super_admin) {
                        if (empty($password)) {
                            $stmt = $conn->prepare("
                                UPDATE hikvision_devices 
                                SET name = ?, ip_address = ?, port = ?, username = ?, location = ?, church_id = ? 
                                WHERE id = ?
                            ");
                            $stmt->bind_param('ssissii', $device_name, $ip_address, $port, $username, $location, $church_id, $device_id);
                        } else {
                            $stmt = $conn->prepare("
                                UPDATE hikvision_devices 
                                SET name = ?, ip_address = ?, port = ?, username = ?, password = ?, location = ?, church_id = ? 
                                WHERE id = ?
                            ");
                            $stmt->bind_param('ssisssii', $device_name, $ip_address, $port, $username, $password, $location, $church_id, $device_id);
                        }
                    } else {
                        // Regular users can only update devices in their church
                        $church_id = get_user_church_id($conn);
                        if (empty($password)) {
                            $stmt = $conn->prepare("
                                UPDATE hikvision_devices 
                                SET name = ?, ip_address = ?, port = ?, username = ?, location = ? 
                                WHERE id = ? AND church_id = ?
                            ");
                            $stmt->bind_param('ssissii', $device_name, $ip_address, $port, $username, $location, $device_id, $church_id);
                        } else {
                            $stmt = $conn->prepare("
                                UPDATE hikvision_devices 
                                SET name = ?, ip_address = ?, port = ?, username = ?, password = ?, location = ? 
                                WHERE id = ? AND church_id = ?
                            ");
                            $stmt->bind_param('ssisssii', $device_name, $ip_address, $port, $username, $password, $location, $device_id, $church_id);
                        }
                    }
                    
                    if ($stmt->execute() && $stmt->affected_rows > 0) {
                        $message = 'Device updated successfully!';
                        $messageType = 'success';
                        // Redirect to prevent duplicate submissions
                        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=device_updated');
                        exit;
                    } else {
                        $message = 'Error updating device or device not found.';
                        $messageType = 'error';
                        $stmt->close();
                    }
                }
                break;
                
            case 'delete_device':
                $device_id = intval($_POST['device_id']);
                $church_id = get_user_church_id($conn);
                
                // Check if device has enrollments or logs
                $stmt = $conn->prepare("
                    SELECT 
                        (SELECT COUNT(*) FROM member_hikvision_data WHERE device_id = ?) as enrollments,
                        (SELECT COUNT(*) FROM hikvision_attendance_logs WHERE device_id = ?) as logs
                ");
                $stmt->bind_param('ii', $device_id, $device_id);
                $stmt->execute();
                $result = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                if ($result['enrollments'] > 0 || $result['logs'] > 0) {
                    $message = 'Cannot delete device: It has ' . $result['enrollments'] . ' enrollments and ' . $result['logs'] . ' attendance logs. Disable it instead.';
                    $messageType = 'error';
                } else {
                    $stmt = $conn->prepare("DELETE FROM hikvision_devices WHERE id = ? AND church_id = ?");
                    $stmt->bind_param('ii', $device_id, $church_id);
                    
                    if ($stmt->execute() && $stmt->affected_rows > 0) {
                        $message = 'Device deleted successfully!';
                        $messageType = 'success';
                    } else {
                        $message = 'Error deleting device or device not found.';
                        $messageType = 'error';
                    }
                }
                break;
        }
    }
}

// Get all devices
$query = "SELECT d.*, 
    d.ip_address as ip,
    c.name as church_name,
    (SELECT COUNT(*) FROM hikvision_enrollments WHERE device_id = d.id) as enrollment_count,
    (SELECT MAX(timestamp) FROM hikvision_attendance_logs WHERE device_id = d.id) as last_attendance
    FROM hikvision_devices d 
    LEFT JOIN churches c ON d.church_id = c.id
    ORDER BY d.name";
$devices_rs = $conn->query($query);
// Some PHP environments (e.g., certain cPanel builds) do not allow foreach over mysqli_result
// Convert to a plain array to ensure consistent rendering
$devices = [];
if ($devices_rs === false) {
    // Surface query error to help diagnose blank list on production
    $message = 'Failed to load devices: ' . $conn->error;
    $messageType = 'error';
} elseif ($devices_rs instanceof mysqli_result) {
    while ($row = $devices_rs->fetch_assoc()) {
        $devices[] = $row;
    }
}

// Get sync history
$query = "
    SELECT h.*, d.name as device_name 
    FROM hikvision_sync_history h
    JOIN hikvision_devices d ON h.device_id = d.id
    ORDER BY h.start_time DESC
    LIMIT 10
";
$sync_history = $conn->query($query);

ob_start();
?>

<!-- Page Header -->
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Hikvision Devices</h1>
    <div>
        <a href="<?= BASE_URL ?>/views/hikvision_enrollment.php" class="btn btn-sm btn-primary shadow-sm">
            <i class="fas fa-user-plus fa-sm text-white-50"></i> Manage Enrollments
        </a>
        <button onclick="syncAllDevices()" class="btn btn-sm btn-info shadow-sm ml-2">
            <i class="fas fa-sync fa-sm text-white-50"></i> Sync All Devices
        </button>
        <button type="button" class="btn btn-sm btn-success shadow-sm ml-2" data-toggle="modal" data-target="#addDeviceModal">
            <i class="fas fa-plus fa-sm text-white-50"></i> Add Device
        </button>
    </div>
</div>

<!-- Alert Messages -->
<?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
        <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-circle' ?> mr-2"></i>
        <?= $message ?>
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
<?php endif; ?>
            
<!-- Devices Card -->
<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
        <h6 class="m-0 font-weight-bold text-primary">Hikvision Biometric Devices</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered" id="devicesTable" width="100%" cellspacing="0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>IP Address</th>
                        <th>Location</th>
                        <?php if ($is_super_admin): ?>
                            <th>Church</th>
                        <?php endif; ?>
                        <th>Status</th>
                        <th>Enrollments</th>
                        <th>Last Activity</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($devices as $device): ?>
                        <tr>
                            <td><?= htmlspecialchars($device['name']) ?></td>
                            <td><?= htmlspecialchars($device['ip']) ?>:<?= htmlspecialchars($device['port']) ?></td>
                            <td><?= htmlspecialchars($device['location'] ?? 'N/A') ?></td>
                            <?php if ($is_super_admin): ?>
                                <td><?= htmlspecialchars($device['church_name'] ?? 'Unknown') ?></td>
                            <?php endif; ?>
                            <td>
                                <?php if ($device['is_active']): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-info"><?= intval($device['enrollment_count']) ?></span>
                            </td>
                            <td>
                                <?= $device['last_attendance'] ? date('Y-m-d H:i', strtotime($device['last_attendance'])) : 'Never' ?>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-primary" onclick="testConnection(<?= $device['id'] ?>)" title="Test Connection">
                                        <i class="fas fa-plug"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-info" onclick="syncDevice(<?= $device['id'] ?>)" title="Sync Attendance">
                                        <i class="fas fa-sync"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-success" onclick="manageEnrollments(<?= $device['id'] ?>)" title="Manage Enrollments">
                                        <i class="fas fa-users"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-warning" onclick="editDevice(<?= $device['id'] ?>)" title="Edit Device">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if ($device['is_active']): ?>
                                        <button type="button" class="btn btn-sm btn-secondary" onclick="toggleDevice(<?= $device['id'] ?>, <?= $device['is_active'] ?>)" title="Disable Device">
                                            <i class="fas fa-power-off"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-success" onclick="toggleDevice(<?= $device['id'] ?>, <?= $device['is_active'] ?>)" title="Enable Device">
                                            <i class="fas fa-power-off"></i>
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-danger" onclick="deleteDevice(<?= $device['id'] ?>)" title="Delete Device">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($devices)): ?>
                        <tr>
                            <td colspan="<?= $is_super_admin ? 8 : 7 ?>" class="text-center">No devices found. Add your first device to get started.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Sync History Card -->
<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
        <h6 class="m-0 font-weight-bold text-primary">Recent Sync History</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered" id="syncHistoryTable" width="100%" cellspacing="0">
                <thead>
                    <tr>
                        <th>Device</th>
                        <th>Start Time</th>
                        <th>End Time</th>
                        <th>Status</th>
                        <th>Records Synced</th>
                        <th>Records Processed</th>
                        <th>Error Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($sync_history && $sync_history->num_rows > 0): ?>
                        <?php while ($history = $sync_history->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($history['device_name']) ?></td>
                                <td><?= date('Y-m-d H:i:s', strtotime($history['start_time'])) ?></td>
                                <td><?= $history['end_time'] ? date('Y-m-d H:i:s', strtotime($history['end_time'])) : 'In Progress' ?></td>
                                <td>
                                    <?php if ($history['status'] === 'completed'): ?>
                                        <span class="badge badge-success">Completed</span>
                                    <?php elseif ($history['status'] === 'in_progress'): ?>
                                        <span class="badge badge-info">In Progress</span>
                                    <?php elseif ($history['status'] === 'failed'): ?>
                                        <span class="badge badge-danger">Failed</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary"><?= ucfirst($history['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format($history['records_synced']) ?></td>
                                <td><?= number_format($history['records_processed']) ?></td>
                                <td><?= htmlspecialchars($history['error_message'] ?? '') ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center">No sync history found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- Generate API Key Modal -->
<div class="modal fade" id="apiKeyModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Generate API Key</h4>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="generate_api_key">
                    <input type="hidden" name="device_id" id="api_device_id">
                    
                    <p>Are you sure you want to generate a new API key for this device?</p>
                    <p class="text-warning">This will invalidate any existing API keys for this device.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Generate Key</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Device Modal -->
<div class="modal fade" id="deleteDeviceModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Delete Device</h4>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form method="post" id="deleteDeviceForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="delete_device">
                    <input type="hidden" name="device_id" id="delete_device_id">
                    
                    <p>Are you sure you want to delete this device?</p>
                    <p class="text-danger">This action cannot be undone. Devices with attendance records cannot be deleted.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    $(document).ready(function() {
        // Initialize DataTables
        $('#devicesTable').DataTable({
            "order": [[0, "asc"]],
            "pageLength": 25
        });
        
        $('#syncHistoryTable').DataTable({
            "order": [[1, "desc"]],
            "pageLength": 10
        });
    });
    
    // Edit device
    function editDevice(id) {
        // Show loading spinner
        Swal.fire({
            title: 'Loading device details...',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        // Fetch device details via AJAX
        console.log('Edit device ID:', id);
        console.log('AJAX URL:', '<?= BASE_URL ?>/ajax/get_hikvision_device.php');
        
        $.ajax({
            url: '<?= BASE_URL ?>/ajax/get_hikvision_device.php',
            type: 'GET',
            data: { id: id },
            dataType: 'json',
            success: function(response) {
                Swal.close();
                if (response.success) {
                    const device = response.device;
                    document.getElementById('edit_device_id').value = device.id;
                    document.getElementById('edit_name').value = device.name;
                    document.getElementById('edit_ip_address').value = device.ip;
                    document.getElementById('edit_port').value = device.port;
                    document.getElementById('edit_username').value = device.username;
                    document.getElementById('edit_location').value = device.location;
                    document.getElementById('edit_is_active').checked = device.is_active == 1;
                    
                    <?php if ($is_super_admin): ?>
                    if (document.getElementById('edit_church_id')) {
                        document.getElementById('edit_church_id').value = device.church_id;
                    }
                    <?php endif; ?>
                    
                    $('#editDeviceModal').modal('show');
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.message || 'Failed to load device details'
                    });
                }
            },
            error: function() {
                Swal.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Network error occurred while fetching device details'
                });
            }
        });
    }
    
    // Test connection to device
    function testConnection(id) {
        console.log('Test connection for device ID:', id);
        console.log('AJAX URL:', '<?= BASE_URL ?>/ajax/test_hikvision_device.php');
        
        Swal.fire({
            title: 'Testing connection...',
            text: 'Please wait while we test the connection to the device',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        $.ajax({
            url: '<?= BASE_URL ?>/ajax/test_hikvision_device.php',
            type: 'POST',
            data: { device_id: id },
            dataType: 'json',
            success: function(response) {
                Swal.close();
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Connection Successful',
                        text: response.message || 'Successfully connected to the device'
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Connection Failed',
                        text: response.message || 'Failed to connect to the device'
                    });
                }
            },
            error: function() {
                Swal.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Network error occurred while testing connection'
                });
            }
        });
    }
    
    // Sync device attendance data
    function syncDevice(id) {
        Swal.fire({
            title: 'Sync Device',
            text: 'Are you sure you want to sync attendance data from this device?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, sync now',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Syncing attendance data...',
                    text: 'This may take a few moments',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                $.ajax({
                    url: '<?= BASE_URL ?>/ajax/sync_hikvision_device.php',
                    type: 'POST',
                    data: { device_id: id },
                    dataType: 'json',
                    success: function(response) {
                        Swal.close();
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Sync Initiated',
                                text: response.message || 'Sync process has been initiated successfully',
                                confirmButtonText: 'OK'
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Sync Failed',
                                text: response.message || 'Failed to initiate sync process'
                            });
                        }
                    },
                    error: function() {
                        Swal.close();
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Network error occurred while syncing device'
                        });
                    }
                });
            }
        });
    }
    
    // Sync all devices
    function syncAllDevices() {
        Swal.fire({
            title: 'Sync All Devices',
            text: 'Are you sure you want to sync attendance data from all active devices?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, sync all',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Syncing all devices...',
                    text: 'This may take several minutes',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                $.ajax({
                    url: '<?= BASE_URL ?>/ajax/sync_all_hikvision_devices.php',
                    type: 'POST',
                    dataType: 'json',
                    success: function(response) {
                        Swal.close();
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Sync Initiated',
                                text: response.message || 'Sync process has been initiated for all active devices',
                                confirmButtonText: 'OK'
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Sync Failed',
                                text: response.message || 'Failed to initiate sync process'
                            });
                        }
                    },
                    error: function() {
                        Swal.close();
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Network error occurred while syncing devices'
                        });
                    }
                });
            }
        });
    }
    
    // Toggle device active status
    function toggleDevice(id, currentStatus) {
        console.log('Toggle device ID:', id, 'Current status:', currentStatus);
        console.log('AJAX URL:', '<?= BASE_URL ?>/ajax/toggle_hikvision_device.php');
        
        const newStatus = currentStatus == 1 ? 0 : 1;
        const action = newStatus ? 'activate' : 'deactivate';
        Swal.fire({
            title: `${newStatus ? 'Activate' : 'Deactivate'} Device`,
            text: `Are you sure you want to ${action} this device?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: `Yes, ${action}`,
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: '<?= BASE_URL ?>/ajax/toggle_hikvision_device.php',
                    type: 'POST',
                    data: { device_id: id },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Success',
                                text: response.message || `Device ${newStatus ? 'activated' : 'deactivated'} successfully`,
                                confirmButtonText: 'OK'
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: response.message || `Failed to ${action} device`
                            });
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Network error occurred'
                        });
                    }
                });
            }
        });
    }
    
    // Delete device
    function deleteDevice(id) {
        Swal.fire({
            title: 'Delete Device',
            text: 'Are you sure you want to delete this device? This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, delete it',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#d33'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: '<?= BASE_URL ?>/ajax/delete_hikvision_device.php',
                    type: 'POST',
                    data: { device_id: id },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Success',
                                text: response.message || 'Device deleted successfully',
                                confirmButtonText: 'OK'
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: response.message || 'Failed to delete device'
                            });
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Network error occurred while deleting device'
                        });
                    }
                });
            }
        });
    }
    
    // Manage enrollments
    function manageEnrollments(id) {
        window.location.href = '<?= BASE_URL ?>/views/hikvision_enrollment.php?device_id=' + id;
    }
</script>

<?php
$content = ob_get_clean();
include __DIR__.'/../includes/template.php';
?>

<!-- Add Device Modal -->
<div class="modal fade" id="addDeviceModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Add Hikvision Device</h4>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_device">
                    
                    <div class="form-group">
                        <label for="name">Device Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="ip_address">IP Address <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="ip_address" name="ip_address" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="port">Port</label>
                        <input type="number" class="form-control" id="port" name="port" value="80">
                    </div>
                    
                    <div class="form-group">
                        <label for="username">Username <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="username" name="username" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="location">Location</label>
                        <input type="text" class="form-control" id="location" name="location">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Device</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function testConnection(deviceId) {
    console.log('Testing connection for device ID:', deviceId);
    if (confirm('Test connection to this device?')) {
        // Create a form and submit it
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '<?= BASE_URL ?>/ajax/test_hikvision_device.php';
        form.innerHTML = `
            <input type="hidden" name="device_id" value="${deviceId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function syncDevice(deviceId) {
    console.log('Syncing device ID:', deviceId);
    if (confirm('Sync attendance data from this device? This may take several minutes.')) {
        // Create a form and submit it
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '<?= BASE_URL ?>/ajax/sync_hikvision_device.php';
        form.innerHTML = `
            <input type="hidden" name="device_id" value="${deviceId}">
            <input type="hidden" name="sync_type" value="manual">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function deleteDevice(deviceId) {
    console.log('Deleting device ID:', deviceId);
    if (confirm('Are you sure you want to delete this device? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '<?= BASE_URL ?>/ajax/delete_hikvision_device.php';
        form.innerHTML = `
            <input type="hidden" name="device_id" value="${deviceId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function toggleDevice(deviceId, newStatus) {
    console.log('Toggling device ID:', deviceId, 'New status:', newStatus);
    const action = newStatus ? 'enable' : 'disable';
    if (confirm(`Are you sure you want to ${action} this device?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '<?= BASE_URL ?>/ajax/toggle_hikvision_device.php';
        form.innerHTML = `
            <input type="hidden" name="device_id" value="${deviceId}">
            <input type="hidden" name="is_active" value="${newStatus}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function editDevice(deviceId) {
    // Debug: Check if deviceId is properly passed
    console.log('editDevice called with deviceId:', deviceId);
    
    if (!deviceId || deviceId === 'undefined' || deviceId === '') {
        alert('Error: Device ID is missing or invalid');
        return;
    }
    
    // Fetch device data via AJAX
    const url = '<?= BASE_URL ?>/ajax/get_hikvision_device.php?id=' + deviceId;
    console.log('Fetching from URL:', url);
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Populate the edit modal with device data
                document.getElementById('edit_device_id').value = data.device.id;
                document.getElementById('edit_name').value = data.device.name;
                document.getElementById('edit_ip_address').value = data.device.ip_address;
                document.getElementById('edit_port').value = data.device.port;
                document.getElementById('edit_username').value = data.device.username;
                document.getElementById('edit_location').value = data.device.location || '';
                document.getElementById('edit_is_active').checked = data.device.is_active == 1;
                
                // Show the modal
                $('#editDeviceModal').modal('show');
            } else {
                alert('Error loading device data: ' + data.message);
            }
        })
        .catch(error => {
            alert('Error loading device data: ' + error.message);
        });
}
</script>

<!-- Edit Device Modal -->
<div class="modal fade" id="editDeviceModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Edit Hikvision Device</h4>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_device">
                    <input type="hidden" name="device_id" id="edit_device_id">
                    
                    <div class="form-group">
                        <label for="edit_name">Device Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_ip_address">IP Address <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_ip_address" name="ip_address" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_port">Port</label>
                        <input type="number" class="form-control" id="edit_port" name="port" value="80">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_username">Username <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_username" name="username" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_password">Password (leave blank to keep current)</label>
                        <input type="password" class="form-control" id="edit_password" name="password">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_location">Location</label>
                        <input type="text" class="form-control" id="edit_location" name="location">
                    </div>
                    
                    <div class="form-group">
                        <div class="custom-control custom-switch">
                            <input type="checkbox" class="custom-control-input" id="edit_is_active" name="is_active">
                            <label class="custom-control-label" for="edit_is_active">Active</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>