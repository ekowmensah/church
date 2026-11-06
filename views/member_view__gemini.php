<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Authentication check
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Permission check
$is_super_admin = is_super_admin();

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

$full_name = trim(implode(' ', array_filter([
    (string) ($member['first_name'] ?? ''),
    (string) ($member['middle_name'] ?? ''),
    (string) ($member['last_name'] ?? ''),
])));

$format_member_date = static function (?string $dateValue, string $format = 'M d, Y'): string {
    $dateValue = trim((string) $dateValue);
    if ($dateValue === '' || $dateValue === '0000-00-00') {
        return 'N/A';
    }

    $timestamp = strtotime($dateValue);
    if ($timestamp === false) {
        return 'N/A';
    }

    return date($format, $timestamp);
};

$format_member_age = static function (?string $dobValue): string {
    $dobValue = trim((string) $dobValue);
    if ($dobValue === '' || $dobValue === '0000-00-00') {
        return 'N/A';
    }

    $birthDate = DateTime::createFromFormat('Y-m-d', $dobValue);
    if (!($birthDate instanceof DateTime) || $birthDate->format('Y-m-d') !== $dobValue) {
        return 'N/A';
    }

    $today = new DateTime('today');
    if ($birthDate > $today) {
        return 'N/A';
    }

    $age = $birthDate->diff($today);
    return sprintf(
        '%d year%s, %d month%s, %d day%s',
        $age->y,
        $age->y === 1 ? '' : 's',
        $age->m,
        $age->m === 1 ? '' : 's',
        $age->d,
        $age->d === 1 ? '' : 's'
    );
};

$dob_age_text = $format_member_age($member['dob'] ?? null);

$spouse_display_name = trim((string) ($member['spouse_name'] ?? ''));
$spouse_crn = trim((string) ($member['spouse_crn'] ?? ''));
$spouse_member_id = 0;
$spouse_photo_url = BASE_URL . '/assets/img/undraw_profile.svg';
if ($spouse_display_name === '' && $spouse_crn !== '') {
    $spouse_stmt = $conn->prepare('SELECT id, first_name, middle_name, last_name, photo FROM members WHERE crn = ? LIMIT 1');
    if ($spouse_stmt) {
        $spouse_stmt->bind_param('s', $spouse_crn);
        $spouse_stmt->execute();
        $spouse_result = $spouse_stmt->get_result();
        $spouse_member = $spouse_result ? $spouse_result->fetch_assoc() : null;
        $spouse_stmt->close();

        if ($spouse_member) {
            $spouse_member_id = (int) ($spouse_member['id'] ?? 0);
            $spouse_display_name = trim(implode(' ', array_filter([
                (string) ($spouse_member['first_name'] ?? ''),
                (string) ($spouse_member['middle_name'] ?? ''),
                (string) ($spouse_member['last_name'] ?? ''),
            ])));
            $spouse_photo = trim((string) ($spouse_member['photo'] ?? ''));
            if ($spouse_photo !== '' && file_exists(__DIR__.'/../uploads/members/' . $spouse_photo)) {
                $spouse_photo_url = BASE_URL . '/uploads/members/' . rawurlencode($spouse_photo) . '?v=' . time();
            }
        }
    }
}

if ($spouse_member_id <= 0 && $spouse_crn !== '') {
    $spouse_id_stmt = $conn->prepare('SELECT id, photo FROM members WHERE crn = ? LIMIT 1');
    if ($spouse_id_stmt) {
        $spouse_id_stmt->bind_param('s', $spouse_crn);
        $spouse_id_stmt->execute();
        $spouse_id_result = $spouse_id_stmt->get_result();
        $spouse_id_row = $spouse_id_result ? $spouse_id_result->fetch_assoc() : null;
        $spouse_member_id = (int) ($spouse_id_row['id'] ?? 0);
        $spouse_photo = trim((string) ($spouse_id_row['photo'] ?? ''));
        if ($spouse_photo !== '' && file_exists(__DIR__.'/../uploads/members/' . $spouse_photo)) {
            $spouse_photo_url = BASE_URL . '/uploads/members/' . rawurlencode($spouse_photo) . '?v=' . time();
        }
        $spouse_id_stmt->close();
    }
}

$has_spouse_reference = ($spouse_display_name !== '' || $spouse_crn !== '');

