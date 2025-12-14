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
$member_id = isset($_GET['member_id']) ? intval($_GET['member_id']) : 0;
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

if ($is_bible_class_leader) {
    $class_members = get_bible_class_members($conn, $is_bible_class_leader['class_id']);
    $member_ids = array_column($class_members, 'id');
    if (in_array($member_id, $member_ids)) {
        $member_belongs = true;
        $group_name = $is_bible_class_leader['class_name'];
        $group_type = 'Bible Class';
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

// Get date range for filtering
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$payment_type_filter = $_GET['payment_type'] ?? '';

// Get payment types for filter
$payment_types = $conn->query("SELECT id, name FROM payment_types ORDER BY name ASC");

// Get payments
$sql = "
    SELECT p.*, pt.name as payment_type_name, 
           CONCAT(u.name, ' (', u.email, ')') as recorded_by_name
    FROM payments p
    LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
    LEFT JOIN users u ON p.recorded_by = u.id
    WHERE p.member_id = ? AND p.payment_date BETWEEN ? AND ?
";

$params = [$member_id, $start_date, $end_date];
$types = 'iss';

if ($payment_type_filter) {
    $sql .= " AND p.payment_type_id = ?";
    $params[] = $payment_type_filter;
    $types .= 'i';
}

$sql .= " ORDER BY p.payment_date DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate statistics
$total_amount = 0;
$total_payments = count($payments);
foreach ($payments as $payment) {
    $total_amount += $payment['amount'];
}
$avg_payment = $total_payments > 0 ? $total_amount / $total_payments : 0;

// Get payment type breakdown
$stmt = $conn->prepare("
    SELECT pt.name, COUNT(p.id) as count, SUM(p.amount) as total
    FROM payments p
    LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
    WHERE p.member_id = ? AND p.payment_date BETWEEN ? AND ?
    GROUP BY p.payment_type_id
    ORDER BY total DESC
");
$stmt->bind_param('iss', $member_id, $start_date, $end_date);
$stmt->execute();
$payment_breakdown = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

ob_start();
?>
<style>
.member-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 30px;
    border-radius: 15px;
    margin-bottom: 30px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
}

.stat-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.08);
    transition: transform 0.2s;
    border-left: 4px solid;
}

.stat-card:hover {
    transform: translateY(-3px);
}

.stat-card.total { border-left-color: #28a745; }
.stat-card.count { border-left-color: #17a2b8; }
.stat-card.average { border-left-color: #ffc107; }

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: #2c3e50;
}

.stat-label {
    font-size: 0.85rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.payment-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 15px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    transition: all 0.3s;
    border-left: 4px solid #28a745;
}

.payment-card:hover {
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    transform: translateX(5px);
}

.filter-card {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
}
</style>

<div class="member-header">
    <div class="d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <img src="<?= BASE_URL ?>/uploads/members/<?= htmlspecialchars($member['photo'] ?? 'default.png') ?>" 
                 alt="Photo" 
                 style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; margin-right: 20px; border: 3px solid white;">
            <div>
                <h2><?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></h2>
                <p class="mb-0">
                    <i class="fas fa-id-card"></i> <?= htmlspecialchars($member['crn'] ?? 'N/A') ?>
                    | <i class="fas fa-<?= $group_type === 'Bible Class' ? 'chalkboard-teacher' : 'users-cog' ?>"></i> <?= htmlspecialchars($group_name) ?>
                </p>
            </div>
        </div>
        <div>
            <a href="<?= $group_type === 'Bible Class' ? 'my_bible_class_leader.php' : 'my_organization_leader.php' ?>" 
               class="btn btn-light btn-lg">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>
</div>

<!-- Filter Section -->
<div class="filter-card">
    <form method="get" class="row align-items-end">
        <input type="hidden" name="member_id" value="<?= $member_id ?>">
        <div class="col-md-3">
            <label class="form-label fw-bold">Start Date</label>
            <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label fw-bold">End Date</label>
            <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label fw-bold">Payment Type</label>
            <select name="payment_type" class="form-control">
                <option value="">All Types</option>
                <?php while ($pt = $payment_types->fetch_assoc()): ?>
                <option value="<?= $pt['id'] ?>" <?= $payment_type_filter == $pt['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($pt['name']) ?>
                </option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-filter"></i> Apply Filter
            </button>
            <a href="?member_id=<?= $member_id ?>" class="btn btn-secondary">
                <i class="fas fa-times"></i> Clear
            </a>
        </div>
    </form>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="stat-card total">
            <div class="stat-label">Total Amount</div>
            <div class="stat-value">GH₵ <?= number_format($total_amount, 2) ?></div>
            <small class="text-muted">In selected period</small>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card count">
            <div class="stat-label">Total Payments</div>
            <div class="stat-value"><?= $total_payments ?></div>
            <small class="text-muted">Transactions</small>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card average">
            <div class="stat-label">Average Payment</div>
            <div class="stat-value">GH₵ <?= number_format($avg_payment, 2) ?></div>
            <small class="text-muted">Per transaction</small>
        </div>
    </div>
</div>

<div class="row">
    <!-- Payment Breakdown -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Payment Breakdown</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($payment_breakdown)): ?>
                    <?php foreach ($payment_breakdown as $breakdown): ?>
                    <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom">
                        <div>
                            <strong><?= htmlspecialchars($breakdown['name'] ?? 'Unknown') ?></strong>
                            <br>
                            <small class="text-muted"><?= $breakdown['count'] ?> payments</small>
                        </div>
                        <div class="text-right">
                            <strong class="text-success">GH₵ <?= number_format($breakdown['total'], 2) ?></strong>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="alert alert-info">No payments in this period</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Payment History -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="fas fa-history"></i> Payment History (<?= $total_payments ?>)</h5>
            </div>
            <div class="card-body" style="max-height: 600px; overflow-y: auto;">
                <?php if (!empty($payments)): ?>
                    <?php foreach ($payments as $payment): ?>
                    <div class="payment-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1">
                                    <?= htmlspecialchars($payment['payment_type_name'] ?? 'N/A') ?>
                                    <?php if ($payment['payment_period']): ?>
                                    <span class="badge badge-info ml-2">
                                        <?= htmlspecialchars($payment['payment_period_description'] ?? date('M Y', strtotime($payment['payment_period']))) ?>
                                    </span>
                                    <?php endif; ?>
                                </h6>
                                <small class="text-muted">
                                    <i class="fas fa-calendar"></i> <?= date('l, F j, Y', strtotime($payment['payment_date'])) ?>
                                </small>
                                <?php if ($payment['description']): ?>
                                <br>
                                <small class="text-muted">
                                    <i class="fas fa-comment"></i> <?= htmlspecialchars($payment['description']) ?>
                                </small>
                                <?php endif; ?>
                                <?php if ($payment['recorded_by_name']): ?>
                                <br>
                                <small class="text-muted">
                                    <i class="fas fa-user"></i> Recorded by: <?= htmlspecialchars($payment['recorded_by_name']) ?>
                                </small>
                                <?php endif; ?>
                            </div>
                            <div class="text-right">
                                <h4 class="text-success mb-0">GH₵ <?= number_format($payment['amount'], 2) ?></h4>
                                <small class="text-muted">#<?= $payment['id'] ?></small>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="alert alert-info">No payments found for the selected period.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$page_content = ob_get_clean();
$page_title = 'Payments - ' . $member['first_name'] . ' ' . $member['last_name'];
include '../includes/layout.php';
?>
