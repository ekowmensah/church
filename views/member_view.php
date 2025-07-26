<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

// Authentication check
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_member_profile')) {
    http_response_code(403);
    echo '<div class="alert alert-danger m-4"><h4>403 Forbidden</h4><p>You do not have permission to view member profiles.</p></div>';
    exit;
}

// Get member ID and validate
$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    echo '<div class="alert alert-danger m-4">Invalid member ID.</div>';
    exit;
}

// Fetch member data with all related information
$stmt = $conn->prepare("
    SELECT m.*, 
           c.name AS class_name, 
           ch.name AS church_name,
           COUNT(DISTINCT p.id) as total_payments,
           COALESCE(SUM(CASE WHEN p.reversal_approved_at IS NULL THEN p.amount ELSE 0 END), 0) as total_amount_paid
    FROM members m 
    LEFT JOIN bible_classes c ON m.class_id = c.id 
    LEFT JOIN churches ch ON m.church_id = ch.id
    LEFT JOIN payments p ON m.id = p.member_id
    WHERE m.id = ?
    GROUP BY m.id
");
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();
$member = $result->fetch_assoc();

if (!$member) {
    echo '<div class="alert alert-danger m-4">Member not found.</div>';
    exit;
}

// Fetch emergency contacts
$contacts_stmt = $conn->prepare("SELECT * FROM member_emergency_contacts WHERE member_id = ? ORDER BY id");
$contacts_stmt->bind_param('i', $id);
$contacts_stmt->execute();
$contacts = $contacts_stmt->get_result();

// Fetch organizations
$org_stmt = $conn->prepare("
    SELECT o.name 
    FROM member_organizations mo 
    JOIN organizations o ON mo.organization_id = o.id 
    WHERE mo.member_id = ?
");
$org_stmt->bind_param('i', $id);
$org_stmt->execute();
$organizations = $org_stmt->get_result();

// Fetch SMS logs
$sms_stmt = $conn->prepare("
    SELECT * FROM sms_logs 
    WHERE member_id = ? 
    ORDER BY sent_at DESC 
    LIMIT 10
");
$sms_stmt->bind_param('i', $id);
$sms_stmt->execute();
$sms_logs = $sms_stmt->get_result();

$page_title = 'Member Profile - ' . htmlspecialchars($member['first_name'] . ' ' . $member['last_name']);
ob_start();
?>
<!-- Custom Styles -->
<style>
.member-profile-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 15px;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.member-avatar {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    border: 4px solid rgba(255,255,255,0.3);
    object-fit: cover;
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
}

.member-avatar-placeholder {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    color: rgba(255,255,255,0.8);
    border: 4px solid rgba(255,255,255,0.3);
}

.info-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
    border: none;
    margin-bottom: 1.5rem;
    overflow: hidden;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.info-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.12);
}

.info-card-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-bottom: 2px solid #dee2e6;
    padding: 1rem 1.5rem;
    font-weight: 600;
    color: #495057;
}

.info-card-body {
    padding: 1.5rem;
}

.info-item {
    display: flex;
    align-items: center;
    margin-bottom: 1rem;
    padding: 0.5rem 0;
    border-bottom: 1px solid #f8f9fa;
}

.info-item:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.info-label {
    font-weight: 600;
    color: #6c757d;
    min-width: 140px;
    margin-right: 1rem;
}

.info-value {
    color: #495057;
    flex: 1;
}

.stats-card {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    color: white;
    border-radius: 12px;
    padding: 1.5rem;
    text-align: center;
    box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
}

