<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/leader_helpers.php';

// Check if user is logged in
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Check if user is an organization leader
$user_id = $_SESSION['user_id'] ?? null;
$member_id = $_SESSION['member_id'] ?? null;
$org_leaderships = is_organization_leader($conn, $user_id, $member_id);

if (!$org_leaderships) {
    http_response_code(403);
    echo '<div class="alert alert-danger">You are not assigned as an organization leader.</div>';
    exit;
}

// If only one organization, redirect directly to that organization's dashboard
if (count($org_leaderships) === 1) {
    header('Location: my_organization_leader.php?org_id=' . $org_leaderships[0]['organization_id']);
    exit;
}

ob_start();
?>
<style>
.org-selector-header {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    color: white;
    padding: 40px;
    border-radius: 15px;
    margin-bottom: 30px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
    text-align: center;
}

.org-card {
    background: white;
    border-radius: 15px;
    padding: 30px;
    margin-bottom: 20px;
    box-shadow: 0 3px 15px rgba(0,0,0,0.08);
    transition: all 0.3s ease;
    border-left: 5px solid #f093fb;
    cursor: pointer;
}

.org-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    border-left-color: #f5576c;
}

.org-icon {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    color: white;
    margin: 0 auto 20px;
}

.org-name {
    font-size: 1.5rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 10px;
}

.org-description {
    color: #6c757d;
    margin-bottom: 20px;
    line-height: 1.6;
}

.org-stats {
    display: flex;
    justify-content: space-around;
    padding-top: 20px;
    border-top: 1px solid #e9ecef;
}

.stat-item {
    text-align: center;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: #f5576c;
}

.stat-label {
    font-size: 0.85rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
</style>

<div class="org-selector-header">
    <h1><i class="fas fa-users-cog"></i> My Organization Leadership</h1>
    <p class="mb-0">You are a leader of <?= count($org_leaderships) ?> organizations. Select one to manage:</p>
</div>

<div class="row">
    <?php foreach ($org_leaderships as $org): ?>
        <?php
        // Get member count
        $stmt = $conn->prepare("
            SELECT COUNT(DISTINCT mo.member_id) as member_count
            FROM member_organizations mo
            WHERE mo.organization_id = ?
        ");
        $stmt->bind_param('i', $org['organization_id']);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        // Get recent payment count (last 30 days)
        $stmt = $conn->prepare("
            SELECT COUNT(p.id) as payment_count, COALESCE(SUM(p.amount), 0) as total_amount
            FROM payments p
            INNER JOIN member_organizations mo ON p.member_id = mo.member_id
            WHERE mo.organization_id = ? AND p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ");
        $stmt->bind_param('i', $org['organization_id']);
        $stmt->execute();
        $payment_stats = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        ?>
        <div class="col-md-6">
            <div class="org-card" onclick="window.location.href='my_organization_leader.php?org_id=<?= $org['organization_id'] ?>'">
                <div class="org-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="org-name text-center">
                    <?= htmlspecialchars($org['org_name']) ?>
                </div>
                <?php if ($org['description']): ?>
                <div class="org-description text-center">
                    <?= htmlspecialchars($org['description']) ?>
                </div>
                <?php endif; ?>
                
                <div class="org-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?= $stats['member_count'] ?></div>
                        <div class="stat-label">Members</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= $payment_stats['payment_count'] ?></div>
                        <div class="stat-label">Payments (30d)</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">GH₵ <?= number_format($payment_stats['total_amount'], 0) ?></div>
                        <div class="stat-label">Total (30d)</div>
                    </div>
                </div>
                
                <div class="text-center mt-3">
                    <button class="btn btn-primary btn-lg">
                        <i class="fas fa-arrow-right"></i> Manage Organization
                    </button>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php
$page_content = ob_get_clean();
$page_title = 'My Organization Leadership';
include '../includes/layout.php';
?>
