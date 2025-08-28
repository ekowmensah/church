<?php
// views/member_hikvision_mapping.php
// Admin UI to map Hikvision device users to church members
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions.php';

$page_title = "HikVision User Mapping";
$current_page = "hikvision_mapping";

// Initialize variables from auth
$is_super_admin = $_SESSION['is_super_admin'] ?? false;
$church_id = $_SESSION['church_id'] ?? 1;

// Check permissions
if (!$is_super_admin && !has_permission('manage_hikvision_devices')) {
    header('HTTP/1.0 403 Forbidden');
    include '../views/errors/403.php';
    exit;
}

$conn = require_once __DIR__ . '/../config/database.php';

// Handle form submission for mapping
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'map_user') {
    $device_id = $_POST['device_id'];
    $hikvision_user_id = $_POST['hikvision_user_id'];
    $member_id = $_POST['member_id'];
    
    $stmt = $conn->prepare('UPDATE member_hikvision_data SET member_id = ? WHERE device_id = ? AND hikvision_user_id = ?');
    $stmt->bind_param('iss', $member_id, $device_id, $hikvision_user_id);
    
    if ($stmt->execute()) {
        $message = 'User mapped successfully!';
        $message_type = 'success';
    } else {
        $message = 'Failed to map user.';
        $message_type = 'error';
    }
    $stmt->close();
}

// Fetch all device users from member_hikvision_data (joined with members and devices)
$sql = "SELECT h.id, h.device_id, h.hikvision_user_id, h.member_id, 
               CONCAT_WS(' ', m.first_name, m.middle_name, m.last_name) AS full_name, 
               m.phone, m.email, m.crn,
               d.device_name
        FROM member_hikvision_data h 
        LEFT JOIN members m ON h.member_id = m.id 
        LEFT JOIN hikvision_devices d ON h.device_id = d.id
        ORDER BY h.device_id, h.hikvision_user_id";
$result = $conn->query($sql);
$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}

// Fetch all members for dropdown
$members_sql = "SELECT id, CONCAT_WS(' ', first_name, middle_name, last_name) AS full_name, crn FROM members WHERE status = 'active' ORDER BY first_name, last_name";
$members_result = $conn->query($members_sql);
$members = [];
while ($member = $members_result->fetch_assoc()) {
    $members[] = $member;
}

ob_start();
?>

<style>
.mapping-card {
    box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
    border: 1px solid #e3e6f0;
}
.unmapped-row {
    background-color: #fff3cd;
    border-left: 4px solid #ffc107;
}
.mapped-row {
    background-color: #d1edff;
    border-left: 4px solid #0d6efd;
}
.device-badge {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 0.25rem 0.75rem;
    border-radius: 1rem;
    font-size: 0.875rem;
    font-weight: 500;
}
.user-id-badge {
    background: #6c757d;
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 0.375rem;
    font-family: 'Courier New', monospace;
    font-size: 0.875rem;
}
.member-info {
    background: #f8f9fa;
    padding: 0.75rem;
    border-radius: 0.375rem;
    border: 1px solid #dee2e6;
}
.select2-container {
    width: 100% !important;
}
</style>

<?php if (isset($message)): ?>
<div class="alert alert-<?= $message_type === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($message) ?>
    <button type="button" class="close" data-dismiss="alert">
        <span>&times;</span>
    </button>
</div>
<?php endif; ?>