.stats-number {
    font-size: 2.5rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.stats-label {
    font-size: 0.9rem;
    opacity: 0.9;
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.action-btn {
    padding: 0.5rem 1rem;
    border-radius: 8px;
    border: none;
    font-weight: 500;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.2s ease;
}

.btn-primary-custom {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    color: white;
}

.btn-success-custom {
    background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
    color: white;
}

.btn-secondary-custom {
    background: linear-gradient(135deg, #6c757d 0%, #545b62 100%);
    color: white;
}

.action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    color: white;
    text-decoration: none;
}

.membership-status {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    border-radius: 25px;
    font-weight: 600;
    font-size: 0.9rem;
}

.status-full {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    color: white;
}

.status-catechumen {
    background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
    color: white;
}

.status-none {
    background: linear-gradient(135deg, #6c757d 0%, #545b62 100%);
    color: white;
}

.action-buttons-card {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border: 1px solid #dee2e6;
    border-radius: 15px;
    padding: 1.5rem;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.member-stats {
    background: rgba(255, 255, 255, 0.8);
    border-radius: 10px;
    padding: 1rem;
}

.stat-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.stat-label {
    font-size: 0.9rem;
    color: #6c757d;
}

.stat-value {
    font-weight: 600;
    color: #495057;
}

.badge-lg {
    font-size: 0.9rem;
    padding: 0.5rem 0.75rem;
}

.gap-2 {
    gap: 0.5rem !important;
}

.gap-3 {
    gap: 1rem !important;
}

@media (max-width: 768px) {
    .member-profile-header {
        text-align: center;
        padding: 1.5rem;
    }
    
    .action-buttons {
        justify-content: center;
        margin-top: 1rem;
    }
    
    .action-buttons-card .d-flex {
        flex-direction: column;
        align-items: stretch !important;
    }

    .member-stats {
        margin-top: 1rem;
    }

    .stat-item {
        justify-content: center;
        text-align: center;
    }
}
</style>

<div class="container-fluid px-4 py-3">
    <!-- Profile Header -->
    <div class="member-profile-header">
        <div class="row align-items-center">
            <div class="col-md-2 text-center mb-3 mb-md-0">
                <?php if (!empty($member['photo']) && file_exists(__DIR__.'/../uploads/members/' . $member['photo'])): ?>
                    <img src="<?= BASE_URL ?>/uploads/members/<?= rawurlencode($member['photo']) ?>?v=<?= time() ?>" 
                         class="member-avatar" alt="Member Photo">
                <?php else: ?>
                    <div class="member-avatar-placeholder">
                        <i class="fas fa-user"></i>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-md-7">
                <h1 class="mb-3 font-weight-bold">
                    <?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?>
                </h1>
                <div class="row">
                    <div class="col-md-6">
                        <p class="mb-2"><i class="fas fa-id-card mr-2"></i><strong>CRN:</strong> <?= htmlspecialchars($member['crn']) ?></p>
                        <p class="mb-2"><i class="fas fa-church mr-2"></i><strong>Church:</strong> <?= htmlspecialchars($member['church_name'] ?? 'N/A') ?></p>
                        <p class="mb-2"><i class="fas fa-users mr-2"></i><strong>Class:</strong> <?= htmlspecialchars($member['class_name'] ?? 'N/A') ?></p>
                    </div>
                    <div class="col-md-6">
                        <p class="mb-2">
                            <i class="fas fa-user-check mr-2"></i><strong>Status:</strong> 
                            <span class="badge badge-<?= ($member['status'] === 'active' ? 'success' : ($member['status'] === 'pending' ? 'warning' : 'secondary')) ?> ml-1">
                                <?= htmlspecialchars(ucfirst($member['status'])) ?>
                            </span>
                        </p>
                        <p class="mb-2">
                            <i class="fas fa-certificate mr-2"></i><strong>Membership:</strong>
                            <?php
                            $is_confirmed = (strtolower($member['confirmed'] ?? '') === 'yes');
                            $is_baptized = (strtolower($member['baptized'] ?? '') === 'yes');
                            
                            if ($is_confirmed && $is_baptized) {
                                echo '<span class="membership-status status-full"><i class="fas fa-check-circle"></i> Full Member</span>';
                            } elseif ($is_confirmed || $is_baptized) {
                                echo '<span class="membership-status status-catechumen"><i class="fas fa-clock"></i> Catechumen</span>';
                            } else {
                                echo '<span class="membership-status status-none"><i class="fas fa-minus-circle"></i> No Status</span>';
                            }
                            ?>
                        </p>
                        <p class="mb-2"><i class="fas fa-calendar mr-2"></i><strong>Joined:</strong> <?= date('M d, Y', strtotime($member['created_at'])) ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card mb-3">
                    <div class="stats-number">₵<?= number_format($member['total_amount_paid'], 2) ?></div>
                    <div class="stats-label">Total Paid</div>
                    <small class="d-block mt-1"><?= $member['total_payments'] ?> payments</small>
                </div>
                <div class="action-buttons">
                    <a href="<?= BASE_URL ?>/views/member_list.php" class="action-btn btn-secondary-custom">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                    <button class="action-btn btn-success-custom" id="viewHealthBtn">
                        <i class="fas fa-notes-medical"></i> Health Records
                    </button>
                    <a href="<?= BASE_URL ?>/views/admin_member_edit.php?id=<?= $member['id'] ?>" class="action-btn btn-primary-custom">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                </div>
            </div>
        </div>
    </div>
    <!-- Information Cards -->
    <div class="row">
        <!-- Personal Information -->
        <div class="col-lg-6 mb-4">
            <div class="info-card">
                <div class="info-card-header">
                    <i class="fas fa-user mr-2"></i>Personal Information
                </div>
                <div class="info-card-body">
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-id-card mr-2"></i>CRN:</div>
                        <div class="info-value"><?= htmlspecialchars($member['crn'] ?? 'N/A') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-user mr-2"></i>Full Name:</div>
                        <div class="info-value"><?= htmlspecialchars(trim($member['first_name'] . ' ' . ($member['middle_name'] ? $member['middle_name'] . ' ' : '') . $member['last_name'])) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-venus-mars mr-2"></i>Gender:</div>
                        <div class="info-value">
                            <span class="badge badge-<?= strtolower($member['gender']) === 'male' ? 'primary' : 'pink' ?>">
                                <?= htmlspecialchars($member['gender'] ?? 'N/A') ?>
                            </span>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-birthday-cake mr-2"></i>Date of Birth:</div>
                        <div class="info-value"><?= htmlspecialchars($member['dob'] ?? 'N/A') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-calendar-day mr-2"></i>Day Born:</div>
                        <div class="info-value"><?= htmlspecialchars($member['day_born'] ?? 'N/A') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-map-marker-alt mr-2"></i>Place of Birth:</div>
                        <div class="info-value"><?= htmlspecialchars($member['place_of_birth'] ?? 'N/A') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-heart mr-2"></i>Marital Status:</div>
                        <div class="info-value">
                            <span class="badge badge-info">
                                <?= htmlspecialchars($member['marital_status'] ?? 'N/A') ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Contact Information -->
        <div class="col-lg-6 mb-4">
            <div class="info-card">
                <div class="info-card-header">
                    <i class="fas fa-address-book mr-2"></i>Contact Information
                </div>
                <div class="info-card-body">
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-phone mr-2"></i>Phone:</div>
                        <div class="info-value">
                            <?php if (!empty($member['phone'])): ?>
                                <a href="tel:<?= htmlspecialchars($member['phone']) ?>" class="text-primary">
                                    <?= htmlspecialchars($member['phone']) ?>
                                </a>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-home mr-2"></i>Address:</div>
                        <div class="info-value"><?= htmlspecialchars($member['address'] ?? 'N/A') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-map-pin mr-2"></i>GPS Address:</div>
                        <div class="info-value"><?= htmlspecialchars($member['gps_address'] ?? 'N/A') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-city mr-2"></i>Home Town:</div>
                        <div class="info-value"><?= htmlspecialchars($member['home_town'] ?? 'N/A') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-map mr-2"></i>Region:</div>
                        <div class="info-value"><?= htmlspecialchars($member['region'] ?? 'N/A') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-phone-alt mr-2"></i>Telephone:</div>
                        <div class="info-value">
                            <?php if (!empty($member['telephone'])): ?>
                                <a href="tel:<?= htmlspecialchars($member['telephone']) ?>" class="text-primary">
                                    <?= htmlspecialchars($member['telephone']) ?>
                                </a>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-envelope mr-2"></i>Email:</div>
                        <div class="info-value">
                            <?php if (!empty($member['email'])): ?>
                                <a href="mailto:<?= htmlspecialchars($member['email']) ?>" class="text-primary">
                                    <?= htmlspecialchars($member['email']) ?>
                                </a>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Spiritual & Professional Information -->
        <div class="col-lg-6 mb-4">
            <div class="info-card">
                <div class="info-card-header">
                    <i class="fas fa-cross mr-2"></i>Spiritual & Professional Information
                </div>
                <div class="info-card-body">
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-calendar-plus mr-2"></i>Date of Enrollment:</div>
                        <div class="info-value"><?= htmlspecialchars($member['date_of_enrollment'] ?? 'N/A') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-check-circle mr-2"></i>Confirmed:</div>
                        <div class="info-value">
                            <span class="badge badge-<?= strtolower($member['confirmed'] ?? '') === 'yes' ? 'success' : 'secondary' ?>">
                                <?= htmlspecialchars($member['confirmed'] ?? 'No') ?>
                            </span>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-water mr-2"></i>Baptized:</div>
                        <div class="info-value">
                            <span class="badge badge-<?= strtolower($member['baptized'] ?? '') === 'yes' ? 'success' : 'secondary' ?>">
                                <?= htmlspecialchars($member['baptized'] ?? 'No') ?>
                            </span>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-calendar-check mr-2"></i>Date of Confirmation:</div>
                        <div class="info-value"><?= htmlspecialchars($member['date_of_confirmation'] ?? 'N/A') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-briefcase mr-2"></i>Occupation:</div>
                        <div class="info-value"><?= htmlspecialchars($member['occupation'] ?? 'N/A') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-building mr-2"></i>Employer:</div>
                        <div class="info-value"><?= htmlspecialchars($member['employer'] ?? 'N/A') ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Emergency Contacts -->
        <div class="col-lg-6 mb-4">
            <div class="info-card">
                <div class="info-card-header">
                    <i class="fas fa-phone-square-alt mr-2"></i>Emergency Contacts
                </div>
                <div class="info-card-body">
                    <?php if ($contacts->num_rows > 0): ?>
                        <?php $contact_num = 1; while ($contact = $contacts->fetch_assoc()): ?>
                            <div class="info-item">
                                <div class="info-label"><i class="fas fa-user-friends mr-2"></i>Contact <?= $contact_num ?>:</div>
                                <div class="info-value">
                                    <strong><?= htmlspecialchars($contact['name']) ?></strong><br>
                                    <small class="text-muted">
                                        <i class="fas fa-phone mr-1"></i><?= htmlspecialchars($contact['mobile']) ?><br>
                                        <i class="fas fa-heart mr-1"></i><?= htmlspecialchars($contact['relationship']) ?>
                                    </small>
                                </div>
                            </div>
                            <?php $contact_num++; ?>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="text-center text-muted py-3">
                            <i class="fas fa-user-slash fa-2x mb-2"></i>
                            <p>No emergency contacts recorded.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Organizations -->
    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="info-card">
                <div class="info-card-header">
                    <i class="fas fa-sitemap mr-2"></i>Organizations
                </div>
                <div class="info-card-body">
                    <?php if ($organizations->num_rows > 0): ?>
                        <div class="d-flex flex-wrap gap-2">
                            <?php while ($org = $organizations->fetch_assoc()): ?>
                                <span class="badge badge-primary badge-lg">
                                    <i class="fas fa-users mr-1"></i><?= htmlspecialchars($org['name']) ?>
                                </span>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-muted py-3">
                            <i class="fas fa-users-slash fa-2x mb-2"></i>
                            <p>No organizations assigned.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent SMS Notifications -->
        <div class="col-lg-6 mb-4">
            <div class="info-card">
                <div class="info-card-header">
                    <i class="fas fa-sms mr-2"></i>Recent SMS Notifications
                </div>
                <div class="info-card-body">
                    <?php if ($sms_logs->num_rows > 0): ?>
                        <div class="sms-logs-container" style="max-height: 300px; overflow-y: auto;">
                            <?php while ($sms = $sms_logs->fetch_assoc()): ?>
                                <div class="info-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center mb-1">
                                                <span class="badge badge-<?= strtolower($sms['type']) === 'registration' ? 'success' : 'info' ?> mr-2">
                                                    <?= htmlspecialchars(ucfirst($sms['type'])) ?>
                                                </span>
                                                <small class="text-muted">
                                                    <?= date('M d, Y H:i', strtotime($sms['sent_at'])) ?>
                                                </small>
                                            </div>
                                            <p class="mb-1 small"><?= htmlspecialchars(substr($sms['message'], 0, 100)) ?><?= strlen($sms['message']) > 100 ? '...' : '' ?></p>
                                        </div>
                                        <div class="ml-2">
                                            <?php
                                            $status = $sms['status'] ?? '';
                                            if (stripos($status, 'fail') !== false) {
                                                echo '<span class="badge badge-danger">Failed</span>';
                                            } elseif (stripos($status, 'sent') !== false || stripos($status, 'success') !== false) {
                                                echo '<span class="badge badge-success">Sent</span>';
                                            } else {
                                                echo '<span class="badge badge-secondary">' . htmlspecialchars($status) . '</span>';
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-muted py-3">
                            <i class="fas fa-sms fa-2x mb-2"></i>
                            <p>No SMS notifications sent.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="action-buttons-card">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div class="d-flex flex-wrap gap-2">
                        <a href="<?= BASE_URL ?>/views/member_list.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Members
                        </a>
                        <?php if (has_permission('edit_member')): ?>
                            <a href="<?= BASE_URL ?>/views/admin_member_edit.php?id=<?= $member['id'] ?>" class="btn btn-primary">
                                <i class="fas fa-edit mr-2"></i>Edit Member
                            </a>
                        <?php endif; ?>
                        <?php if (has_permission('view_health_records')): ?>
                            <button type="button" class="btn btn-info" id="viewHealthBtn">
                                <i class="fas fa-heartbeat mr-2"></i>Health Records
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="member-stats">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-item">
                                <i class="fas fa-credit-card text-success mr-1"></i>
                                <span class="stat-label">Total Payments:</span>
                                <span class="stat-value total-payments" data-member-id="<?= $member['id'] ?>">₵0.00</span>
                            </div>
                            <div class="stat-item">
                                <i class="fas fa-calendar-check text-info mr-1"></i>
                                <span class="stat-label">Member Since:</span>
                                <span class="stat-value"><?= date('M Y', strtotime($member['created_at'])) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
</div>

<!-- Health Records Modal -->
<div class="modal fade" id="healthModal" tabindex="-1" role="dialog" aria-labelledby="healthModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="healthModalLabel">Health Records</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body" id="healthRecordsBody">
        <div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i> Loading...</div>
      </div>
    </div>
  </div>
</div>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script>
// Define BASE_URL for JavaScript
const BASE_URL = '<?= BASE_URL ?>';

// Fetch total payments
$(function() {
  var span = $('.total-payments');
  var memberId = span.data('member-id');
  $.get(BASE_URL + '/ajax_get_total_payments.php', {member_id: memberId}, function(res) {
    if (res && typeof res.total !== 'undefined') {
      span.text('₵' + parseFloat(res.total).toLocaleString(undefined, {minimumFractionDigits: 2}));
    }
  }, 'json').fail(function() {
    span.text('₵0.00');
  });
});

// Health Records modal (robust binding)
$(document).on('click', '#viewHealthBtn', function() {
  var memberId = $('.total-payments').data('member-id');
  $('#healthRecordsBody').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i> Loading...</div>');
  $('#healthModal').modal('show');
  $.get(BASE_URL + '/ajax_get_health_records.php', {member_id: memberId}, function(html) {
    $('#healthRecordsBody').html(html);
    if ($('#healthRecordsTable').length) {
      $('#healthRecordsTable').DataTable({
        responsive: true,
        pageLength: 10,
        order: [[0, 'desc']]
      });
    }
  }).fail(function() {
    $('#healthRecordsBody').html('<div class="text-center text-muted py-4"><i class="fas fa-exclamation-triangle fa-2x mb-2"></i><p>Failed to load health records.</p></div>');
  });
});
</script>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
?>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    var span = $('.total-payments');
    var memberId = span.data('member-id');
    $.get('ajax_get_total_payments.php', {member_id: memberId}, function(res) {
        if (res && typeof res.total !== 'undefined') {
            var total = parseFloat(res.total);
            span.text('₵' + total.toLocaleString(undefined, {minimumFractionDigits: 2}));
            if (total > 0) {
                span.removeClass('badge-secondary').addClass('badge-success');
            } else {
                span.removeClass('badge-success').addClass('badge-secondary');
            }
        }
    }, 'json');
});
</script>
