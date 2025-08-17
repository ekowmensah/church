<?php
//require_once '../includes/admin_auth.php';
//require_once '../config/database.php';
//require_once '../helpers/HikVisionService.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';
require_once __DIR__.'/../helpers/HikVisionService.php';

$page_title = "HikVision Devices";
$current_page = "hikvision_devices";

// Initialize variables from auth
$is_super_admin = $_SESSION['is_super_admin'] ?? false;
$church_id = $_SESSION['church_id'] ?? 1;

// Check permissions
if (!$is_super_admin && !has_permission('manage_hikvision_devices')) {
    header('HTTP/1.0 403 Forbidden');
    include '../views/errors/403.php';
    exit;
}

// Handle form submissions
$message = '';
$message_type = '';

// === API KEY MANAGEMENT ===
function getCurrentApiKey() {
    $key_file = __DIR__ . '/../config/hikvision_api_key.txt';
    if (file_exists($key_file)) {
        return trim(file_get_contents($key_file));
    }
    return '';
}

function setNewApiKey() {
    $key_file = __DIR__ . '/../config/hikvision_api_key.txt';
    $new_key = bin2hex(random_bytes(32));
    file_put_contents($key_file, $new_key);
    return $new_key;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'manage_api_key') {
    if (isset($_POST['rotate'])) {
        $new_key = setNewApiKey();
        $message = 'API Key rotated successfully!';
        $message_type = 'success';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_device':
                $result = addDevice($conn, $_POST, $church_id);
                $message = $result['message'];
                $message_type = $result['success'] ? 'success' : 'danger';
                break;
            case 'test_connection':
                $result = testDeviceConnection($conn, $_POST['device_id']);
                $message = $result['message'];
                $message_type = $result['success'] ? 'success' : 'danger';
                break;
            case 'sync_attendance':
                $result = syncDeviceAttendance($conn, $_POST['device_id'], $_POST['session_id'] ?? null);
                $message = $result['message'];
                $message_type = $result['success'] ? 'success' : 'warning';
                break;
        }
    }
}

// Get ALL devices first to debug
$all_devices_query = "SELECT d.*, c.name as church_name FROM hikvision_devices d LEFT JOIN churches c ON d.church_id = c.id ORDER BY d.id DESC";
$all_devices_result = $conn->query($all_devices_query);
$all_devices = $all_devices_result->fetch_all(MYSQLI_ASSOC);

// Show debug info directly on page
$debug_info = "<strong>Debug Info:</strong><br>";
$debug_info .= "Your church_id: $church_id<br>";
$debug_info .= "Total devices in database: " . count($all_devices) . "<br>";
if (!empty($all_devices)) {
    $debug_info .= "Device church_ids: ";
    foreach ($all_devices as $dev) {
        $debug_info .= $dev['church_id'] . ", ";
    }
    $debug_info = rtrim($debug_info, ", ") . "<br>";
}