<div class="card mapping-card">
    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
        <h6 class="m-0 font-weight-bold text-primary">
            <i class="fas fa-users-cog mr-2"></i>HikVision User Mapping
        </h6>
        <div class="text-muted small">
            <i class="fas fa-info-circle mr-1"></i>
            Map device users to church members for attendance tracking
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($rows)): ?>
            <div class="text-center py-4">
                <i class="fas fa-users fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">No HikVision Users Found</h5>
                <p class="text-muted">Run the user sync script to pull users from the device first.</p>
                <a href="<?= BASE_URL ?>/views/hikvision_devices.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Devices
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered" id="mappingTable">
                    <thead class="thead-light">
                        <tr>
                            <th><i class="fas fa-server mr-1"></i>Device</th>
                            <th><i class="fas fa-id-badge mr-1"></i>HikVision User ID</th>
                            <th><i class="fas fa-user mr-1"></i>Mapped Member</th>
                            <th><i class="fas fa-cogs mr-1"></i>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr class="<?= $row['member_id'] ? 'mapped-row' : 'unmapped-row' ?>">
                            <td>
                                <span class="device-badge">
                                    <?= htmlspecialchars($row['device_name'] ?? 'Device ' . $row['device_id']) ?>
                                </span>
                                <br><small class="text-muted">ID: <?= htmlspecialchars($row['device_id']) ?></small>
                            </td>
                            <td>
                                <span class="user-id-badge"><?= htmlspecialchars($row['hikvision_user_id']) ?></span>
                            </td>
                            <td>
                                <?php if ($row['member_id']): ?>
                                    <div class="member-info">
                                        <strong><?= htmlspecialchars($row['full_name']) ?></strong>
                                        <?php if ($row['crn']): ?>
                                            <br><small class="text-muted">CRN: <?= htmlspecialchars($row['crn']) ?></small>
                                        <?php endif; ?>
                                        <?php if ($row['phone']): ?>
                                            <br><small class="text-muted"><i class="fas fa-phone mr-1"></i><?= htmlspecialchars($row['phone']) ?></small>
                                        <?php endif; ?>
                                        <?php if ($row['email']): ?>
                                            <br><small class="text-muted"><i class="fas fa-envelope mr-1"></i><?= htmlspecialchars($row['email']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-2">
                                        <i class="fas fa-exclamation-triangle text-warning mr-1"></i>
                                        <span class="text-warning font-weight-bold">Not Mapped</span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$row['member_id']): ?>
                                    <form method="post" class="mapping-form">
                                        <input type="hidden" name="action" value="map_user">
                                        <input type="hidden" name="device_id" value="<?= htmlspecialchars($row['device_id']) ?>">
                                        <input type="hidden" name="hikvision_user_id" value="<?= htmlspecialchars($row['hikvision_user_id']) ?>">
                                        <div class="form-group mb-2">
                                            <select name="member_id" class="form-control member-select" required>
                                                <option value="">Select Member...</option>
                                                <?php foreach ($members as $member): ?>
                                                    <option value="<?= htmlspecialchars($member['id']) ?>">
                                                        <?= htmlspecialchars($member['full_name']) ?>
                                                        <?php if ($member['crn']): ?>
                                                            (<?= htmlspecialchars($member['crn']) ?>)
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-sm">
                                            <i class="fas fa-link mr-1"></i>Map User
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <div class="text-center">
                                        <span class="badge badge-success">
                                            <i class="fas fa-check mr-1"></i>Mapped
                                        </span>
                                        <br>
                                        <form method="post" class="mt-2" style="display: inline;">
                                            <input type="hidden" name="action" value="map_user">
                                            <input type="hidden" name="device_id" value="<?= htmlspecialchars($row['device_id']) ?>">
                                            <input type="hidden" name="hikvision_user_id" value="<?= htmlspecialchars($row['hikvision_user_id']) ?>">
                                            <input type="hidden" name="member_id" value="">
                                            <button type="submit" class="btn btn-outline-secondary btn-sm" onclick="return confirm('Unmap this user?')">
                                                <i class="fas fa-unlink mr-1"></i>Unmap
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="row mt-3">
                <div class="col-md-6">
                    <div class="card border-left-warning">
                        <div class="card-body py-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Unmapped Users</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?= count(array_filter($rows, function($r) { return !$r['member_id']; })) ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-left-primary">
                        <div class="card-body py-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Mapped Users</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?= count(array_filter($rows, function($r) { return $r['member_id']; })) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />

<script>
$(document).ready(function() {
    // Initialize Select2 for member dropdowns
    $('.member-select').select2({
        placeholder: 'Search and select member...',
        allowClear: true,
        width: '100%'
    });
    
    // Initialize DataTable
    $('#mappingTable').DataTable({
        "pageLength": 10,
        "order": [[0, "asc"]],
        "columnDefs": [
            { "orderable": false, "targets": [3] }
        ]
    });
});
</script>

<?php
$page_content = ob_get_clean();
require_once __DIR__.'/../includes/layout.php';
?>
