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
    http_response_code(403);
    echo '<div class="alert alert-danger">Access denied.</div>';
    exit;
}

$device_id = isset($_GET['device_id']) ? intval($_GET['device_id']) : 0;

if (!$device_id) {
    echo '<div class="alert alert-danger">Invalid device ID.</div>';
    exit;
}

$zkService = new ZKTecoService();
$stats = $zkService->getDeviceStats($device_id);

if (!$stats || !isset($stats['device'])) {
    echo '<div class="alert alert-danger">Device not found.</div>';
    exit;
}

$device = $stats['device'];
$logs = $stats['logs'];
$enrolled = $stats['enrolled'];
$sync_history = $stats['sync_history'];
?>

<div class="row">
    <div class="col-md-6">
        <h5><i class="fas fa-microchip"></i> Device Information</h5>
        <table class="table table-sm">
            <tr>
                <td><strong>Name:</strong></td>
                <td><?php echo htmlspecialchars($device['device_name']); ?></td>
            </tr>
            <tr>
                <td><strong>IP Address:</strong></td>
                <td><?php echo htmlspecialchars($device['ip_address']); ?>:<?php echo $device['port']; ?></td>
            </tr>
            <tr>
                <td><strong>Location:</strong></td>
                <td><?php echo htmlspecialchars($device['location'] ?: 'Not specified'); ?></td>
            </tr>
            <tr>
                <td><strong>Model:</strong></td>
                <td><?php echo htmlspecialchars($device['device_model']); ?></td>
            </tr>
            <tr>
                <td><strong>Firmware:</strong></td>
                <td><?php echo htmlspecialchars($device['firmware_version'] ?: 'Unknown'); ?></td>
            </tr>
            <tr>
                <td><strong>Status:</strong></td>
                <td>
                    <?php if ($device['is_active']): ?>
                        <span class="badge badge-success">Active</span>
                    <?php else: ?>
                        <span class="badge badge-secondary">Inactive</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><strong>Last Sync:</strong></td>
                <td>
                    <?php if ($device['last_sync']): ?>
                        <?php echo date('M j, Y H:i:s', strtotime($device['last_sync'])); ?>
                    <?php else: ?>
                        <span class="text-muted">Never</span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>
    
    <div class="col-md-6">
        <h5><i class="fas fa-chart-pie"></i> Statistics</h5>
        <div class="row">
            <div class="col-6">
                <div class="info-box bg-info">
                    <span class="info-box-icon"><i class="fas fa-users"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Enrolled Members</span>
                        <span class="info-box-number"><?php echo $enrolled['total']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-6">
                <div class="info-box bg-success">
                    <span class="info-box-icon"><i class="fas fa-list"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Logs</span>
                        <span class="info-box-number"><?php echo $logs['total']; ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-6">
                <div class="info-box bg-warning">
                    <span class="info-box-icon"><i class="fas fa-fingerprint"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Fingerprint</span>
                        <span class="info-box-number"><?php echo $enrolled['fingerprint']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-6">
                <div class="info-box bg-primary">
                    <span class="info-box-icon"><i class="fas fa-smile"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Face</span>
                        <span class="info-box-number"><?php echo $enrolled['face']; ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="progress-group">
            <span class="progress-text">Processed Logs</span>
            <span class="float-right"><b><?php echo $logs['processed']; ?></b>/<?php echo $logs['total']; ?></span>
            <div class="progress progress-sm">
                <?php 
                $processed_percentage = $logs['total'] > 0 ? ($logs['processed'] / $logs['total']) * 100 : 0;
                ?>
                <div class="progress-bar bg-success" style="width: <?php echo $processed_percentage; ?>%"></div>
            </div>
        </div>
    </div>
</div>

<hr>

<h5><i class="fas fa-history"></i> Recent Sync History</h5>
<?php if (empty($sync_history)): ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i> No sync history available.
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-striped">
            <thead>
                <tr>
                    <th>Date/Time</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Records</th>
                    <th>Duration</th>
                    <th>Initiated By</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sync_history as $sync): ?>
                    <tr>
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
                        <td>
                            <?php if ($sync['initiated_by']): ?>
                                <?php
                                $user_stmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
                                $user_stmt->bind_param('i', $sync['initiated_by']);
                                $user_stmt->execute();
                                $user = $user_stmt->get_result()->fetch_assoc();
                                if ($user) {
                                    echo '<small>' . htmlspecialchars($user['name']) . '</small>';
                                } else {
                                    echo '<small class="text-muted">Unknown</small>';
                                }
                                ?>
                            <?php else: ?>
                                <small class="text-muted">System</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($logs['latest_log']): ?>
    <div class="mt-3">
        <small class="text-muted">
            <i class="fas fa-clock"></i> Latest attendance log: 
            <?php echo date('M j, Y H:i:s', strtotime($logs['latest_log'])); ?>
        </small>
    </div>
<?php endif; ?>