$has_transfer_details = ((int) ($member['transfer_from_other_chapel'] ?? 0) === 1)
    || trim((string) ($member['transfer_diocese'] ?? '')) !== ''
    || trim((string) ($member['transfer_circuit'] ?? '')) !== ''
    || trim((string) ($member['transfer_society'] ?? '')) !== ''
    || trim((string) ($member['superintendent_name'] ?? '')) !== '';

// Fetch emergency contacts (+ linked member if mobile belongs to a known member)
$contacts_stmt = $conn->prepare("
    SELECT mec.*,
           m.id AS linked_member_id,
           m.crn AS linked_member_crn,
           CONCAT_WS(' ', m.first_name, m.middle_name, m.last_name) AS linked_member_name
    FROM member_emergency_contacts mec
    LEFT JOIN members m ON m.phone = mec.mobile AND m.id <> ?
    WHERE mec.member_id = ?
    ORDER BY mec.id
");
$contacts_stmt->bind_param('ii', $id, $id);
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

$page_title = 'Member Profile - ' . htmlspecialchars($full_name !== '' ? $full_name : 'Member');
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

.spouse-avatar-thumb {
    width: 56px;
    height: 56px;
    object-fit: cover;
    border-radius: 50%;
    border: 2px solid #dee2e6;
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

.member-profile-page {
    --profile-ink: #1c2d36;
    --profile-muted: #647680;
    --profile-line: #dce5e8;
    --profile-soft: #f3f7f6;
    --profile-green: #176b50;
    color: var(--profile-ink);
    max-width: 1500px;
    margin: 0 auto;
}
.member-profile-page .profile-content-grid { align-items: start; display: grid; gap: 20px; grid-template-columns: minmax(0, 1.55fr) minmax(300px, .85fr); }
.member-profile-page .profile-primary-column, .member-profile-page .profile-secondary-column { display: flex; flex-direction: column; gap: 16px; min-width: 0; }
.member-profile-page .profile-section { margin: 0; }
.member-profile-page .member-profile-header {
    background: #183c36;
    border: 1px solid #264e46;
    border-radius: 10px;
    box-shadow: none;
    margin-bottom: 20px;
    padding: 22px 26px;
}
.member-profile-page .member-profile-header h1 { font-size: 1.8rem; line-height: 1.15; margin: 0 0 12px; }
.member-profile-page .member-avatar, .member-profile-page .member-avatar-placeholder { height: 104px; width: 104px; }
.member-profile-page .member-avatar-placeholder { background: rgba(255,255,255,.1); font-size: 2.4rem; }
.profile-meta { color: rgba(255,255,255,.8); display: flex; flex-wrap: wrap; gap: 8px 18px; }
.profile-meta span { align-items: center; display: inline-flex; gap: 7px; }
.profile-meta strong { color: #fff; font-weight: 650; }
.member-profile-page .membership-status { border-radius: 5px; font-size: .78rem; padding: 4px 8px; }
.member-profile-page .status-full { background: #d7eee3; color: #17593f; }
.member-profile-page .status-catechumen { background: #fff0cf; color: #77510b; }
.member-profile-page .status-none { background: #e7ecee; color: #455660; }
.member-profile-actions { align-items: flex-end; display: flex; flex-direction: column; gap: 12px; }
.member-profile-page .stats-card { background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.18); border-radius: 8px; box-shadow: none; min-width: 205px; padding: 11px 15px; text-align: right; }
.member-profile-page .stats-number { font-size: 1.45rem; margin-bottom: 0; }
.member-profile-page .stats-label { font-size: .72rem; font-weight: 750; letter-spacing: .06em; text-transform: uppercase; }
.member-profile-page .action-buttons { gap: 7px; justify-content: flex-end; }
.member-profile-page .action-btn { border: 1px solid rgba(255,255,255,.3); border-radius: 6px; font-size: .84rem; padding: 7px 10px; }
.member-profile-page .action-btn:hover { box-shadow: none; transform: translateY(-1px); }
.member-profile-page .btn-primary-custom { background: #fff; color: #183c36; }
.member-profile-page .btn-success-custom { background: #d8eee4; color: #185b42; }
.member-profile-page .btn-secondary-custom { background: transparent; color: #fff; }
.member-profile-page .info-card { border: 1px solid var(--profile-line); border-radius: 8px; box-shadow: none; margin-bottom: 0; }
.member-profile-page .info-card:hover { box-shadow: 0 5px 16px rgba(27,52,61,.06); transform: none; }
.member-profile-page .info-card-header { background: #fff; border-bottom: 1px solid var(--profile-line); color: var(--profile-ink); font-size: .98rem; padding: 13px 17px; }
.member-profile-page .info-card-header i { color: var(--profile-green); }
.member-profile-page .info-card-body { padding: 12px 17px; }
.member-profile-page .info-card-body { align-content: start; column-gap: 22px; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); }
.member-profile-page .info-item { border-bottom: 1px solid #edf1f2; display: block; margin: 0; min-width: 0; padding: 12px 0; }
.member-profile-page .info-item:last-child { border-bottom: 0; }
.member-profile-page .info-item-wide { grid-column: 1 / -1; }
.member-profile-page .info-label { color: var(--profile-muted); display: block; font-size: .7rem; font-weight: 750; letter-spacing: .035em; margin: 0 0 5px; min-width: 0; text-transform: uppercase; }
.member-profile-page .info-value { color: var(--profile-ink); font-size: .92rem; line-height: 1.45; min-width: 0; overflow-wrap: anywhere; }
.member-profile-page .info-label i { color: #83939a; margin-right: 5px; width: 14px; }
.member-profile-page .action-buttons-card { background: #fff; border: 1px solid var(--profile-line); border-radius: 8px; box-shadow: none; }
.member-profile-page .member-stats { background: var(--profile-soft); border-radius: 6px; }
.member-profile-page .stat-label { color: var(--profile-muted); font-size: .78rem; }
.member-profile-page .stat-value { color: var(--profile-ink); font-size: .86rem; }
.member-profile-page .badge { font-weight: 650; }
.member-profile-page .info-card-header { align-items: center; display: flex; gap: 8px; }
.member-profile-page .info-card-header::after { color: #8a9ba1; content: ''; flex: 1; height: 1px; background: var(--profile-line); margin-left: 8px; }
.member-profile-page .info-card-body .text-center { padding: 18px 10px !important; }
.member-profile-page .empty-icon { align-items: center; background: var(--profile-soft); border-radius: 50%; color: #789087; display: inline-flex; height: 42px; justify-content: center; width: 42px; }
.member-profile-page .profile-section-nav { border-bottom: 1px solid var(--profile-line); display: flex; flex-wrap: wrap; gap: 6px; margin: -4px 0 18px; padding-bottom: 12px; }
.member-profile-page .profile-section-nav a { border-radius: 5px; color: #52656e; font-size: .82rem; padding: 6px 9px; text-decoration: none; }
.member-profile-page .profile-section-nav a:hover { background: var(--profile-soft); color: var(--profile-green); }
.member-profile-page .sms-log-item { border-bottom: 1px solid var(--profile-line); padding: 11px 0; }
.member-profile-page .sms-log-item:last-child { border-bottom: 0; }
.member-profile-page .profile-header-id { color: #c3ded2; font-size: .84rem; margin-top: -6px; }
.member-profile-page .profile-section-nav { position: sticky; top: 0; z-index: 20; background: rgba(255,255,255,.96); padding-top: 8px; }
.member-profile-page .profile-section { scroll-margin-top: 72px; }
.member-profile-page .info-card-header { font-size: 1rem; }
.member-profile-page .info-card-body a:not(.btn) { color: #176b50; font-weight: 650; }
.member-profile-page .info-card-body a:not(.btn):hover { color: #104c39; }
.member-profile-page .badge-lg { background: #e8f2ed; color: #185b42; font-size: .82rem; font-weight: 700; }
@media (max-width: 767.98px) {
    .member-profile-page { padding-left: 12px; padding-right: 12px; }
    .member-profile-page .member-profile-header { padding: 18px 16px; }
    .member-profile-page .member-profile-header h1 { font-size: 1.5rem; }
    .member-profile-actions { align-items: stretch; margin-top: 16px; }
    .member-profile-page .stats-card { text-align: center; }
    .member-profile-page .action-buttons { justify-content: center; }
    .member-profile-page .info-label { flex-basis: 42%; }
    .member-profile-page .profile-content-grid { grid-template-columns: 1fr; }
    .member-profile-page .info-card-body { column-gap: 14px; }
}
@media (max-width: 520px) {
    .member-profile-page .info-card-body { grid-template-columns: 1fr; }
    .member-profile-page .info-item-wide { grid-column: auto; }
    .member-profile-page .profile-section-nav { flex-wrap: nowrap; overflow-x: auto; }
    .member-profile-page .profile-section-nav a { flex: 0 0 auto; }
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

<div class="container-fluid px-4 py-3 member-profile-page">
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
                <div class="small text-uppercase font-weight-bold mb-2" style="letter-spacing:.08em;color:#a8d7c3;">Member profile</div>
                <h1 class="font-weight-bold">
                    <?= htmlspecialchars($full_name !== '' ? $full_name : 'N/A') ?>
                </h1>
                <div class="profile-meta">
                    <span><i class="fas fa-id-card"></i><strong><?= htmlspecialchars($member['crn'] ?? 'N/A') ?></strong></span>
                    <span><i class="fas fa-church"></i><?= htmlspecialchars($member['church_name'] ?? 'N/A') ?></span>
                    <span><i class="fas fa-users"></i><?= htmlspecialchars($member['class_name'] ?? 'N/A') ?></span>
                    <?php if ($dob_age_text !== 'N/A'): ?><span><i class="fas fa-birthday-cake"></i><?= htmlspecialchars($dob_age_text) ?></span><?php endif; ?>
                    <span><i class="fas fa-heart"></i><?= htmlspecialchars($member['marital_status'] ?? 'N/A') ?></span>
                    <span><i class="fas fa-water"></i>Baptized <?= htmlspecialchars(ucfirst((string) ($member['baptized'] ?? 'No'))) ?></span>
                    <span><i class="fas fa-user-check"></i><span class="badge badge-<?= ($member['status'] === 'active' ? 'success' : ($member['status'] === 'pending' ? 'warning' : 'secondary')) ?>"> <?= htmlspecialchars(ucfirst($member['status'])) ?></span></span>
                    <span><i class="fas fa-certificate"></i>
                        <?php
                        $membership_label = trim((string) ($member['membership_status'] ?? '')) ?: 'Unclassified';
                        $membership_class = $membership_label === 'Full Member'
                            ? 'status-full'
                            : ($membership_label === 'Catechumen' ? 'status-catechumen' : 'status-none');
                        echo '<span class="membership-status ' . $membership_class . '">' . htmlspecialchars($membership_label) . '</span>';
                        ?>
                    </span>
                    <span><i class="fas fa-calendar"></i>Joined <?= htmlspecialchars($format_member_date($member['created_at'] ?? null)) ?></span>
                </div>
                <div class="profile-header-id">Member record #<?= (int) $member['id'] ?></div>
            </div>
            <div class="col-md-3 member-profile-actions">
                <div class="stats-card mb-3">
                    <div class="stats-number total-payments" data-member-id="<?= (int) $member['id'] ?>">GHS <?= number_format($member['total_amount_paid'], 2) ?></div>
                    <div class="stats-label">Total Paid</div>
                    <small class="d-block mt-1"><?= $member['total_payments'] ?> payments</small>
                </div>
                <div class="action-buttons">
                    <a href="<?= BASE_URL ?>/views/member_list.php" class="action-btn btn-secondary-custom">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                    <?php if (has_permission('view_health_records')): ?><button type="button" class="action-btn btn-success-custom" id="viewHealthBtn">
                        <i class="fas fa-notes-medical"></i> Health Records
                    </button><?php endif; ?>
                    <?php if (has_permission('edit_member')): ?><a href="<?= BASE_URL ?>/views/admin_member_edit.php?id=<?= $member['id'] ?>" class="action-btn btn-primary-custom">
                        <i class="fas fa-edit"></i> Edit
                    </a><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <nav class="profile-section-nav" aria-label="Member profile sections">
        <a href="#profile-personal">Personal</a>
        <a href="#profile-contact">Contact</a>
        <a href="#profile-faith">Faith &amp; Work</a>
        <a href="#profile-emergency">Emergency contacts</a>
        <a href="#profile-organizations">Organizations</a>
        <a href="#profile-messages">SMS history</a>
    </nav>

    <div class="profile-content-grid">
      <div class="profile-primary-column">
        <!-- Personal Information -->
        <section class="profile-section" id="profile-personal">
            <div class="info-card">
                <div class="info-card-header">
                    <i class="fas fa-user mr-2"></i>Personal Information
                </div>
                <div class="info-card-body">
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
                        <div class="info-value">
                            <?= htmlspecialchars($format_member_date($member['dob'] ?? null)) ?>
                            <?php if ($dob_age_text !== 'N/A'): ?>
                                <small class="text-muted ml-1">(Age: <?= htmlspecialchars($dob_age_text) ?>)</small>
                            <?php endif; ?>
                        </div>
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
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-ring mr-2"></i>Nature of Marriage:</div>
                        <div class="info-value"><?= htmlspecialchars((string) ($member['marriage_type'] ?? '') !== '' ? (string) $member['marriage_type'] : 'N/A') ?></div>
                    </div>
                    <div class="info-item info-item-wide">
                        <div class="info-label"><i class="fas fa-user-friends mr-2"></i>Spouse:</div>
                        <div class="info-value">
                            <div class="d-flex align-items-center">
                                <?php if ($has_spouse_reference): ?>
                                    <button type="button" class="btn p-0 border-0 bg-transparent mr-3" data-toggle="modal" data-target="#spousePhotoModal" title="Expand spouse photo">
                                        <img src="<?= htmlspecialchars($spouse_photo_url) ?>" alt="Spouse photo" class="spouse-avatar-thumb">
                                    </button>
                                <?php endif; ?>
                                <div>
                                    <?= htmlspecialchars($spouse_display_name !== '' ? $spouse_display_name : 'N/A') ?>
                                    <?php if ($spouse_crn !== ''): ?>
                                        <small class="text-muted d-block">CRN: <?= htmlspecialchars($spouse_crn) ?></small>
                                    <?php endif; ?>
                                    <?php if ($has_spouse_reference): ?>
                                        <button type="button" class="btn btn-link btn-sm p-0 mt-1" data-toggle="modal" data-target="#spousePhotoModal">
                                            <i class="fas fa-search-plus mr-1"></i>Expand Photo
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($spouse_member_id > 0): ?>
                                        <a href="<?= BASE_URL ?>/views/member_view.php?id=<?= (int) $spouse_member_id ?>" class="d-inline-block mt-1">
                                            <i class="fas fa-external-link-alt mr-1"></i>View Member
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Contact Information -->
        <section class="profile-section" id="profile-contact">
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
        </section>

        <!-- Spiritual & Professional Information -->
        <section class="profile-section" id="profile-faith">
            <div class="info-card">
                <div class="info-card-header">
                    <i class="fas fa-cross mr-2"></i>Spiritual & Professional Information
                </div>
                <div class="info-card-body">
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-calendar-plus mr-2"></i>Date of Enrollment:</div>
                        <div class="info-value"><?= htmlspecialchars($format_member_date($member['date_of_enrollment'] ?? null)) ?></div>
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
                        <div class="info-label"><i class="fas fa-water mr-2"></i>Date of Baptism:</div>
                        <div class="info-value"><?= htmlspecialchars($format_member_date($member['date_of_baptism'] ?? null)) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-calendar-check mr-2"></i>Date of Confirmation:</div>
                        <div class="info-value"><?= htmlspecialchars($format_member_date($member['date_of_confirmation'] ?? null)) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-award mr-2"></i>Membership Status:</div>
                        <div class="info-value"><?= htmlspecialchars((string) ($member['membership_status'] ?? '') !== '' ? (string) $member['membership_status'] : 'N/A') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-briefcase mr-2"></i>Employment Status:</div>
                        <div class="info-value"><?= htmlspecialchars((string) ($member['employment_status'] ?? '') !== '' ? (string) $member['employment_status'] : 'N/A') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-user-tie mr-2"></i>Profession:</div>
                        <div class="info-value"><?= htmlspecialchars((string) ($member['profession'] ?? '') !== '' ? (string) $member['profession'] : 'N/A') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-briefcase mr-2"></i>Occupation:</div>
                        <div class="info-value"><?= htmlspecialchars($member['occupation'] ?? 'N/A') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-building mr-2"></i>Employer:</div>
                        <div class="info-value"><?= htmlspecialchars($member['employer'] ?? 'N/A') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-exchange-alt mr-2"></i>Transferred In:</div>
                        <div class="info-value">
                            <span class="badge badge-<?= ((int) ($member['transfer_from_other_chapel'] ?? 0) === 1) ? 'warning' : 'secondary' ?>">
                                <?= ((int) ($member['transfer_from_other_chapel'] ?? 0) === 1) ? 'Yes' : 'No' ?>
                            </span>
                        </div>
                    </div>
                    <?php if ($has_transfer_details): ?>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-globe-africa mr-2"></i>Transfer Diocese:</div>
                            <div class="info-value"><?= htmlspecialchars((string) ($member['transfer_diocese'] ?? '') !== '' ? (string) $member['transfer_diocese'] : 'N/A') ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-route mr-2"></i>Transfer Circuit:</div>
                            <div class="info-value"><?= htmlspecialchars((string) ($member['transfer_circuit'] ?? '') !== '' ? (string) $member['transfer_circuit'] : 'N/A') ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-church mr-2"></i>Transfer Society:</div>
                            <div class="info-value"><?= htmlspecialchars((string) ($member['transfer_society'] ?? '') !== '' ? (string) $member['transfer_society'] : 'N/A') ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-file-signature mr-2"></i>Removal Note:</div>
                            <div class="info-value">
                                <span class="badge badge-<?= ((int) ($member['removal_note_provided'] ?? 0) === 1) ? 'success' : 'secondary' ?>">
                                    <?= ((int) ($member['removal_note_provided'] ?? 0) === 1) ? 'Provided' : 'Not Provided' ?>
                                </span>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-user-shield mr-2"></i>Superintendent:</div>
                            <div class="info-value"><?= htmlspecialchars((string) ($member['superintendent_name'] ?? '') !== '' ? (string) $member['superintendent_name'] : 'N/A') ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
                </section>
            </div>

        <!-- Emergency Contacts -->
            <div class="profile-secondary-column">
                <section class="profile-section" id="profile-emergency">
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
                                    <a href="tel:<?= htmlspecialchars($contact['mobile']) ?>">
                                                <i class="fas fa-phone mr-1"></i><?= htmlspecialchars($contact['mobile']) ?></a><br>
                                        <i class="fas fa-heart mr-1"></i><?= htmlspecialchars($contact['relationship']) ?>
                                    </small>
                                    <?php if (!empty($contact['linked_member_id'])): ?>
                                        <div class="mt-1">
                                            <a href="<?= BASE_URL ?>/views/member_view.php?id=<?= (int) $contact['linked_member_id'] ?>">
                                                <i class="fas fa-external-link-alt mr-1"></i>View Member
                                                <?php if (!empty($contact['linked_member_crn'])): ?>
                                                    (<?= htmlspecialchars($contact['linked_member_crn']) ?>)
                                                <?php endif; ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
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
        </section>

    <!-- Organizations -->
        <section class="profile-section" id="profile-organizations">
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
        </section>

        <!-- Recent SMS Notifications -->
        <section class="profile-section" id="profile-messages">
            <div class="info-card">
                <div class="info-card-header">
                    <i class="fas fa-sms mr-2"></i>Recent SMS Notifications
                </div>
                <div class="info-card-body">
                    <?php if ($sms_logs->num_rows > 0): ?>
                        <div class="sms-logs-container" style="max-height: 300px; overflow-y: auto;">
                            <?php while ($sms = $sms_logs->fetch_assoc()): ?>
                                <div class="sms-log-item">
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
        </section>
      </div>
    </div>

</div>

<?php if ($has_spouse_reference): ?>
<div class="modal fade" id="spousePhotoModal" tabindex="-1" role="dialog" aria-labelledby="spousePhotoModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="spousePhotoModalLabel">
          <?= htmlspecialchars($spouse_display_name !== '' ? $spouse_display_name : 'Spouse') ?>
          <?php if ($spouse_crn !== ''): ?>
            <small class="text-muted">(<?= htmlspecialchars($spouse_crn) ?>)</small>
          <?php endif; ?>
        </h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body text-center">
        <img src="<?= htmlspecialchars($spouse_photo_url) ?>" alt="Spouse image" class="img-fluid rounded shadow-sm" style="max-height:70vh;object-fit:contain;">
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

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
      span.text('GHS ' + parseFloat(res.total).toLocaleString(undefined, {minimumFractionDigits: 2}));
    }
  }, 'json').fail(function() {
    span.text('GHS 0.00');
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
