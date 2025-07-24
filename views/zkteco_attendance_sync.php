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
            case 'sync_and_map':
                $device_id = isset($_POST['device_id']) ? intval($_POST['device_id']) : null;
                $session_id = isset($_POST['session_id']) ? intval($_POST['session_id']) : null;
                
                // First sync attendance data
                if ($device_id) {
                    $sync_result = $zkService->syncDeviceAttendance($device_id, 'manual', isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 1);
                } else {
                    $sync_result = $zkService->syncAllDevices(isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 1);
                }
                
                if ($sync_result['success'] || (is_array($sync_result) && count($sync_result) > 0)) {
                    // Then map to sessions
                    $mapped_count = $zkService->mapLogsToSessions($device_id, $session_id);
                    
                    $sync_count = is_array($sync_result) ? 
                        array_sum(array_column(array_filter($sync_result, function($r) { return $r['success']; }), 'records')) :
                        $sync_result['records'];
                    
                    $message = "Sync completed! {$sync_count} records synced, {$mapped_count} mapped to attendance sessions.";
                    $messageType = 'success';
                } else {
                    $error_msg = is_array($sync_result) ? 
                        implode(', ', array_column($sync_result, 'message')) :
                        $sync_result['message'];
                    $message = "Sync failed: {$error_msg}";
                    $messageType = 'error';
                }
                break;
                
            case 'map_only':
                $device_id = isset($_POST['device_id']) ? intval($_POST['device_id']) : null;
                $session_id = isset($_POST['session_id']) ? intval($_POST['session_id']) : null;
                
                $mapped_count = $zkService->mapLogsToSessions($device_id, $session_id);
                $message = "Mapping completed! {$mapped_count} attendance records created from existing logs.";
                $messageType = 'success';
                break;
        }
    }
}

// Get active devices
$devices_query = "SELECT id, device_name, location FROM zkteco_devices WHERE is_active = TRUE AND church_id = ? ORDER BY device_name";
$stmt = $conn->prepare($devices_query);
$church_id = get_user_church_id($conn);
$stmt->bind_param('i', $church_id);
$stmt->execute();
$devices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get recent attendance sessions
$sessions_query = "
    SELECT id, title, service_date, 
           (SELECT COUNT(*) FROM attendance_records WHERE session_id = ats.id) as total_attendance,
           (SELECT COUNT(*) FROM attendance_records WHERE session_id = ats.id AND sync_source = 'zkteco') as zkteco_attendance
    FROM attendance_sessions ats 
    WHERE church_id = ? 
    ORDER BY service_date DESC 
    LIMIT 10
";
$stmt = $conn->prepare($sessions_query);
$church_id = get_user_church_id($conn);
$stmt->bind_param('i', $church_id);
$stmt->execute();
$sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get unprocessed logs summary
$unprocessed_query = "
    SELECT zd.device_name, COUNT(zrl.id) as unprocessed_count, MAX(zrl.timestamp) as latest_log
    FROM zkteco_raw_logs zrl
    JOIN zkteco_devices zd ON zrl.device_id = zd.id
    WHERE zrl.processed = FALSE AND zd.church_id = ?
    GROUP BY zd.id, zd.device_name
    ORDER BY unprocessed_count DESC
";
$stmt = $conn->prepare($unprocessed_query);
$church_id = get_user_church_id($conn);
$stmt->bind_param('i', $church_id);
$stmt->execute();
$unprocessed_logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get recent sync activity
$recent_sync_query = "
    SELECT zsh.*, zd.device_name
    FROM zkteco_sync_history zsh
    JOIN zkteco_devices zd ON zsh.device_id = zd.id
    WHERE zd.church_id = ?
    ORDER BY zsh.sync_start DESC
    LIMIT 5
";
$stmt = $conn->prepare($recent_sync_query);
$church_id = get_user_church_id($conn);
$stmt->bind_param('i', $church_id);
$stmt->execute();
$recent_syncs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0 text-gray-800"><i class="fas fa-sync mr-2"></i>ZKTeco Attendance Sync</h1>
    <div>
        <a href="zkteco_devices.php" class="btn btn-secondary mr-2"><i class="fas fa-microchip mr-1"></i> Devices</a>
        <a href="zkteco_enrollment.php" class="btn btn-secondary"><i class="fas fa-fingerprint mr-1"></i> Enrollment</a>
    </div>
</div>
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?> alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Quick Stats Row -->
            <div class="row">
                <div class="col-lg-3 col-6">
                    <div class="small-box bg-info">
                        <div class="inner">
                            <h3><?php echo count($devices); ?></h3>
                            <p>Active Devices</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-microchip"></i>
                        </div>
                        <a href="zkteco_devices.php" class="small-box-footer">
                            Manage Devices <i class="fas fa-arrow-circle-right"></i>
                        </a>
                    </div>
                </div>
                
                <div class="col-lg-3 col-6">
                    <div class="small-box bg-warning">
                        <div class="inner">
                            <h3><?php echo array_sum(array_column($unprocessed_logs, 'unprocessed_count')); ?></h3>
                            <p>Unprocessed Logs</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-6">
                    <div class="small-box bg-success">
                        <div class="inner">
                            <?php
                            $enrolled_count_query = "
                                SELECT COUNT(DISTINCT mbd.member_id) as enrolled_count
                                FROM member_biometric_data mbd
                                JOIN zkteco_devices zd ON mbd.device_id = zd.id
                                WHERE zd.church_id = ? AND mbd.is_active = TRUE
                            ";
                            $stmt = $conn->prepare($enrolled_count_query);
                            $church_id = get_user_church_id($conn);
