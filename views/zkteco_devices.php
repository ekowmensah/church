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
                $device_name = trim($_POST['device_name']);
                $ip_address = trim($_POST['ip_address']);
                $port = intval($_POST['port']);
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
                
                if (empty($device_name) || empty($ip_address)) {
                    $message = 'Device name and IP address are required.';
                    $messageType = 'error';
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO zkteco_devices (device_name, ip_address, port, location, church_id, is_active) 
                        VALUES (?, ?, ?, ?, ?, TRUE)
                    ");
                    $stmt->bind_param('ssisi', $device_name, $ip_address, $port, $location, $church_id);
                    
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
                $device_name = trim($_POST['device_name']);
                $ip_address = trim($_POST['ip_address']);
                $port = intval($_POST['port']);
                $location = trim($_POST['location']);
                
                // Get church_id from form (if super admin) or from user's church
                if ($is_super_admin && isset($_POST['church_id']) && !empty($_POST['church_id'])) {
                    $new_church_id = intval($_POST['church_id']);
                    // Validate that the selected church exists
                    $church_check = $conn->prepare('SELECT id FROM churches WHERE id = ?');
                    $church_check->bind_param('i', $new_church_id);
                    $church_check->execute();
                    if ($church_check->get_result()->num_rows === 0) {
                        $message = 'Selected church does not exist.';
                        $messageType = 'error';
                        $church_check->close();
                        break;
                    }
                    $church_check->close();
                } else {
                    $new_church_id = get_user_church_id($conn);
                }
                
                if (empty($device_name) || empty($ip_address)) {
                    $message = 'Device name and IP address are required.';
                    $messageType = 'error';
                } else {
                    // For super admin, allow updating church_id; for others, maintain existing church restriction
                    if ($is_super_admin) {
                        $stmt = $conn->prepare("
                            UPDATE zkteco_devices 
                            SET device_name = ?, ip_address = ?, port = ?, location = ?, church_id = ?
                            WHERE id = ?
                        ");
                        $stmt->bind_param('ssiiii', $device_name, $ip_address, $port, $location, $new_church_id, $device_id);
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE zkteco_devices 
                            SET device_name = ?, ip_address = ?, port = ?, location = ?
                            WHERE id = ? AND church_id = ?
                        ");
                        $stmt->bind_param('ssiiii', $device_name, $ip_address, $port, $location, $device_id, $new_church_id);
                    }
                    
                    if ($stmt->execute() && $stmt->affected_rows > 0) {
                        $stmt->close();
                        header('Location: zkteco_devices.php?success=device_updated');
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
                $check_stmt = $conn->prepare("
                    SELECT 
                        (SELECT COUNT(*) FROM member_biometric_data WHERE device_id = ?) as enrollments,
                        (SELECT COUNT(*) FROM zkteco_raw_logs WHERE device_id = ?) as logs
                ");
                $check_stmt->bind_param('ii', $device_id, $device_id);
                $check_stmt->execute();
                $result = $check_stmt->get_result()->fetch_assoc();
                $check_stmt->close();
                
                if ($result['enrollments'] > 0 || $result['logs'] > 0) {
                    $message = 'Cannot delete device: It has ' . $result['enrollments'] . ' enrollments and ' . $result['logs'] . ' attendance logs. Disable it instead.';
                    $messageType = 'error';
                } else {
                    $stmt = $conn->prepare("DELETE FROM zkteco_devices WHERE id = ? AND church_id = ?");
                    $stmt->bind_param('ii', $device_id, $church_id);
                    
                    if ($stmt->execute() && $stmt->affected_rows > 0) {
                        $message = 'Device deleted successfully!';
                        $messageType = 'success';
                    } else {
                        $message = 'Error deleting device or device not found.';
                        $messageType = 'error';
                    }
                    $stmt->close();
                }
                break;
                
            case 'test_connection':
                $device_id = intval($_POST['device_id']);
                $result = $zkService->testDeviceConnection($device_id);
                
                if ($result['success']) {
                    $message = $result['message'] . " (Version: {$result['version']}, Users: {$result['users']}, Records: {$result['records']})";
                    $messageType = 'success';
                } else {
                    $message = $result['message'];
                    $messageType = 'error';
                }
                break;
                
            case 'sync_device':
                $device_id = intval($_POST['device_id']);
                $result = $zkService->syncDeviceAttendance($device_id, 'manual', isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 1);
                
                if ($result['success']) {
                    $message = $result['message'];
                    $messageType = 'success';
                } else {
                    $message = $result['message'];
                    $messageType = 'error';
                }
                break;
                
            case 'toggle_device':
                $device_id = intval($_POST['device_id']);
                $is_active = intval($_POST['is_active']);
                
                $stmt = $conn->prepare("UPDATE zkteco_devices SET is_active = ? WHERE id = ?");
                $stmt->bind_param('ii', $is_active, $device_id);
                
                if ($stmt->execute()) {
                    $status = $is_active ? 'enabled' : 'disabled';
                    $message = "Device {$status} successfully!";
                    $messageType = 'success';
                } else {
                    $message = 'Error updating device status.';
                    $messageType = 'error';
                }
                break;
        }
    }
}

// Get all churches for device assignment (super admin can see all, others see their own)
$churches_query = "SELECT id, name FROM churches";
if (!$is_super_admin) {
    $user_church_id = get_user_church_id($conn);
    $churches_query .= " WHERE id = ?";
    $stmt = $conn->prepare($churches_query . " ORDER BY name");
    $stmt->bind_param('i', $user_church_id);
} else {
    $stmt = $conn->prepare($churches_query . " ORDER BY name");
}
$stmt->execute();
$churches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get all devices for current church
$devices_query = "
    SELECT zd.*, 
           COUNT(mbd.id) as enrolled_members,
           COUNT(zrl.id) as total_logs,
           MAX(zsh.sync_end) as last_sync
    FROM zkteco_devices zd
    LEFT JOIN member_biometric_data mbd ON zd.id = mbd.device_id AND mbd.is_active = TRUE
    LEFT JOIN zkteco_raw_logs zrl ON zd.id = zrl.device_id
    LEFT JOIN zkteco_sync_history zsh ON zd.id = zsh.device_id AND zsh.sync_status = 'success'
    WHERE zd.church_id = ?
    GROUP BY zd.id
    ORDER BY zd.created_at DESC
";
$stmt = $conn->prepare($devices_query);
$church_id = get_user_church_id($conn);
$stmt->bind_param('i', $church_id);
$stmt->execute();
$devices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0 text-gray-800"><i class="fas fa-microchip mr-2"></i>ZKTeco Device Management</h1>
</div>
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?> alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Add Device Card -->
            <div class="card card-primary">
                <div class="card-header">
                    <h3 class="card-title">Add New ZKTeco Device</h3>
                </div>
                <form method="POST">
                    <div class="card-body">
                        <input type="hidden" name="action" value="add_device">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="device_name">Device Name *</label>
                                    <input type="text" class="form-control" id="device_name" name="device_name" required>
                                    <small class="form-text text-muted">e.g., "Main Entrance Scanner"</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="church_id">Church <?php echo $is_super_admin ? '*' : ''; ?></label>
                                    <?php if ($is_super_admin): ?>
                                        <select class="form-control" id="church_id" name="church_id" required>
                                            <option value="">Select Church...</option>
                                            <?php foreach ($churches as $church): ?>
                                                <option value="<?php echo $church['id']; ?>">
                                                    <?php echo htmlspecialchars($church['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Select which church this device belongs to</small>
                                    <?php else: ?>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($churches[0]['name'] ?? 'No Church'); ?>" readonly>
                                        <small class="form-text text-muted">Device will be assigned to your church</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="ip_address">IP Address *</label>
                                    <input type="text" class="form-control" id="ip_address" name="ip_address" 
                                           pattern="^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$" required>
                                    <small class="form-text text-muted">e.g., "192.168.1.100"</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="port">Port</label>
                                    <input type="number" class="form-control" id="port" name="port" value="4370" min="1" max="65535">
                                    <small class="form-text text-muted">Default: 4370</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="location">Location</label>
                                    <input type="text" class="form-control" id="location" name="location">
                                    <small class="form-text text-muted">Physical location</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary">Add Device</button>
                    </div>
                </form>
            </div>

            <!-- Devices List -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Configured Devices</h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-sm btn-success" onclick="syncAllDevices()">
                            <i class="fas fa-sync"></i> Sync All Devices
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($devices)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No ZKTeco devices configured yet. Add your first device above.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>Device Name</th>
                                        <th>IP Address</th>
                                        <th>Location</th>
                                        <th>Status</th>
                                        <th>Enrolled Members</th>
                                        <th>Total Logs</th>
                                        <th>Last Sync</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($devices as $device): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($device['device_name']); ?></strong>
                                                <?php if ($device['firmware_version']): ?>
                                                    <br><small class="text-muted">v<?php echo htmlspecialchars($device['firmware_version']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($device['ip_address']); ?>:<?php echo $device['port']; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($device['location'] ?: '-'); ?></td>
                                            <td>
                                                <?php if ($device['is_active']): ?>
                                                    <span class="badge badge-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-info"><?php echo $device['enrolled_members']; ?></span>
                                            </td>
                                            <td>
                                                <span class="badge badge-primary"><?php echo $device['total_logs']; ?></span>
                                            </td>
                                            <td>
                                                <?php if ($device['last_sync']): ?>
                                                    <small><?php echo date('M j, Y H:i', strtotime($device['last_sync'])); ?></small>
                                                <?php else: ?>
                                                    <small class="text-muted">Never</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-info" 
                                                            onclick="testConnection(<?php echo $device['id']; ?>)"
                                                            title="Test Connection">
                                                        <i class="fas fa-plug"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-success" 
                                                            onclick="syncDevice(<?php echo $device['id']; ?>)"
                                                            title="Sync Attendance">
                                                        <i class="fas fa-sync"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-primary" 
                                                            onclick="viewStats(<?php echo $device['id']; ?>)"
                                                            title="View Statistics">
                                                        <i class="fas fa-chart-bar"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-purple" 
                                                            onclick="manageEnrollments(<?php echo $device['id']; ?>)"
                                                            title="Manage Enrollments">
                                                        <i class="fas fa-fingerprint"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-secondary" 
                                                            onclick="editDevice(<?php echo $device['id']; ?>)"
                                                            title="Edit Device">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-<?php echo $device['is_active'] ? 'warning' : 'success'; ?>" 
                                                            onclick="toggleDevice(<?php echo $device['id']; ?>, <?php echo $device['is_active'] ? 0 : 1; ?>)"
                                                            title="<?php echo $device['is_active'] ? 'Disable' : 'Enable'; ?>">
                                                        <i class="fas fa-<?php echo $device['is_active'] ? 'pause' : 'play'; ?>"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-danger" 
                                                            onclick="deleteDevice(<?php echo $device['id']; ?>)"
                                                            title="Delete Device">
                                                        <i class="fas fa-trash"></i>
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



<script>
function testConnection(deviceId) {
    if (confirm('Test connection to this device?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="test_connection">
            <input type="hidden" name="device_id" value="${deviceId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function syncDevice(deviceId) {
    if (confirm('Sync attendance data from this device? This may take a few moments.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="sync_device">
            <input type="hidden" name="device_id" value="${deviceId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function toggleDevice(deviceId, newStatus) {
    const action = newStatus ? 'enable' : 'disable';
    if (confirm(`Are you sure you want to ${action} this device?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="toggle_device">
            <input type="hidden" name="device_id" value="${deviceId}">
            <input type="hidden" name="is_active" value="${newStatus}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function syncAllDevices() {
    if (confirm('Sync attendance data from all active devices? This may take several minutes.')) {
        // This would require AJAX implementation for better UX
        alert('Sync all devices functionality will be implemented with AJAX for better user experience.');
    }
}

function editDevice(deviceId) {
    // Get device data and populate edit modal
    fetch('ajax_get_device.php?device_id=' + deviceId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('edit_device_id').value = data.device.id;
                document.getElementById('edit_device_name').value = data.device.device_name;
                document.getElementById('edit_ip_address').value = data.device.ip_address;
                document.getElementById('edit_port').value = data.device.port;
                document.getElementById('edit_location').value = data.device.location;
                $('#editDeviceModal').modal('show');
            } else {
                alert('Error loading device data: ' + data.message);
            }
        })
        .catch(error => {
            alert('Error loading device data: ' + error.message);
        });
}

function deleteDevice(deviceId) {
    if (confirm('Are you sure you want to delete this device? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_device">
            <input type="hidden" name="device_id" value="${deviceId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function editDevice(deviceId) {
    // Fetch device data via AJAX
    fetch('ajax_get_device.php?device_id=' + deviceId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Populate the edit modal with device data
                document.getElementById('edit_device_id').value = data.device.id;
                document.getElementById('edit_device_name').value = data.device.device_name;
                document.getElementById('edit_ip_address').value = data.device.ip_address;
                document.getElementById('edit_port').value = data.device.port;
                document.getElementById('edit_location').value = data.device.location || '';
                
                // Handle church selection based on user role
                <?php if ($is_super_admin): ?>
                    document.getElementById('edit_church_id').value = data.device.church_id;
                <?php else: ?>
                    document.getElementById('edit_church_name').value = data.device.church_name || 'Unknown Church';
                    document.getElementById('edit_church_id').value = data.device.church_id;
                <?php endif; ?>
                
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

function manageEnrollments(deviceId) {
    // Redirect to enrollment page with device filter
    window.location.href = 'zkteco_enrollment.php?device_id=' + deviceId;
}

function bulkEnrollDevice(deviceId) {
    // Redirect to bulk enrollment page
    window.location.href = 'zkteco_bulk_enrollment.php?device_id=' + deviceId;
}

function viewStats(deviceId) {
    $('#statsModal').modal('show');
    
    // Load device statistics via AJAX
    fetch('zkteco_device_stats.php?device_id=' + deviceId)
        .then(response => response.text())
        .then(html => {
            document.getElementById('statsContent').innerHTML = html;
        })
        .catch(error => {
            document.getElementById('statsContent').innerHTML = 
                '<div class="alert alert-danger">Error loading statistics: ' + error.message + '</div>';
        });
}
</script>

<?php
// Following visitor_list.php modal pattern
ob_start();
include 'zkteco_stats_modal.php';
include 'zkteco_edit_device_modal.php';
$modal_html = ob_get_clean();
$page_content = ob_get_clean();
require_once __DIR__.'/../includes/layout.php';
?>
