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
require_once __DIR__.'/../includes/admin_auth.php';

// Only allow users with appropriate permissions
if (!has_permission('manage_hikvision_devices')) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// Initialize variables
$success = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_device':
                // Add new device
                $name = $_POST['name'] ?? '';
                $ip = $_POST['ip_address'] ?? '';
                $port = $_POST['port'] ?? 80;
                $username = $_POST['username'] ?? '';
                $password = $_POST['password'] ?? '';
                $location = $_POST['location'] ?? '';
                
                if (empty($name) || empty($ip) || empty($username) || empty($password)) {
                    $error = 'All required fields must be filled out.';
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO hikvision_devices 
                        (name, ip_address, port, username, password, location, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, 1)
                    ");
                    $stmt->bind_param('ssisss', $name, $ip, $port, $username, $password, $location);
                    
                    if ($stmt->execute()) {
                        $device_id = $stmt->insert_id;
                        $success = "Device '{$name}' added successfully.";
                    } else {
                        $error = "Failed to add device: " . $conn->error;
                    }
                }
                break;
                
            case 'edit_device':
                // Edit existing device
                $device_id = $_POST['device_id'] ?? 0;
                $name = $_POST['name'] ?? '';
                $ip = $_POST['ip_address'] ?? '';
                $port = $_POST['port'] ?? 80;
                $username = $_POST['username'] ?? '';
                $password = $_POST['password'] ?? '';
                $location = $_POST['location'] ?? '';
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                
                if (empty($device_id) || empty($name) || empty($ip)) {
                    $error = 'All required fields must be filled out.';
                } else {
                    // Check if password is being updated
                    if (empty($password)) {
                        $stmt = $conn->prepare("
                            UPDATE hikvision_devices 
                            SET name = ?, ip_address = ?, port = ?, username = ?, location = ?, is_active = ?
                            WHERE id = ?
                        ");
                        $stmt->bind_param('ssissii', $name, $ip, $port, $username, $location, $is_active, $device_id);
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE hikvision_devices 
                            SET name = ?, ip_address = ?, port = ?, username = ?, password = ?, location = ?, is_active = ?
                            WHERE id = ?
                        ");
                        $stmt->bind_param('ssisssii', $name, $ip, $port, $username, $password, $location, $is_active, $device_id);
                    }
                    
                    if ($stmt->execute()) {
                        $success = "Device '{$name}' updated successfully.";
                    } else {
                        $error = "Failed to update device: " . $conn->error;
                    }
                }
                break;
                
            case 'generate_api_key':
                // Generate API key for device
                $device_id = $_POST['device_id'] ?? 0;
                
                if (empty($device_id)) {
                    $error = 'Invalid device selected.';
                } else {
                    // Generate a secure random API key
                    $api_key = bin2hex(random_bytes(32));
                    $expires_at = date('Y-m-d H:i:s', strtotime('+1 year'));
                    
                    // Deactivate any existing keys for this device
                    $stmt = $conn->prepare("
                        UPDATE hikvision_api_keys 
                        SET is_active = 0 
                        WHERE device_id = ?
                    ");
                    $stmt->bind_param('i', $device_id);
                    $stmt->execute();
                    
                    // Insert new API key
                    $stmt = $conn->prepare("
                        INSERT INTO hikvision_api_keys 
                        (device_id, api_key, expires_at, is_active)
                        VALUES (?, ?, ?, 1)
                    ");
                    $stmt->bind_param('iss', $device_id, $api_key, $expires_at);
                    
                    if ($stmt->execute()) {
                        $success = "API key generated successfully. Please copy this key now as it won't be shown again: <strong>{$api_key}</strong>";
                    } else {
                        $error = "Failed to generate API key: " . $conn->error;
                    }
                }
                break;
                
            case 'delete_device':
                // Delete device
                $device_id = $_POST['device_id'] ?? 0;
                
                if (empty($device_id)) {
                    $error = 'Invalid device selected.';
                } else {
                    // Check if device has attendance records
                    $stmt = $conn->prepare("
                        SELECT COUNT(*) as count FROM hikvision_attendance_logs 
                        WHERE device_id = ?
                    ");
                    $stmt->bind_param('i', $device_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $row = $result->fetch_assoc();
                    
                    if ($row['count'] > 0) {
                        $error = "Cannot delete device with attendance records. Deactivate it instead.";
                    } else {
                        // Delete API keys first
                        $stmt = $conn->prepare("DELETE FROM hikvision_api_keys WHERE device_id = ?");
                        $stmt->bind_param('i', $device_id);
                        $stmt->execute();
                        
                        // Delete device
                        $stmt = $conn->prepare("DELETE FROM hikvision_devices WHERE id = ?");
                        $stmt->bind_param('i', $device_id);
                        
                        if ($stmt->execute()) {
                            $success = "Device deleted successfully.";
                        } else {
                            $error = "Failed to delete device: " . $conn->error;
                        }
                    }
                }
                break;
        }
    }
}

// Get all devices
$query = "SELECT * FROM hikvision_devices ORDER BY name";
$devices = $conn->query($query);

// Get sync history
$query = "
    SELECT h.*, d.name as device_name 
    FROM hikvision_sync_history h
    JOIN hikvision_devices d ON h.device_id = d.id
    ORDER BY h.start_time DESC
    LIMIT 10
";
$sync_history = $conn->query($query);

// Page title
$pageTitle = 'Hikvision Devices';
include '../includes/header.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>Hikvision Devices</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/dashboard.php">Home</a></li>
                        <li class="breadcrumb-item active">Hikvision Devices</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                    <?= $success ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                    <?= $error ?>
                </div>
            <?php endif; ?>
            
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Device List</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#addDeviceModal">
                                    <i class="fas fa-plus"></i> Add Device
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>IP Address</th>
                                        <th>Location</th>
                                        <th>Status</th>
                                        <th>Last Sync</th>
                                        <th>Total Records</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($devices && $devices->num_rows > 0): ?>
                                        <?php while ($device = $devices->fetch_assoc()): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($device['name']) ?></td>
                                                <td><?= htmlspecialchars($device['ip_address']) ?>:<?= htmlspecialchars($device['port']) ?></td>
                                                <td><?= htmlspecialchars($device['location']) ?></td>
                                                <td>
                                                    <?php if ($device['is_active']): ?>
                                                        <span class="badge badge-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-danger">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= $device['last_sync'] ? date('Y-m-d H:i', strtotime($device['last_sync'])) : 'Never' ?></td>
                                                <td><?= number_format($device['total_records']) ?></td>
                                                <td>
                                                    <button class="btn btn-info btn-sm" onclick="editDevice(<?= $device['id'] ?>, '<?= htmlspecialchars($device['name']) ?>', '<?= htmlspecialchars($device['ip_address']) ?>', <?= $device['port'] ?>, '<?= htmlspecialchars($device['username']) ?>', '<?= htmlspecialchars($device['location']) ?>', <?= $device['is_active'] ?>)">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                    <button class="btn btn-primary btn-sm" onclick="generateApiKey(<?= $device['id'] ?>)">
                                                        <i class="fas fa-key"></i> API Key
                                                    </button>
                                                    <button class="btn btn-danger btn-sm" onclick="deleteDevice(<?= $device['id'] ?>, '<?= htmlspecialchars($device['name']) ?>')">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center">No devices found</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Recent Sync History</h3>
                        </div>
                        <div class="card-body">
                            <table class="table table-bordered table-striped">
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
                                                        <span class="badge badge-warning">In Progress</span>
                                                    <?php elseif ($history['status'] === 'failed'): ?>
                                                        <span class="badge badge-danger">Failed</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-info"><?= ucfirst($history['status']) ?></span>
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
            </div>
        </div>
    </section>
</div>

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
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="delete_device">
                    <input type="hidden" name="device_id" id="delete_device_id">
                    
                    <p>Are you sure you want to delete the device <span id="delete_device_name" class="font-weight-bold"></span>?</p>
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
    // Edit device
    function editDevice(id, name, ip, port, username, location, isActive) {
        document.getElementById('edit_device_id').value = id;
        document.getElementById('edit_name').value = name;
        document.getElementById('edit_ip_address').value = ip;
        document.getElementById('edit_port').value = port;
        document.getElementById('edit_username').value = username;
        document.getElementById('edit_location').value = location;
        document.getElementById('edit_is_active').checked = isActive == 1;
        
        $('#editDeviceModal').modal('show');
    }
    
    // Generate API key
    function generateApiKey(id) {
        document.getElementById('api_device_id').value = id;
        $('#apiKeyModal').modal('show');
    }
    
    // Delete device
    function deleteDevice(id, name) {
        document.getElementById('delete_device_id').value = id;
        document.getElementById('delete_device_name').textContent = name;
        $('#deleteDeviceModal').modal('show');
    }
</script>

<?php include '../includes/footer.php'; ?>
