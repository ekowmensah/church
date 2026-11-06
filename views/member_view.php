<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/church_helper.php';
require_once __DIR__ . '/../helpers/role_of_serving_registration.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$isSuperAdmin = is_super_admin();
if (!$isSuperAdmin && !has_permission('view_member_profile')) {
    http_response_code(403);
    include __DIR__ . '/errors/403.php';
    exit;
}

$memberId = max(0, (int) ($_GET['id'] ?? 0));
if ($memberId < 1) {
    http_response_code(400);
    exit('Invalid member ID.');
}

$actorChurchId = $isSuperAdmin ? 0 : (int) get_user_church_id($conn);
$memberSql = "SELECT member.*, class.name AS class_name, church.name AS church_name,
        COUNT(DISTINCT CASE WHEN payment.reversal_approved_at IS NULL THEN payment.id END) AS total_payments,
        COALESCE(SUM(CASE WHEN payment.reversal_approved_at IS NULL THEN payment.amount ELSE 0 END), 0) AS total_amount_paid
    FROM members member
    LEFT JOIN bible_classes class ON class.id = member.class_id
    LEFT JOIN churches church ON church.id = member.church_id
    LEFT JOIN payments payment ON payment.member_id = member.id
    WHERE member.id = ?" . ($isSuperAdmin ? '' : ' AND member.church_id = ?') . "
    GROUP BY member.id, class.name, church.name
    LIMIT 1";
$memberStmt = $conn->prepare($memberSql);
if ($isSuperAdmin) {
    $memberStmt->bind_param('i', $memberId);
} else {
    $memberStmt->bind_param('ii', $memberId, $actorChurchId);
}
$memberStmt->execute();
$member = $memberStmt->get_result()->fetch_assoc();
$memberStmt->close();
if (!$member) {
    http_response_code(404);
    exit('Member not found within your authorized church.');
}

$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$display = static function ($value): string {
    $value = trim((string) $value);
    return $value === '' ? 'N/A' : $value;
};
$formatDate = static function ($value, string $format = 'M j, Y'): string {
    $value = trim((string) $value);
    if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') return 'N/A';
    $timestamp = strtotime($value);
    return $timestamp === false ? 'N/A' : date($format, $timestamp);
};
$formatAge = static function ($value): string {
    $value = trim((string) $value);
    if ($value === '' || $value === '0000-00-00') return 'N/A';
    $birth = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    $today = new DateTimeImmutable('today');
    if (!$birth || $birth->format('Y-m-d') !== $value || $birth > $today) return 'N/A';
    $age = $birth->diff($today);
    return $age->y . ' year' . ($age->y === 1 ? '' : 's');
};
$photoUrl = static function ($filename): ?string {
    $filename = basename(trim((string) $filename));
    if ($filename === '') return null;
    $path = __DIR__ . '/../uploads/members/' . $filename;
    if (!is_file($path)) return null;
    return BASE_URL . '/uploads/members/' . rawurlencode($filename) . '?v=' . filemtime($path);
};

$fullName = trim(implode(' ', array_filter([
    $member['first_name'] ?? '', $member['middle_name'] ?? '', $member['last_name'] ?? '',
])));
$fullName = $fullName !== '' ? $fullName : 'Unnamed member';
$memberPhotoUrl = $photoUrl($member['photo'] ?? null);
$age = $formatAge($member['dob'] ?? null);
$canEdit = $isSuperAdmin || has_permission('edit_member');
$healthDecision = $isSuperAdmin
    ? ['allowed' => true]
    : authorize_permission('view_health_records', ['church_id' => (int) $member['church_id']]);
$canViewHealth = !empty($healthDecision['allowed']);
$canViewStatement = $isSuperAdmin || has_permission('view_individual_payment_report');

