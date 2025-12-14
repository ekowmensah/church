<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/leader_helpers.php';

// Check if user is logged in
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Get member_id from URL
$member_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$member_id) {
    header('Location: ' . $_SERVER['HTTP_REFERER'] ?? 'index.php');
    exit;
}

// Check if user is a leader and verify member belongs to their group
$user_id = $_SESSION['user_id'] ?? null;
$logged_member_id = $_SESSION['member_id'] ?? null;
$is_bible_class_leader = is_bible_class_leader($conn, $user_id, $logged_member_id);
$is_org_leader = is_organization_leader($conn, $user_id, $logged_member_id);

if (!$is_bible_class_leader && !$is_org_leader) {
    http_response_code(403);
    echo '<div class="alert alert-danger">You are not assigned as a leader.</div>';
    exit;
}

// Verify member belongs to leader's group
$member_belongs = false;
$group_name = '';
$group_type = '';
$back_url = '';

if ($is_bible_class_leader) {
    $class_members = get_bible_class_members($conn, $is_bible_class_leader['class_id']);
    $member_ids = array_column($class_members, 'id');
    if (in_array($member_id, $member_ids)) {
        $member_belongs = true;
        $group_name = $is_bible_class_leader['class_name'];
        $group_type = 'Bible Class';
        $back_url = 'my_bible_class_leader.php';
    }
}

if (!$member_belongs && $is_org_leader) {
    // is_org_leader now returns array of organizations
    foreach ($is_org_leader as $org) {
        $org_members = get_organization_members($conn, $org['organization_id']);
        $member_ids = array_column($org_members, 'id');
        if (in_array($member_id, $member_ids)) {
            $member_belongs = true;
            $group_name = $org['org_name'];
            $group_type = 'Organization';
            $back_url = 'my_organization_leader.php?org_id=' . $org['organization_id'];
            break;
        }
    }
}

if (!$member_belongs) {
    http_response_code(403);
    echo '<div class="alert alert-danger">This member does not belong to your group.</div>';
    exit;
}

// Get member details
$stmt = $conn->prepare("
    SELECT m.*, bc.name as class_name, c.name as church_name
    FROM members m
    LEFT JOIN bible_classes bc ON m.class_id = bc.id
    LEFT JOIN churches c ON m.church_id = c.id
    WHERE m.id = ?
");
$stmt->bind_param('i', $member_id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$member) {
    echo '<div class="alert alert-danger">Member not found.</div>';
    exit;
}

// Get member's organizations
$stmt = $conn->prepare("
    SELECT o.name
    FROM organizations o
    INNER JOIN member_organizations mo ON o.id = mo.organization_id
    WHERE mo.member_id = ?
");
$stmt->bind_param('i', $member_id);
$stmt->execute();
$organizations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get recent attendance (last 10)
$stmt = $conn->prepare("
    SELECT ar.*, ats.title, ats.service_date
    FROM attendance_records ar
    INNER JOIN attendance_sessions ats ON ar.session_id = ats.id
    WHERE ar.member_id = ?
    ORDER BY ats.service_date DESC
    LIMIT 10
");
$stmt->bind_param('i', $member_id);
$stmt->execute();
$recent_attendance = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get attendance statistics
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_records,
        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count
    FROM attendance_records
    WHERE member_id = ?
");
$stmt->bind_param('i', $member_id);
$stmt->execute();
$attendance_stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$attendance_rate = $attendance_stats['total_records'] > 0 
    ? round(($attendance_stats['present_count'] / $attendance_stats['total_records']) * 100, 1) 
    : 0;

// Get payment statistics
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_payments,
        SUM(amount) as total_amount,
        MAX(payment_date) as last_payment_date
    FROM payments
    WHERE member_id = ?
");
$stmt->bind_param('i', $member_id);
$stmt->execute();
$payment_stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Calculate age if DOB exists
$age = null;
if ($member['dob']) {
    $dob = new DateTime($member['dob']);
    $now = new DateTime();
    $age = $now->diff($dob)->y;
}

ob_start();
?>
<style>
.profile-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 40px;
    border-radius: 15px;
    margin-bottom: 30px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
}

.profile-photo {
    width: 150px;
    height: 150px;
    border-radius: 50%;
    object-fit: cover;
    border: 5px solid white;
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
}

.info-card {
    background: white;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 20px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.08);
}

.info-row {
    display: flex;
    padding: 12px 0;
    border-bottom: 1px solid #f0f0f0;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    font-weight: 600;
    color: #6c757d;
    min-width: 150px;
}

.info-value {
    color: #2c3e50;
    flex: 1;
}

.stat-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.08);
    text-align: center;
    transition: transform 0.2s;
    border-top: 4px solid;
}