$stmt->bind_param('i', $church_id);
                            $stmt->execute();
                            $enrolled_count = $stmt->get_result()->fetch_assoc()['enrolled_count'];
                            ?>
                            <h3><?php echo $enrolled_count; ?></h3>
                            <p>Enrolled Members</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <a href="zkteco_enrollment.php" class="small-box-footer">
                            Manage Enrollment <i class="fas fa-arrow-circle-right"></i>
                        </a>
                    </div>
                </div>
                
                <div class="col-lg-3 col-6">
                    <div class="small-box bg-danger">
                        <div class="inner">
                            <?php
                            $today_zkteco_attendance = 0;
                            if (!empty($sessions)) {
                                $today_session = array_filter($sessions, function($s) {
                                    return date('Y-m-d', strtotime($s['service_date'])) === date('Y-m-d');
                                });
                                if (!empty($today_session)) {
                                    $today_zkteco_attendance = array_sum(array_column($today_session, 'zkteco_attendance'));
                                }
                            }
                            ?>
                            <h3><?php echo $today_zkteco_attendance; ?></h3>
                            <p>Today's ZKTeco Attendance</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-fingerprint"></i>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (empty($devices)): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> 
                    No active ZKTeco devices found. Please <a href="zkteco_devices.php">configure devices</a> first.
                </div>
            <?php else: ?>
                <!-- Sync Controls Card -->
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title">Sync Attendance Data</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <form method="POST" class="mb-3">
                                    <input type="hidden" name="action" value="sync_and_map">
                                    
                                    <div class="form-group">
                                        <label for="sync_device_id">Device (optional)</label>
                                        <select class="form-control" id="sync_device_id" name="device_id">
                                            <option value="">All Active Devices</option>
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
                                    
                                    <div class="form-group">
                                        <label for="sync_session_id">Target Session (optional)</label>
                                        <select class="form-control" id="sync_session_id" name="session_id">
                                            <option value="">Auto-map to matching sessions</option>
                                            <?php foreach ($sessions as $session): ?>
                                                <option value="<?php echo $session['id']; ?>">
                                                    <?php echo htmlspecialchars($session['title']); ?> - 
                                                    <?php echo date('M j, Y', strtotime($session['service_date'])); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-sync"></i> Sync & Map Attendance
                                    </button>
                                </form>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="alert alert-info">
                                    <h5><i class="fas fa-info-circle"></i> How it works:</h5>
                                    <ol class="mb-0">
                                        <li><strong>Sync:</strong> Retrieves attendance logs from ZKTeco devices</li>
                                        <li><strong>Map:</strong> Matches logs to attendance sessions based on time windows</li>
                                        <li><strong>Create:</strong> Generates attendance records for enrolled members</li>
                                    </ol>
                                </div>
                                
                                <form method="POST" class="mt-3">
                                    <input type="hidden" name="action" value="map_only">
                                    <button type="submit" class="btn btn-secondary">
                                        <i class="fas fa-map"></i> Map Existing Logs Only
                                    </button>
                                    <small class="form-text text-muted">Process already synced logs without fetching new data</small>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="row">
                <!-- Unprocessed Logs -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Unprocessed Logs</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($unprocessed_logs)): ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle"></i> All logs have been processed!
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Device</th>
                                                <th>Count</th>
                                                <th>Latest Log</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($unprocessed_logs as $log): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($log['device_name']); ?></td>
                                                    <td>
                                                        <span class="badge badge-warning"><?php echo $log['unprocessed_count']; ?></span>
                                                    </td>
                                                    <td>
                                                        <small><?php echo date('M j, H:i', strtotime($log['latest_log'])); ?></small>
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
                
                <!-- Recent Sessions -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Recent Attendance Sessions</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($sessions)): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> No recent attendance sessions found.
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Session</th>
                                                <th>Date</th>
                                                <th>Attendance</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($sessions as $session): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($session['title']); ?></strong>
                                                    </td>
                                                    <td>
                                                        <small><?php echo date('M j, Y', strtotime($session['service_date'])); ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="badge badge-primary"><?php echo $session['total_attendance']; ?></span>
                                                        <?php if ($session['zkteco_attendance'] > 0): ?>
                                                            <span class="badge badge-success" title="ZKTeco Attendance">
                                                                <i class="fas fa-fingerprint"></i> <?php echo $session['zkteco_attendance']; ?>
                                                            </span>
                                                        <?php endif; ?>
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
            </div>

            <!-- Recent Sync Activity -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Recent Sync Activity</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_syncs)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No sync activity yet.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Device</th>
                                        <th>Date/Time</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th>Records</th>
                                        <th>Duration</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_syncs as $sync): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($sync['device_name']); ?></td>
                                            <td>
                                                <small><?php echo date('M j, Y H:i:s', strtotime($sync['sync_start'])); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?php 
                                                    echo $sync['sync_type'] === 'manual' ? 'primary' : 
                                                        ($sync['sync_type'] === 'automatic' ? 'info' : 'secondary'); 
                                                ?>">
                                                    <?php echo ucfirst($sync['sync_type']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?php 
                                                    echo $sync['sync_status'] === 'success' ? 'success' : 
                                                        ($sync['sync_status'] === 'partial' ? 'warning' : 'danger'); 
                                                ?>">
                                                    <?php echo ucfirst($sync['sync_status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small>
                                                    <?php echo $sync['records_synced']; ?> synced
                                                    <?php if ($sync['records_processed'] != $sync['records_synced']): ?>
                                                        / <?php echo $sync['records_processed']; ?> processed
                                                    <?php endif; ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?php if ($sync['sync_end']): ?>
                                                    <?php 
                                                    $duration = strtotime($sync['sync_end']) - strtotime($sync['sync_start']);
                                                    echo $duration > 0 ? $duration . 's' : '<1s';
                                                    ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>


<?php
$page_content = ob_get_clean();
require_once __DIR__.'/../includes/layout.php';
?>
