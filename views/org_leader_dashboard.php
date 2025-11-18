<?php
/**
 * Organizational Leader Dashboard
 * Specialized dashboard for Organization Leaders
 * Shows statistics and information for their assigned organizations
 */

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/role_based_filter.php';

// Authentication check
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['name'] ?? 'Organization Leader';

// Check if user is an organizational leader
$org_ids = get_user_organization_ids();
if ($org_ids === null) {
    header('Location: ' . BASE_URL . '/views/user_dashboard.php');
    exit;
}

// Get organization information
$org_info = [];
$placeholders = implode(',', array_fill(0, count($org_ids), '?'));
$stmt = $conn->prepare("
    SELECT o.*, c.name as church_name, 
           COUNT(DISTINCT mo.member_id) as member_count
    FROM organizations o
    LEFT JOIN churches c ON o.church_id = c.id
    LEFT JOIN member_organizations mo ON mo.organization_id = o.id
    WHERE o.id IN ($placeholders)
    GROUP BY o.id
");
$types = str_repeat('i', count($org_ids));
$stmt->bind_param($types, ...$org_ids);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $org_info[] = $row;
}
$stmt->close();

// Get total statistics across all assigned organizations
$total_members = 0;
$total_payments_this_month = 0;
$total_amount_this_month = 0;

foreach ($org_ids as $org_id) {
    // Count active members
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT mo.member_id) as count 
        FROM member_organizations mo
        JOIN members m ON mo.member_id = m.id
        WHERE mo.organization_id = ? AND m.membership_status != 'Adherent'
    ");
    $stmt->bind_param('i', $org_id);
    $stmt->execute();
    $total_members += $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();
    
    // Count payments this month from org members
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count, COALESCE(SUM(p.amount), 0) as total
        FROM payments p
        JOIN members m ON p.member_id = m.id
        JOIN member_organizations mo ON mo.member_id = m.id
        WHERE mo.organization_id = ?
        AND MONTH(p.payment_date) = MONTH(CURDATE()) 
        AND YEAR(p.payment_date) = YEAR(CURDATE())
    ");
    $stmt->bind_param('i', $org_id);
    $stmt->execute();
    $payment_result = $stmt->get_result()->fetch_assoc();
    $total_payments_this_month += $payment_result['count'];
    $total_amount_this_month += $payment_result['total'];
    $stmt->close();
}

// Get recent payments from org members
$recent_payments = [];
$stmt = $conn->prepare("
    SELECT p.*, m.crn, m.first_name, m.last_name, 
           pt.name as payment_type, o.name as org_name
    FROM payments p
    JOIN members m ON p.member_id = m.id
    JOIN member_organizations mo ON mo.member_id = m.id
    JOIN organizations o ON mo.organization_id = o.id
    JOIN payment_types pt ON p.payment_type_id = pt.id
    WHERE mo.organization_id IN ($placeholders)
    ORDER BY p.payment_date DESC
    LIMIT 10
");
$stmt->bind_param($types, ...$org_ids);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recent_payments[] = $row;
}
$stmt->close();

// Get upcoming birthdays from org members (next 30 days)
$upcoming_birthdays = [];
$stmt = $conn->prepare("
    SELECT m.*, o.name as org_name,
           DATEDIFF(
               DATE_ADD(
                   DATE(CONCAT(YEAR(CURDATE()), '-', MONTH(m.dob), '-', DAY(m.dob))),
                   INTERVAL IF(
                       DATE(CONCAT(YEAR(CURDATE()), '-', MONTH(m.dob), '-', DAY(m.dob))) < CURDATE(),
                       1,
                       0
                   ) YEAR
               ),
               CURDATE()
           ) as days_until
    FROM members m
    JOIN member_organizations mo ON mo.member_id = m.id
    JOIN organizations o ON mo.organization_id = o.id
    WHERE mo.organization_id IN ($placeholders)
    AND m.dob IS NOT NULL
    HAVING days_until BETWEEN 0 AND 30
    ORDER BY days_until ASC
    LIMIT 5
");
$stmt->bind_param($types, ...$org_ids);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $upcoming_birthdays[] = $row;
}
$stmt->close();

// Start output buffering for page content
ob_start();
?>