// Resolve the spouse once, within the member's church. A typed spouse name is
// retained even when the linked CRN no longer resolves.
$spouseName = trim((string) ($member['spouse_name'] ?? ''));
$spouseCrn = trim((string) ($member['spouse_crn'] ?? ''));
$spouseMemberId = 0;
$spousePhotoUrl = null;
if ($spouseCrn !== '') {
    $spouseStmt = $conn->prepare("SELECT id, first_name, middle_name, last_name, photo
        FROM members WHERE crn = ? AND church_id = ? LIMIT 1");
    $memberChurchId = (int) $member['church_id'];
    $spouseStmt->bind_param('si', $spouseCrn, $memberChurchId);
    $spouseStmt->execute();
    $spouse = $spouseStmt->get_result()->fetch_assoc();
    $spouseStmt->close();
    if ($spouse) {
        $spouseMemberId = (int) $spouse['id'];
        if ($spouseName === '') {
            $spouseName = trim(implode(' ', array_filter([
                $spouse['first_name'] ?? '', $spouse['middle_name'] ?? '', $spouse['last_name'] ?? '',
            ])));
        }
        $spousePhotoUrl = $photoUrl($spouse['photo'] ?? null);
    }
}
$hasSpouse = $spouseName !== '' || $spouseCrn !== '';

$contactsStmt = $conn->prepare("SELECT contact.id, contact.name, contact.mobile,
        COALESCE(relationship.name, NULLIF(contact.relationship_other, ''), contact.relationship, 'Not specified') AS relationship_name,
        COALESCE(linked.id, linked_crn.id, linked_phone.id) AS linked_member_id,
        COALESCE(linked.crn, linked_crn.crn, linked_phone.crn) AS linked_member_crn,
        COALESCE(
            CONCAT_WS(' ', linked.first_name, linked.middle_name, linked.last_name),
            CONCAT_WS(' ', linked_crn.first_name, linked_crn.middle_name, linked_crn.last_name),
            CONCAT_WS(' ', linked_phone.first_name, linked_phone.middle_name, linked_phone.last_name)
        ) AS linked_member_name
    FROM member_emergency_contacts contact
    LEFT JOIN relationship_types relationship ON relationship.id = contact.relationship_type_id
    LEFT JOIN members linked ON linked.id = contact.contact_member_id
        AND linked.church_id = ? AND linked.id <> ?
    LEFT JOIN members linked_crn ON contact.contact_member_id IS NULL
        AND NULLIF(TRIM(contact.contact_crn), '') IS NOT NULL
        AND linked_crn.crn = contact.contact_crn
        AND linked_crn.church_id = ? AND linked_crn.id <> ?
    LEFT JOIN members linked_phone ON contact.contact_member_id IS NULL
        AND linked_crn.id IS NULL
        AND LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(contact.mobile, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')) >= 9
        AND linked_phone.church_id = ? AND linked_phone.id <> ?
        AND RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(linked_phone.phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), 9)
          = RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(contact.mobile, ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), 9)
        AND linked_phone.id = (
            SELECT MIN(candidate.id) FROM members candidate
            WHERE candidate.church_id = ? AND candidate.id <> ?
              AND RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(candidate.phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), 9)
                = RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(contact.mobile, ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), 9)
            HAVING COUNT(*) = 1
        )
    WHERE contact.member_id = ?
    ORDER BY contact.id");
$memberChurchId = (int) $member['church_id'];
$contactsStmt->bind_param(
    'iiiiiiiii',
    $memberChurchId, $memberId,
    $memberChurchId, $memberId,
    $memberChurchId, $memberId,
    $memberChurchId, $memberId,
    $memberId
);
$contactsStmt->execute();
$contacts = $contactsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$contactsStmt->close();