.stat-card:hover {
    transform: translateY(-5px);
}

.stat-card.attendance { border-top-color: #17a2b8; }
.stat-card.payments { border-top-color: #28a745; }
.stat-card.rate { border-top-color: #ffc107; }

.stat-value {
    font-size: 2.5rem;
    font-weight: 700;
    color: #2c3e50;
    margin: 10px 0;
}

.stat-label {
    font-size: 0.9rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.attendance-item {
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 10px;
    display: flex;
    justify-content: between;
    align-items: center;
}

.attendance-item.present {
    background: #d4edda;
    border-left: 4px solid #28a745;
}

.attendance-item.absent {
    background: #f8d7da;
    border-left: 4px solid #dc3545;
}

.action-btn {
    margin: 5px;
}
</style>

<div class="profile-header">
    <div class="d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <img src="<?= BASE_URL ?>/uploads/members/<?= htmlspecialchars($member['photo'] ?? 'default.png') ?>" 
                 alt="Photo" 
                 class="profile-photo">
            <div class="ml-4">
                <h2 class="mb-2"><?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></h2>
                <p class="mb-1">
                    <i class="fas fa-id-card"></i> CRN: <?= htmlspecialchars($member['crn'] ?? 'N/A') ?>
                </p>
                <p class="mb-1">
                    <i class="fas fa-<?= $group_type === 'Bible Class' ? 'chalkboard-teacher' : 'users-cog' ?>"></i> 
                    <?= htmlspecialchars($group_name) ?>
                </p>
                <?php if ($member['phone']): ?>
                <p class="mb-0">
                    <i class="fas fa-phone"></i> <?= htmlspecialchars($member['phone']) ?>
                </p>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <a href="<?= $back_url ?>" class="btn btn-light btn-lg">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <h5 class="mb-3"><i class="fas fa-bolt"></i> Quick Actions</h5>
                <a href="leader_member_payments.php?member_id=<?= $member_id ?>" class="btn btn-success action-btn">
                    <i class="fas fa-money-bill-wave"></i> View Payments
                </a>
                <?php if ($member['phone']): ?>
                <a href="tel:<?= htmlspecialchars($member['phone']) ?>" class="btn btn-primary action-btn">
                    <i class="fas fa-phone"></i> Call Member
                </a>
                <?php endif; ?>
                <?php if ($member['email']): ?>
                <a href="mailto:<?= htmlspecialchars($member['email']) ?>" class="btn btn-info action-btn">
                    <i class="fas fa-envelope"></i> Send Email
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Statistics -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="stat-card attendance">
            <div class="stat-label">Attendance Records</div>
            <div class="stat-value"><?= $attendance_stats['total_records'] ?></div>
            <small class="text-muted"><?= $attendance_stats['present_count'] ?> present, <?= $attendance_stats['absent_count'] ?> absent</small>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card rate">
            <div class="stat-label">Attendance Rate</div>
            <div class="stat-value"><?= $attendance_rate ?>%</div>
            <small class="text-muted">Overall performance</small>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card payments">
            <div class="stat-label">Total Payments</div>
            <div class="stat-value">GH₵ <?= number_format($payment_stats['total_amount'] ?? 0, 2) ?></div>
            <small class="text-muted"><?= $payment_stats['total_payments'] ?? 0 ?> transactions</small>
        </div>
    </div>
</div>

<div class="row">
    <!-- Personal Information -->
    <div class="col-md-6">
        <div class="info-card">
            <h5 class="mb-4"><i class="fas fa-user text-primary"></i> Personal Information</h5>
            
            <div class="info-row">
                <div class="info-label">Full Name:</div>
                <div class="info-value"><?= htmlspecialchars($member['first_name'] . ' ' . ($member['middle_name'] ? $member['middle_name'] . ' ' : '') . $member['last_name']) ?></div>
            </div>
            
            <?php if ($member['gender']): ?>
            <div class="info-row">
                <div class="info-label">Gender:</div>
                <div class="info-value">
                    <i class="fas fa-<?= strtolower($member['gender']) === 'male' ? 'mars' : 'venus' ?>"></i>
                    <?= htmlspecialchars($member['gender']) ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($member['dob']): ?>
            <div class="info-row">
                <div class="info-label">Date of Birth:</div>
                <div class="info-value">
                    <?= date('F j, Y', strtotime($member['dob'])) ?>
                    <?php if ($age): ?>
                    <span class="badge badge-info ml-2"><?= $age ?> years old</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (isset($member['dayborn']) && $member['dayborn']): ?>
            <div class="info-row">
                <div class="info-label">Day Born:</div>
                <div class="info-value"><?= htmlspecialchars($member['dayborn']) ?></div>
            </div>
            <?php endif; ?>
            
            <?php if ($member['email']): ?>
            <div class="info-row">
                <div class="info-label">Email:</div>
                <div class="info-value">
                    <a href="mailto:<?= htmlspecialchars($member['email']) ?>">
                        <?= htmlspecialchars($member['email']) ?>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($member['phone']): ?>
            <div class="info-row">
                <div class="info-label">Phone:</div>
                <div class="info-value">
                    <a href="tel:<?= htmlspecialchars($member['phone']) ?>">
                        <?= htmlspecialchars($member['phone']) ?>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($member['address']): ?>
            <div class="info-row">
                <div class="info-label">Address:</div>
                <div class="info-value"><?= htmlspecialchars($member['address']) ?></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Church Information -->
        <div class="info-card">
            <h5 class="mb-4"><i class="fas fa-church text-primary"></i> Church Information</h5>
            
            <div class="info-row">
                <div class="info-label">Church:</div>
                <div class="info-value"><?= htmlspecialchars($member['church_name'] ?? 'N/A') ?></div>
            </div>
            
            <?php if ($member['class_name']): ?>
            <div class="info-row">
                <div class="info-label">Bible Class:</div>
                <div class="info-value"><?= htmlspecialchars($member['class_name']) ?></div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($organizations)): ?>
            <div class="info-row">
                <div class="info-label">Organizations:</div>
                <div class="info-value">
                    <?php foreach ($organizations as $org): ?>
                    <span class="badge badge-info mr-1"><?= htmlspecialchars($org['name']) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($member['confirmed'] || $member['baptized']): ?>
            <div class="info-row">
                <div class="info-label">Status:</div>
                <div class="info-value">
                    <?php if ($member['confirmed']): ?>
                    <span class="badge badge-success">Confirmed</span>
                    <?php endif; ?>
                    <?php if ($member['baptized']): ?>
                    <span class="badge badge-primary">Baptized</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Attendance -->
    <div class="col-md-6">
        <div class="info-card">
            <h5 class="mb-4"><i class="fas fa-clipboard-check text-primary"></i> Recent Attendance</h5>
            
            <?php if (!empty($recent_attendance)): ?>
                <?php foreach ($recent_attendance as $attendance): ?>
                <div class="attendance-item <?= strtolower($attendance['status']) ?>">
                    <div class="flex-grow-1">
                        <strong><?= htmlspecialchars($attendance['title']) ?></strong>
                        <br>
                        <small class="text-muted">
                            <i class="fas fa-calendar"></i> <?= date('M j, Y', strtotime($attendance['service_date'])) ?>
                        </small>
                    </div>
                    <div>
                        <span class="badge badge-<?= strtolower($attendance['status']) === 'present' ? 'success' : 'danger' ?>">
                            <?= ucfirst($attendance['status']) ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="alert alert-info">No attendance records found.</div>
            <?php endif; ?>
        </div>

        <!-- Payment Summary -->
        <?php if ($payment_stats['total_payments'] > 0): ?>
        <div class="info-card">
            <h5 class="mb-4"><i class="fas fa-money-bill-wave text-success"></i> Payment Summary</h5>
            
            <div class="info-row">
                <div class="info-label">Total Paid:</div>
                <div class="info-value">
                    <strong class="text-success">GH₵ <?= number_format($payment_stats['total_amount'], 2) ?></strong>
                </div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Total Payments:</div>
                <div class="info-value"><?= $payment_stats['total_payments'] ?> transactions</div>
            </div>
            
            <?php if ($payment_stats['last_payment_date']): ?>
            <div class="info-row">
                <div class="info-label">Last Payment:</div>
                <div class="info-value"><?= date('F j, Y', strtotime($payment_stats['last_payment_date'])) ?></div>
            </div>
            <?php endif; ?>
            
            <div class="mt-3">
                <a href="leader_member_payments.php?member_id=<?= $member_id ?>" class="btn btn-success btn-block">
                    <i class="fas fa-eye"></i> View All Payments
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
$page_content = ob_get_clean();
$page_title = 'Member Profile - ' . $member['first_name'] . ' ' . $member['last_name'];
include '../includes/layout.php';
?>