// Get devices - if super admin, show all; otherwise show for user's church
if ($is_super_admin) {
    $devices_query = "SELECT d.*, c.name as church_name FROM hikvision_devices d LEFT JOIN churches c ON d.church_id = c.id ORDER BY d.id DESC";
    $devices_stmt = $conn->prepare($devices_query);
    $devices_stmt->execute();
    $devices = $devices_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $devices_query = "SELECT d.*, c.name as church_name FROM hikvision_devices d LEFT JOIN churches c ON d.church_id = c.id WHERE d.church_id = ? ORDER BY d.id DESC";
    $devices_stmt = $conn->prepare($devices_query);
    $devices_stmt->bind_param("i", $church_id);
    $devices_stmt->execute();
    $devices = $devices_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get attendance sessions for sync dropdown
$sessions_query = "SELECT id, title, service_date FROM attendance_sessions WHERE church_id = ? ORDER BY service_date DESC LIMIT 10";
$sessions_stmt = $conn->prepare($sessions_query);
$sessions_stmt->bind_param("i", $church_id);
$sessions_stmt->execute();
$sessions = $sessions_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

function addDevice($conn, $data, $default_church_id) {
    $church_id = isset($data['church_id']) && is_numeric($data['church_id']) ? intval($data['church_id']) : $default_church_id;
    try {
        $stmt = $conn->prepare("
            INSERT INTO hikvision_devices 
            (church_id, device_name, device_model, ip_address, port, username, password, location) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("isssisss", 
            $church_id,
            $data['device_name'],
            $data['device_model'],
            $data['ip_address'],
            $data['port'],
            $data['username'],
            $data['password'],
            $data['location']
        );
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'Device added successfully'];
        } else {
            return ['success' => false, 'message' => 'Failed to add device: ' . $conn->error];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

function testDeviceConnection($conn, $device_id) {
    try {
        $service = new HikVisionService($conn, $device_id);
        $result = $service->testConnection();
        
        if ($result['success']) {
            return ['success' => true, 'message' => 'Device connection successful'];
        } else {
            return ['success' => false, 'message' => 'Connection failed: ' . $result['error']];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

function syncDeviceAttendance($conn, $device_id, $session_id) {
    try {
        $service = new HikVisionService($conn, $device_id);
        $result = $service->syncAttendance($session_id);
        
        if ($result['success']) {
            $message = "Sync completed. {$result['synced_count']} records processed.";
            if (!empty($result['errors'])) {
                $message .= " Errors: " . implode(', ', array_slice($result['errors'], 0, 3));
            }
            return ['success' => true, 'message' => $message];
        } else {
            return ['success' => false, 'message' => 'Sync failed: ' . $result['error']];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

ob_start();
?>

<div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0">HikVision Devices</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="../index.php">Home</a></li>
                        <li class="breadcrumb-item active">HikVision Devices</li>
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

            <!-- Header with Actions -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <h2 class="text-dark mb-0">
                        <i class="fas fa-video text-primary mr-2"></i>
                        HikVision Devices Management
                    </h2>
                    <p class="text-muted mb-0">Manage face recognition devices and sync attendance data</p>
                </div>
                <div class="col-md-4 text-right">
                    <button type="button" class="btn btn-primary btn-lg" data-toggle="modal" data-target="#addDeviceModal">
                        <i class="fas fa-plus mr-1"></i> Add Device
                    </button>
                    <button type="button" class="btn btn-outline-info ml-2" onclick="location.reload()">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
            </div>

            <!-- Devices List -->
            <div class="card shadow-sm">
                <div class="card-header bg-white border-bottom">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list text-primary mr-2"></i>
                            Registered Devices
                            <span class="badge badge-primary ml-2"><?php echo count($devices); ?></span>
                        </h5>
                        <?php if (!empty($devices)): ?>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary" onclick="toggleView('table')">
                                <i class="fas fa-table"></i> Table
                            </button>
                            <button class="btn btn-outline-secondary" onclick="toggleView('cards')">
                                <i class="fas fa-th-large"></i> Cards
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($devices)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No devices registered yet for church ID: <?php echo $church_id; ?>
                            <br><small><?php echo $debug_info; ?></small>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>Church</th>
                                        <th>Device Name</th>
                                        <th>Model</th>
                                        <th>IP Address</th>
                                        <th>Location</th>
                                        <th>Status</th>
                                        <th>Last Sync</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($devices as $device): ?>
                                        <tr>
                                            <td>
    <?php echo htmlspecialchars($device['church_name'] ?? ''); ?>
</td>
                                            <td><?php echo htmlspecialchars($device['device_name']); ?></td>
                                            <td><?php echo htmlspecialchars($device['device_model']); ?></td>
                                            <td><?php echo htmlspecialchars($device['ip_address']) . ':' . $device['port']; ?></td>
                                            <td><?php echo htmlspecialchars($device['location'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php
                                                $status_class = [
                                                    'connected' => 'success',
                                                    'disconnected' => 'secondary',
                                                    'error' => 'danger'
                                                ];
                                                $status = $device['sync_status'];
                                                ?>
                                                <span class="badge badge-<?php echo $status_class[$status] ?? 'secondary'; ?>">
                                                    <?php echo ucfirst($status); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                echo $device['last_sync'] 
                                                    ? date('M j, Y g:i A', strtotime($device['last_sync']))
                                                    : 'Never';
                                                ?>
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <!-- Test Connection -->
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="test_connection">
                                                        <input type="hidden" name="device_id" value="<?php echo $device['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-info" title="Test Connection" data-toggle="tooltip">
                                                            <i class="fas fa-plug"></i>
                                                        </button>
                                                    </form>
                                                    
                                                    <!-- Sync Attendance -->
                                                    <button type="button" class="btn btn-sm btn-outline-success" 
                                                            onclick="showSyncModal(<?php echo $device['id']; ?>, '<?php echo htmlspecialchars($device['device_name']); ?>')"
                                                            title="Sync Attendance" data-toggle="tooltip">
                                                        <i class="fas fa-sync"></i>
                                                    </button>
                                                    
                                                    <!-- Manage Users -->
                                                    <a href="hikvision_enrollment.php?device_id=<?php echo $device['id']; ?>" 
                                                       class="btn btn-sm btn-outline-primary" title="Manage Users" data-toggle="tooltip">
                                                        <i class="fas fa-users"></i>
                                                    </a>
                                                    
                                                    <!-- API Key Management -->
                                                    <button type="button" class="btn btn-sm btn-outline-warning" title="View/Rotate API Key" data-toggle="tooltip" onclick="showApiKeyModal(<?php echo $device['id']; ?>)">
                                                        <i class="fas fa-key"></i>
                                                    </button>
                                                    
                                                    <!-- Device Settings -->
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                            onclick="editDevice(<?php echo $device['id']; ?>)"
                                                            title="Edit Device" data-toggle="tooltip">
                                                        <i class="fas fa-cog"></i>
                                                    </button>
                                                    
                                                    <!-- Delete Device -->
                                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                                            onclick="deleteDevice(<?php echo $device['id']; ?>, '<?php echo htmlspecialchars($device['device_name']); ?>')"
                                                            title="Delete Device" data-toggle="tooltip">
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
        </div>
    </section>
</div>

<!-- Add Device Modal -->
<div class="modal fade" id="addDeviceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" id="addDeviceForm" autocomplete="off">
                <div class="modal-header">
                    <h4 class="modal-title"><i class="fas fa-plus"></i> Add New Device</h4>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_device">
                    
                    <div class="form-row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="device_name">Device Name</label>
                                <input type="text" class="form-control" id="device_name" name="device_name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="church_id">Church</label>
                                <select class="form-control" id="church_id" name="church_id" required>
                                    <?php
                                    $churches_query = "SELECT id, name FROM churches ORDER BY name";
                                    $churches_result = $conn->query($churches_query);
                                    while ($church = $churches_result->fetch_assoc()):
                                    ?>
                                        <option value="<?php echo $church['id']; ?>" <?php echo ($church['id'] == $church_id) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($church['name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="device_model">Device Model</label>
                                <input type="text" class="form-control" id="device_model" name="device_model" value="DS-K1T320MFWX">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="ip_address">IP Address</label>
                                <input type="text" class="form-control" id="ip_address" name="ip_address" required 
                                       pattern="^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$" placeholder="192.168.1.100">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="port">Port</label>
                                <input type="number" class="form-control" id="port" name="port" value="80" min="1" max="65535">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="username">Username</label>
                                <input type="text" class="form-control" id="username" name="username" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="password">Password</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="location">Location</label>
                                <input type="text" class="form-control" id="location" name="location" placeholder="Main Entrance, Side Door, etc.">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Add Device
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Sync Modal -->
<div class="modal fade" id="syncModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h4 class="modal-title">Sync Attendance</h4>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="sync_attendance">
                    <input type="hidden" name="device_id" id="sync_device_id">
                    
                    <p>Sync attendance data from <strong id="sync_device_name"></strong></p>
                    
                    <div class="form-group">
                        <label for="session_id">Link to Attendance Session (Optional)</label>
                        <select class="form-control" name="session_id" id="session_id">
                            <option value="">-- No specific session --</option>
                            <?php foreach ($sessions as $session): ?>
                                <option value="<?php echo $session['id']; ?>">
                                    <?php echo htmlspecialchars($session['title']) . ' - ' . date('M j, Y', strtotime($session['service_date'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text text-muted">
                            If selected, attendance will be linked to this specific session.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-sync"></i> Start Sync
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Initialize tooltips
$(document).ready(function() {
    $('[data-toggle="tooltip"]').tooltip();
});

function showSyncModal(deviceId, deviceName) {
    document.getElementById('sync_device_id').value = deviceId;
    document.getElementById('sync_device_name').textContent = deviceName;
    $('#syncModal').modal('show');
}

function editDevice(deviceId) {
    // TODO: Implement edit device functionality
    alert('Edit device functionality coming soon!');
}

function deleteDevice(deviceId, deviceName) {
    if (confirm('Are you sure you want to delete device "' + deviceName + '"? This action cannot be undone.')) {
        // Create form and submit
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="delete_device">' +
                        '<input type="hidden" name="device_id" value="' + deviceId + '">';
        document.body.appendChild(form);
        form.submit();
    }
}

function toggleView(viewType) {
    // TODO: Implement card/table view toggle
    console.log('Toggle to ' + viewType + ' view');
}
</script>

<?php 
// API Key Modal (partial)
include __DIR__.'/partials/hikvision_api_key_modal.php';
$page_content = ob_get_clean();
include '../includes/layout.php';
?>