$organizationsStmt = $conn->prepare("SELECT organization.id, organization.name
    FROM member_organizations assignment
    JOIN organizations organization ON organization.id = assignment.organization_id
    WHERE assignment.member_id = ? AND organization.church_id = ?
    ORDER BY organization.name, organization.id");
$organizationsStmt->bind_param('ii', $memberId, $memberChurchId);
$organizationsStmt->execute();
$organizations = $organizationsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$organizationsStmt->close();

$servingRoles = role_of_serving_member_assignments($conn, $memberId);

$smsStmt = $conn->prepare("SELECT type, message, status, sent_at
    FROM sms_logs WHERE member_id = ? ORDER BY sent_at DESC, id DESC LIMIT 10");
$smsStmt->bind_param('i', $memberId);
$smsStmt->execute();
$smsLogs = $smsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$smsStmt->close();

$profileChecks = [
    $member['photo'] ?? '', $member['phone'] ?? '', $member['email'] ?? '',
    $member['address'] ?? '', $member['gps_address'] ?? '', $member['dob'] ?? '',
    $member['class_id'] ?? '', $member['membership_status'] ?? '',
];
$completedChecks = count(array_filter($profileChecks, static fn($value) => trim((string) $value) !== ''));
$profileCompleteness = (int) round(($completedChecks / max(1, count($profileChecks))) * 100);
$hasTransferDetails = (int) ($member['transfer_from_other_chapel'] ?? 0) === 1
    || trim((string) ($member['transfer_diocese'] ?? '')) !== ''
    || trim((string) ($member['transfer_circuit'] ?? '')) !== ''
    || trim((string) ($member['transfer_society'] ?? '')) !== ''
    || trim((string) ($member['superintendent_name'] ?? '')) !== '';

$membershipLabel = $display($member['membership_status'] ?? null);
$status = strtolower(trim((string) ($member['status'] ?? 'unknown')));
$statusClass = $status === 'active' ? 'success' : ($status === 'pending' ? 'warning' : 'muted');
$page_title = 'Member Profile - ' . $fullName;
$statementSearch = trim((string) ($member['crn'] ?? '')) ?: $fullName;
$statementUrl = BASE_URL . '/views/reports/details/individual_payment_report.php?'
    . http_build_query(['search' => $statementSearch, 'period' => 'overall']);

ob_start();
?>
<link rel="stylesheet" href="<?= $h(BASE_URL) ?>/assets/css/member-profile-workspace.css">
<main class="container-fluid member-profile-workspace py-3" id="member-profile-overview">
  <section class="profile-hero mb-4">
    <div class="profile-identity">
      <div class="profile-avatar-wrap">
        <?php if ($memberPhotoUrl): ?><img src="<?= $h($memberPhotoUrl) ?>" alt="<?= $h($fullName) ?>" class="profile-avatar">
        <?php else: ?><div class="profile-avatar profile-avatar-placeholder" aria-hidden="true"><i class="fas fa-user"></i></div><?php endif; ?>
        <span class="profile-status-dot is-<?= $h($statusClass) ?>" title="<?= $h(ucfirst($status)) ?>"></span>
      </div>
      <div class="profile-identity-copy">
        <span class="profile-eyebrow">Membership record</span>
        <h1><?= $h($fullName) ?></h1>
        <div class="profile-identity-line">
          <span><i class="fas fa-id-card"></i><?= $h($display($member['crn'] ?? null)) ?></span>
          <span><i class="fas fa-church"></i><?= $h($display($member['church_name'] ?? null)) ?></span>
          <span><i class="fas fa-users"></i><?= $h($display($member['class_name'] ?? null)) ?></span>
        </div>
        <div class="profile-badges">
          <span class="profile-badge is-<?= $h($statusClass) ?>"><i class="fas fa-circle"></i><?= $h(ucfirst($status)) ?></span>
          <span class="profile-badge is-membership"><i class="fas fa-certificate"></i><?= $h($membershipLabel) ?></span>
          <?php if ((int) ($member['is_archived'] ?? 0) === 1): ?><span class="profile-badge is-danger"><i class="fas fa-archive"></i>Archived</span><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="profile-hero-actions">
      <a href="<?= $h(BASE_URL) ?>/views/member_list.php" class="btn btn-outline-light"><i class="fas fa-arrow-left mr-2"></i>Member directory</a>
      <?php if ($canViewHealth): ?><button type="button" class="btn btn-outline-light" id="viewHealthBtn"><i class="fas fa-notes-medical mr-2"></i>Health records</button><?php endif; ?>
      <?php if ($canViewStatement): ?><a href="<?= $h($statementUrl) ?>" class="btn btn-outline-light"><i class="fas fa-file-invoice-dollar mr-2"></i>Payment statement</a><?php endif; ?>
      <?php if ($canEdit): ?><a href="<?= $h(BASE_URL) ?>/views/admin_member_edit.php?id=<?= $memberId ?>" class="btn btn-light"><i class="fas fa-edit mr-2"></i>Edit profile</a><?php endif; ?>
    </div>
  </section>

  <section class="profile-metrics mb-4" aria-label="Member profile summary">
    <div class="profile-metric"><span class="metric-icon is-green"><i class="fas fa-coins"></i></span><div><small>Total paid</small><strong>GH₵<?= number_format((float) $member['total_amount_paid'], 2) ?></strong><span><?= number_format((int) $member['total_payments']) ?> completed payment<?= (int) $member['total_payments'] === 1 ? '' : 's' ?></span></div></div>
    <div class="profile-metric"><span class="metric-icon is-blue"><i class="fas fa-calendar-alt"></i></span><div><small>Member since</small><strong><?= $h($formatDate($member['created_at'] ?? null, 'M Y')) ?></strong><span>Record #<?= $memberId ?></span></div></div>
    <div class="profile-metric"><span class="metric-icon is-purple"><i class="fas fa-birthday-cake"></i></span><div><small>Age</small><strong><?= $h($age) ?></strong><span>Born <?= $h($formatDate($member['dob'] ?? null)) ?></span></div></div>
    <div class="profile-metric"><span class="metric-icon is-amber"><i class="fas fa-clipboard-check"></i></span><div class="metric-progress-copy"><small>Profile completeness</small><strong><?= $profileCompleteness ?>%</strong><div class="profile-progress"><span style="width:<?= $profileCompleteness ?>%"></span></div></div></div>
  </section>

  <nav class="profile-section-nav mb-4" aria-label="Member profile sections">
    <a href="#member-profile-overview" class="active"><i class="fas fa-user-circle"></i>Overview</a>
    <a href="#profile-personal"><i class="fas fa-address-card"></i>Personal</a>
    <a href="#profile-contact"><i class="fas fa-phone-alt"></i>Contact</a>
    <a href="#profile-faith"><i class="fas fa-cross"></i>Faith &amp; work</a>
    <a href="#profile-emergency"><i class="fas fa-user-shield"></i>Emergency</a>
    <a href="#profile-organizations"><i class="fas fa-sitemap"></i>Groups</a>
    <a href="#profile-messages"><i class="fas fa-sms"></i>Messages</a>
  </nav>

  <div class="profile-layout">
    <div class="profile-main-column">
      <section class="profile-card" id="profile-personal">
        <header><div><span>Identity</span><h2>Personal information</h2></div><i class="fas fa-address-card"></i></header>
        <div class="profile-detail-grid">
          <div class="profile-detail"><small>Gender</small><strong><?= $h($display($member['gender'] ?? null)) ?></strong></div>
          <div class="profile-detail"><small>Date of birth</small><strong><?= $h($formatDate($member['dob'] ?? null)) ?></strong><span><?= $h($age) ?></span></div>
          <div class="profile-detail"><small>Day born</small><strong><?= $h($display($member['day_born'] ?? null)) ?></strong></div>
          <div class="profile-detail"><small>Place of birth</small><strong><?= $h($display($member['place_of_birth'] ?? null)) ?></strong></div>
          <div class="profile-detail"><small>Marital status</small><strong><?= $h($display($member['marital_status'] ?? null)) ?></strong></div>
          <div class="profile-detail"><small>Nature of marriage</small><strong><?= $h($display($member['marriage_type'] ?? null)) ?></strong></div>
          <div class="profile-detail profile-detail-wide"><small>Spouse</small>
            <?php if ($hasSpouse): ?><div class="spouse-summary">
              <button type="button" class="spouse-photo-button" data-toggle="modal" data-target="#spousePhotoModal" aria-label="Open spouse photo"><span class="spouse-thumb"><?php if ($spousePhotoUrl): ?><img src="<?= $h($spousePhotoUrl) ?>" alt="<?= $h($spouseName ?: 'Spouse') ?>"><?php else: ?><i class="fas fa-user"></i><?php endif; ?></span></button>
              <div><strong><?= $h($display($spouseName)) ?></strong><?php if ($spouseCrn !== ''): ?><span>CRN <?= $h($spouseCrn) ?></span><?php endif; ?><div class="spouse-links"><button type="button" data-toggle="modal" data-target="#spousePhotoModal">View photo</button><?php if ($spouseMemberId > 0): ?><a href="<?= $h(BASE_URL) ?>/views/member_view.php?id=<?= $spouseMemberId ?>">Open profile <i class="fas fa-arrow-right"></i></a><?php endif; ?></div></div>
            </div><?php else: ?><strong>N/A</strong><?php endif; ?>
          </div>
        </div>
      </section>

      <section class="profile-card" id="profile-contact">
        <header><div><span>Reachability</span><h2>Contact and residence</h2></div><i class="fas fa-map-marked-alt"></i></header>
        <div class="profile-detail-grid">
          <div class="profile-detail"><small>Mobile phone</small><strong><?php if (!empty($member['phone'])): ?><a href="tel:<?= $h($member['phone']) ?>"><?= $h($member['phone']) ?></a><?php else: ?>N/A<?php endif; ?></strong></div>
          <div class="profile-detail"><small>Telephone</small><strong><?php if (!empty($member['telephone'])): ?><a href="tel:<?= $h($member['telephone']) ?>"><?= $h($member['telephone']) ?></a><?php else: ?>N/A<?php endif; ?></strong></div>
          <div class="profile-detail"><small>Email address</small><strong><?php if (!empty($member['email'])): ?><a href="mailto:<?= $h($member['email']) ?>"><?= $h($member['email']) ?></a><?php else: ?>N/A<?php endif; ?></strong></div>
          <div class="profile-detail"><small>GPS address</small><strong><?= $h($display($member['gps_address'] ?? null)) ?></strong></div>
          <div class="profile-detail profile-detail-wide"><small>Residential address</small><strong><?= $h($display($member['address'] ?? null)) ?></strong></div>
          <div class="profile-detail"><small>Home town</small><strong><?= $h($display($member['home_town'] ?? null)) ?></strong></div>
          <div class="profile-detail"><small>Region</small><strong><?= $h($display($member['region'] ?? null)) ?></strong></div>
        </div>
      </section>

      <section class="profile-card" id="profile-faith">
        <header><div><span>Formation and livelihood</span><h2>Faith and professional profile</h2></div><i class="fas fa-cross"></i></header>
        <div class="profile-detail-grid">
          <div class="profile-detail"><small>Date of enrolment</small><strong><?= $h($formatDate($member['date_of_enrollment'] ?? null)) ?></strong></div>
          <div class="profile-detail"><small>Membership status</small><strong><?= $h($membershipLabel) ?></strong></div>
          <div class="profile-detail"><small>Baptized</small><strong><?= $h(ucfirst($display($member['baptized'] ?? 'No'))) ?></strong><span><?= $h($formatDate($member['date_of_baptism'] ?? null)) ?></span></div>
          <div class="profile-detail"><small>Confirmed</small><strong><?= $h(ucfirst($display($member['confirmed'] ?? 'No'))) ?></strong><span><?= $h($formatDate($member['date_of_confirmation'] ?? null)) ?></span></div>
          <div class="profile-detail"><small>Employment status</small><strong><?= $h($display($member['employment_status'] ?? null)) ?></strong></div>
          <div class="profile-detail"><small>Profession</small><strong><?= $h($display($member['profession'] ?? null)) ?></strong></div>
          <div class="profile-detail"><small>Occupation</small><strong><?= $h($display($member['occupation'] ?? null)) ?></strong></div>
          <div class="profile-detail"><small>Employer</small><strong><?= $h($display($member['employer'] ?? null)) ?></strong></div>
        </div>
      </section>

      <?php if ($hasTransferDetails): ?><section class="profile-card" id="profile-transfer">
        <header><div><span>Membership movement</span><h2>Transfer-in details</h2></div><i class="fas fa-exchange-alt"></i></header>
        <div class="profile-detail-grid">
          <div class="profile-detail"><small>Transferred from another chapel</small><strong><?= (int) ($member['transfer_from_other_chapel'] ?? 0) === 1 ? 'Yes' : 'No' ?></strong></div>
          <div class="profile-detail"><small>Removal note</small><strong><?= (int) ($member['removal_note_provided'] ?? 0) === 1 ? 'Provided' : 'Not provided' ?></strong></div>
          <div class="profile-detail"><small>Diocese</small><strong><?= $h($display($member['transfer_diocese'] ?? null)) ?></strong></div>
          <div class="profile-detail"><small>Circuit</small><strong><?= $h($display($member['transfer_circuit'] ?? null)) ?></strong></div>
          <div class="profile-detail"><small>Society</small><strong><?= $h($display($member['transfer_society'] ?? null)) ?></strong></div>
          <div class="profile-detail"><small>Superintendent</small><strong><?= $h($display($member['superintendent_name'] ?? null)) ?></strong></div>
        </div>
      </section><?php endif; ?>
    </div>

    <aside class="profile-side-column">
      <section class="profile-card profile-placement-card">
        <header><div><span>Church placement</span><h2>Membership context</h2></div><i class="fas fa-church"></i></header>
        <div class="placement-list">
          <div><span><i class="fas fa-church"></i>Church</span><strong><?= $h($display($member['church_name'] ?? null)) ?></strong></div>
          <div><span><i class="fas fa-users"></i>Bible Class</span><strong><?= $h($display($member['class_name'] ?? null)) ?></strong></div>
          <div><span><i class="fas fa-user-check"></i>Record status</span><strong><?= $h(ucfirst($status)) ?></strong></div>
          <div><span><i class="fas fa-calendar-check"></i>Registered</span><strong><?= $h($formatDate($member['created_at'] ?? null)) ?></strong></div>
        </div>
        <div class="serving-role-block"><small>Roles of Serving</small><div class="profile-chip-list"><?php if ($servingRoles): ?><?php foreach ($servingRoles as $role): ?><span><?= $h($role['name']) ?></span><?php endforeach; ?><?php else: ?><em>No current role assigned</em><?php endif; ?></div></div>
      </section>

      <section class="profile-card" id="profile-emergency">
        <header><div><span>Safety contacts</span><h2>Emergency contacts</h2></div><i class="fas fa-user-shield"></i></header>
        <div class="contact-list">
          <?php if ($contacts): ?><?php foreach ($contacts as $index => $contact): ?><article class="contact-card">
            <span class="contact-number"><?= $index + 1 ?></span><div><strong><?= $h($display($contact['name'] ?? null)) ?></strong><small><?= $h($display($contact['relationship_name'] ?? null)) ?></small><a href="tel:<?= $h($contact['mobile'] ?? '') ?>"><i class="fas fa-phone-alt"></i><?= $h($display($contact['mobile'] ?? null)) ?></a><?php if (!empty($contact['linked_member_id'])): ?><a href="<?= $h(BASE_URL) ?>/views/member_view.php?id=<?= (int) $contact['linked_member_id'] ?>"><i class="fas fa-external-link-alt"></i>View linked member<?php if (!empty($contact['linked_member_crn'])): ?> (<?= $h($contact['linked_member_crn']) ?>)<?php endif; ?></a><?php endif; ?></div>
          </article><?php endforeach; ?><?php else: ?><div class="profile-empty"><i class="fas fa-user-slash"></i><strong>No emergency contacts</strong><span>Add contacts through Edit profile.</span></div><?php endif; ?>
        </div>
      </section>

      <section class="profile-card" id="profile-organizations">
        <header><div><span>Participation</span><h2>Organizations</h2></div><i class="fas fa-sitemap"></i></header>
        <div class="organization-list"><?php if ($organizations): ?><?php foreach ($organizations as $organization): ?><span><i class="fas fa-users"></i><?= $h($organization['name']) ?></span><?php endforeach; ?><?php else: ?><div class="profile-empty"><i class="fas fa-users-slash"></i><strong>No organizations assigned</strong></div><?php endif; ?></div>
      </section>

      <section class="profile-card" id="profile-messages">
        <header><div><span>Communication trail</span><h2>Recent SMS</h2></div><i class="fas fa-sms"></i></header>
        <div class="message-timeline">
          <?php if ($smsLogs): ?><?php foreach ($smsLogs as $sms):
            $smsStatus = strtolower((string) ($sms['status'] ?? ''));
            $smsState = str_contains($smsStatus, 'fail') ? 'danger' : ((str_contains($smsStatus, 'sent') || str_contains($smsStatus, 'success')) ? 'success' : 'muted');
          ?><article><span class="message-dot is-<?= $smsState ?>"></span><div><div class="message-meta"><strong><?= $h(ucwords(str_replace('_', ' ', (string) ($sms['type'] ?? 'Message')))) ?></strong><time><?= $h($formatDate($sms['sent_at'] ?? null, 'M j, Y H:i')) ?></time></div><p><?= $h(mb_strimwidth((string) ($sms['message'] ?? ''), 0, 145, '…')) ?></p><span class="message-status is-<?= $smsState ?>"><?= $h($display($sms['status'] ?? null)) ?></span></div></article><?php endforeach; ?>
          <?php else: ?><div class="profile-empty"><i class="fas fa-comment-slash"></i><strong>No SMS history</strong></div><?php endif; ?>
        </div>
      </section>
    </aside>
  </div>
</main>

<script>
window.memberProfileConfig = {
  memberId: <?= $memberId ?>,
  healthUrl: <?= json_encode(BASE_URL . '/views/ajax_get_health_records.php') ?>
};
$(document).on('click', '#viewHealthBtn', function () {
  var config = window.memberProfileConfig;
  $('#healthRecordsBody').html('<div class="profile-modal-loading"><span class="spinner-border text-primary" role="status"></span><strong>Loading health records…</strong></div>');
  $('#healthModal').modal('show');
  $.getJSON(config.healthUrl, {member_id: config.memberId}).done(function (response) {
    if (!response || response.success !== true) {
      var responseMessage = (response && response.error) || 'Please try again.';
      $('#healthRecordsBody').html('<div class="profile-empty py-5"><i class="fas fa-exclamation-triangle"></i><strong>Health records could not be loaded</strong><span>' + $('<div>').text(responseMessage).html() + '</span></div>');
      return;
    }
    var $content = $('<div>').html(response.data || '');
    $content.find('table').each(function () {
      if (!$(this).parent().hasClass('table-responsive')) $(this).wrap('<div class="table-responsive"></div>');
    });
    $('#healthRecordsBody').empty().append($content.contents());
  }).fail(function (xhr) {
    var message = xhr.responseJSON && xhr.responseJSON.error ? xhr.responseJSON.error : 'Please try again.';
    $('#healthRecordsBody').html('<div class="profile-empty py-5"><i class="fas fa-exclamation-triangle"></i><strong>Health records could not be loaded</strong><span>' + $('<div>').text(message).html() + '</span></div>');
  });
});
</script>
<?php
$page_content = ob_get_clean();

ob_start();
if ($hasSpouse): ?>
<div class="modal fade" id="spousePhotoModal" tabindex="-1" role="dialog" aria-labelledby="spousePhotoModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg" role="document"><div class="modal-content profile-modal"><div class="modal-header"><div><span class="profile-modal-eyebrow">Spouse record</span><h5 class="modal-title" id="spousePhotoModalLabel"><?= $h($display($spouseName)) ?></h5><?php if ($spouseCrn !== ''): ?><small>CRN <?= $h($spouseCrn) ?></small><?php endif; ?></div><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div><div class="modal-body text-center"><?php if ($spousePhotoUrl): ?><img src="<?= $h($spousePhotoUrl) ?>" alt="<?= $h($spouseName ?: 'Spouse') ?>" class="profile-modal-photo"><?php else: ?><div class="profile-empty py-5"><i class="fas fa-user"></i><strong>No spouse photo available</strong></div><?php endif; ?></div><?php if ($spouseMemberId > 0): ?><div class="modal-footer"><a class="btn btn-primary" href="<?= $h(BASE_URL) ?>/views/member_view.php?id=<?= $spouseMemberId ?>">Open member profile</a></div><?php endif; ?></div></div>
</div>
<?php endif; ?>
<?php if ($canViewHealth): ?>
<div class="modal fade" id="healthModal" tabindex="-1" role="dialog" aria-labelledby="healthModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" role="document"><div class="modal-content profile-modal"><div class="modal-header"><div><span class="profile-modal-eyebrow">Clinical history</span><h5 class="modal-title" id="healthModalLabel"><?= $h($fullName) ?> &mdash; Health Records</h5><small>Read-only member health history</small></div><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div><div class="modal-body" id="healthRecordsBody"><div class="profile-modal-loading"><span class="spinner-border text-primary" role="status"></span><strong>Loading health records…</strong></div></div></div></div>
</div>
<?php endif;
$modal_html = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