<!-- Page Content -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0">Organization Leader Dashboard</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/index.php">Home</a></li>
                        <li class="breadcrumb-item active">Organization Leader Dashboard</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">
            
            <!-- Welcome Alert -->
            <div class="alert alert-success alert-dismissible">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                <h5><i class="icon fas fa-users"></i> Welcome, <?php echo htmlspecialchars($user_name); ?>!</h5>
                You are managing <?php echo count($org_ids); ?> organization<?php echo count($org_ids) > 1 ? 's' : ''; ?>.
            </div>

            <!-- Summary Statistics -->
            <div class="row">
                <div class="col-lg-3 col-6">
                    <div class="small-box bg-primary">
                        <div class="inner">
                            <h3><?php echo $total_members; ?></h3>
                            <p>Total Members</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <a href="member_list.php" class="small-box-footer">
                            View Members <i class="fas fa-arrow-circle-right"></i>
                        </a>
                    </div>
                </div>

                <div class="col-lg-3 col-6">
                    <div class="small-box bg-success">
                        <div class="inner">
                            <h3><?php echo $total_payments_this_month; ?></h3>
                            <p>Payments This Month</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <a href="payment_list.php" class="small-box-footer">
                            View Payments <i class="fas fa-arrow-circle-right"></i>
                        </a>
                    </div>
                </div>

                <div class="col-lg-3 col-6">
                    <div class="small-box bg-warning">
                        <div class="inner">
                            <h3>GH₵ <?php echo number_format($total_amount_this_month, 2); ?></h3>
                            <p>Amount This Month</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <a href="payment_list.php" class="small-box-footer">
                            View Details <i class="fas fa-arrow-circle-right"></i>
                        </a>
                    </div>
                </div>

                <div class="col-lg-3 col-6">
                    <div class="small-box bg-info">
                        <div class="inner">
                            <h3><?php echo count($org_ids); ?></h3>
                            <p>Assigned Organizations</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-sitemap"></i>
                        </div>
                        <a href="#" class="small-box-footer">
                            See Below <i class="fas fa-arrow-circle-down"></i>
                        </a>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- My Organizations -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-sitemap mr-2"></i>My Organizations</h3>
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Organization Name</th>
                                        <th>Church</th>
                                        <th>Members</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($org_info as $org): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($org['name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($org['church_name']); ?></td>
                                        <td><span class="badge badge-primary"><?php echo $org['member_count']; ?></span></td>
                                        <td>
                                            <a href="member_list.php?org=<?php echo $org['id']; ?>" class="btn btn-sm btn-primary">
                                                <i class="fas fa-users"></i> View Members
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Upcoming Birthdays -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-birthday-cake mr-2"></i>Upcoming Birthdays</h3>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($upcoming_birthdays)): ?>
                            <div class="p-3 text-center text-muted">
                                <i class="fas fa-calendar-times fa-2x mb-2"></i>
                                <p>No birthdays in the next 30 days</p>
                            </div>
                            <?php else: ?>
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Organization</th>
                                        <th>Date</th>
                                        <th>Days</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($upcoming_birthdays as $member): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($member['org_name']); ?></td>
                                        <td><?php echo date('M d', strtotime($member['dob'])); ?></td>
                                        <td>
                                            <?php if ($member['days_until'] == 0): ?>
                                                <span class="badge badge-success">Today!</span>
                                            <?php else: ?>
                                                <span class="badge badge-info"><?php echo $member['days_until']; ?> days</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Payments -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-money-bill-wave mr-2"></i>Recent Payments</h3>
                            <div class="card-tools">
                                <a href="payment_list.php" class="btn btn-sm btn-primary">
                                    <i class="fas fa-list"></i> View All
                                </a>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($recent_payments)): ?>
                            <div class="p-3 text-center text-muted">
                                <i class="fas fa-inbox fa-2x mb-2"></i>
                                <p>No recent payments</p>
                            </div>
                            <?php else: ?>
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Member</th>
                                        <th>CRN</th>
                                        <th>Organization</th>
                                        <th>Payment Type</th>
                                        <th>Amount</th>
                                        <th>Mode</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_payments as $payment): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($payment['crn']); ?></td>
                                        <td><?php echo htmlspecialchars($payment['org_name']); ?></td>
                                        <td><?php echo htmlspecialchars($payment['payment_type']); ?></td>
                                        <td><strong>GH₵ <?php echo number_format($payment['amount'], 2); ?></strong></td>
                                        <td>
                                            <?php
                                            $mode_class = '';
                                            switch($payment['mode']) {
                                                case 'Cash': $mode_class = 'badge-success'; break;
                                                case 'MoMo': $mode_class = 'badge-info'; break;
                                                case 'Cheque': $mode_class = 'badge-warning'; break;
                                                default: $mode_class = 'badge-secondary';
                                            }
                                            ?>
                                            <span class="badge <?php echo $mode_class; ?>"><?php echo htmlspecialchars($payment['mode']); ?></span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3 col-sm-6 mb-3">
                                    <a href="member_list.php" class="btn btn-app btn-block">
                                        <i class="fas fa-users"></i> View Members
                                    </a>
                                </div>
                                <div class="col-md-3 col-sm-6 mb-3">
                                    <a href="payment_list.php" class="btn btn-app btn-block">
                                        <i class="fas fa-money-bill-wave"></i> View Payments
                                    </a>
                                </div>
                                <div class="col-md-3 col-sm-6 mb-3">
                                    <a href="organization_list.php" class="btn btn-app btn-block">
                                        <i class="fas fa-sitemap"></i> Organizations
                                    </a>
                                </div>
                                <div class="col-md-3 col-sm-6 mb-3">
                                    <a href="user_dashboard.php" class="btn btn-app btn-block">
                                        <i class="fas fa-tachometer-alt"></i> Main Dashboard
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>

<?php
// Capture page content
$page_content = ob_get_clean();

// Set page title and modal/script HTML
$page_title = "Organization Leader Dashboard";
$modal_html = ''; // No modals on this page
$script_html = ''; // No additional scripts

// Include layout
include __DIR__.'/../includes/layout.php';
?>
