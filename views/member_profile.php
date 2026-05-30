<?php
require_once __DIR__ . '/../includes/member_auth.php';
require_once __DIR__ . '/../helpers/spouse_link_helper.php';

if (!isset($_SESSION['member_id']) && !isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

if (isset($_SESSION['role_id']) && (int) $_SESSION['role_id'] === 1) {
    header('Location: member_view.php?id=' . (int) ($_SESSION['member_id'] ?? 0));
    exit;
}

$member_id = (int) ($_SESSION['member_id'] ?? 0);
if ($member_id <= 0) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$format_member_date = static function (?string $dateValue, string $format = 'F j, Y'): string {
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
    $diff = $birthDate->diff($today);
    return sprintf(
        '%d year%s, %d month%s, %d day%s',
        $diff->y,
        $diff->y === 1 ? '' : 's',
        $diff->m,
        $diff->m === 1 ? '' : 's',
        $diff->d,
        $diff->d === 1 ? '' : 's'
    );
};

$display_value = static function (?string $value): string {
    $value = trim((string) $value);
    return $value === '' ? 'N/A' : htmlspecialchars($value);
};

$yes_no_badge = static function (?string $value): string {
    $normalized = strtolower(trim((string) $value));
    if ($normalized === 'yes') {
        return '<span class="badge badge-success">Yes</span>';
    }
    if ($normalized === 'no') {
        return '<span class="badge badge-secondary">No</span>';
    }
    return '<span class="badge badge-light">N/A</span>';
};

$relationship_feedback = '';
$relationship_feedback_type = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['spouse_request_action'] ?? ''));
    $request_id = (int) ($_POST['request_id'] ?? 0);
    if ($request_id > 0 && in_array($action, ['approve', 'reject'], true)) {
        $result = $action === 'approve'
            ? spouse_link_approve_request($conn, $request_id, $member_id)
            : spouse_link_reject_request($conn, $request_id, $member_id);
        $relationship_feedback = (string) ($result['message'] ?? '');
        $relationship_feedback_type = !empty($result['ok']) ? 'success' : 'danger';
    }
}

$member_stmt = $conn->prepare("
    SELECT m.*, bc.name AS class_name, ch.name AS church_name
    FROM members m
    LEFT JOIN bible_classes bc ON bc.id = m.class_id
    LEFT JOIN churches ch ON ch.id = m.church_id
    WHERE m.id = ?
    LIMIT 1
");
$member_stmt->bind_param('i', $member_id);
$member_stmt->execute();
$member = $member_stmt->get_result()->fetch_assoc();
$member_stmt->close();

if (!$member) {
    ob_start();
    ?>
    <div class="alert alert-danger">Member profile could not be loaded.</div>
    <?php
    $page_content = ob_get_clean();
    include '../includes/layout.php';
    exit;
}

$full_name = trim(implode(' ', array_filter([
    (string) ($member['first_name'] ?? ''),
    (string) ($member['middle_name'] ?? ''),
    (string) ($member['last_name'] ?? ''),
])));

$age_label = $format_member_age($member['dob'] ?? null);

$spouse_crn = trim((string) ($member['spouse_crn'] ?? ''));
$spouse_name = trim((string) ($member['spouse_name'] ?? ''));
$spouse_member_id = 0;
$spouse_full_name = $spouse_name;
$spouse_photo = '';
$spouse_photo_url = BASE_URL . '/assets/img/undraw_profile.svg';
if ($spouse_crn !== '') {
    $spouse_stmt = $conn->prepare('SELECT id, first_name, middle_name, last_name, photo FROM members WHERE crn = ? LIMIT 1');
    if ($spouse_stmt) {
        $spouse_stmt->bind_param('s', $spouse_crn);
        $spouse_stmt->execute();
        $spouse_row = $spouse_stmt->get_result()->fetch_assoc();
        $spouse_stmt->close();
        if ($spouse_row) {
            $spouse_member_id = (int) ($spouse_row['id'] ?? 0);
            $resolved_name = trim(implode(' ', array_filter([
                (string) ($spouse_row['first_name'] ?? ''),
                (string) ($spouse_row['middle_name'] ?? ''),
                (string) ($spouse_row['last_name'] ?? ''),
            ])));
            if ($resolved_name !== '') {
                $spouse_full_name = $resolved_name;
            }
            $spouse_photo = trim((string) ($spouse_row['photo'] ?? ''));
            if ($spouse_photo !== '' && file_exists(__DIR__ . '/../uploads/members/' . $spouse_photo)) {
                $spouse_photo_url = BASE_URL . '/uploads/members/' . rawurlencode($spouse_photo) . '?v=' . time();
            }
        }
    }
}

$orgs = [];
$org_stmt = $conn->prepare('
    SELECT o.name
    FROM organizations o
    INNER JOIN member_organizations mo ON mo.organization_id = o.id
    WHERE mo.member_id = ?
    ORDER BY o.name ASC
');
if ($org_stmt) {
    $org_stmt->bind_param('i', $member_id);
    $org_stmt->execute();
    $org_res = $org_stmt->get_result();
    while ($org_row = $org_res->fetch_assoc()) {
        $org_name = trim((string) ($org_row['name'] ?? ''));
        if ($org_name !== '') {
            $orgs[] = $org_name;
        }
    }
    $org_stmt->close();
}

$contacts = [];
$contact_stmt = $conn->prepare("
    SELECT mec.name, mec.mobile, mec.relationship, m.id AS linked_member_id, m.crn AS linked_member_crn
    FROM member_emergency_contacts mec
    LEFT JOIN members m ON m.phone = mec.mobile AND m.id <> ?
    WHERE mec.member_id = ?
    ORDER BY mec.id ASC
");
if ($contact_stmt) {
    $contact_stmt->bind_param('ii', $member_id, $member_id);
    $contact_stmt->execute();
    $contact_res = $contact_stmt->get_result();
    while ($row = $contact_res->fetch_assoc()) {
        $contacts[] = $row;
    }
    $contact_stmt->close();
}

$transfer_from_other_chapel = ((int) ($member['transfer_from_other_chapel'] ?? 0)) === 1;
$has_transfer_details = $transfer_from_other_chapel
    || trim((string) ($member['transfer_diocese'] ?? '')) !== ''
    || trim((string) ($member['transfer_circuit'] ?? '')) !== ''
    || trim((string) ($member['transfer_society'] ?? '')) !== ''
    || trim((string) ($member['superintendent_name'] ?? '')) !== '';

$is_confirmed = strtolower((string) ($member['confirmed'] ?? '')) === 'yes';
$is_baptized = strtolower((string) ($member['baptized'] ?? '')) === 'yes';
$computed_membership = ($is_confirmed && $is_baptized)
    ? 'Full Member'
    : (($is_confirmed || $is_baptized) ? 'Catechumen' : 'No Status');
$membership_status_label = trim((string) ($member['membership_status'] ?? '')) !== ''
    ? (string) $member['membership_status']
    : $computed_membership;

$incoming_spouse_requests = spouse_link_get_pending_incoming($conn, $member_id);
$outgoing_spouse_requests = spouse_link_get_pending_outgoing($conn, $member_id);
$has_spouse_reference = ($spouse_full_name !== '' || $spouse_crn !== '');

ob_start();
?>
<div class="card card-primary card-outline">
    <div class="card-body">
        <?php if ($relationship_feedback !== ''): ?>
            <div class="alert alert-<?= htmlspecialchars($relationship_feedback_type) ?> mb-3">
                <?= htmlspecialchars($relationship_feedback) ?>
            </div>
        <?php endif; ?>

        <div class="card mb-4 shadow-sm border-0" id="spouse-requests" style="background:#f8fbff;">
            <div class="card-header bg-warning text-dark font-weight-bold d-flex align-items-center">
                <i class="fas fa-user-check mr-2"></i>Spouse Relationship Requests
            </div>
            <div class="card-body">
                <?php if (empty($incoming_spouse_requests) && empty($outgoing_spouse_requests)): ?>
                    <div class="text-muted">No spouse requests at the moment.</div>
                <?php else: ?>
                    <?php if (!empty($incoming_spouse_requests)): ?>
                        <h6 class="font-weight-bold mb-2">Pending Your Approval</h6>
                        <?php foreach ($incoming_spouse_requests as $req): ?>
                            <div class="border rounded p-2 mb-2 bg-white">
                                <div>
                                    <strong><?= htmlspecialchars($req['requester_name'] ?: 'Member') ?></strong>
                                    <?php if (!empty($req['crn'])): ?>
                                        <span class="text-muted">(<?= htmlspecialchars($req['crn']) ?>)</span>
                                    <?php endif; ?>
                                    requested spouse link on
                                    <span class="text-muted"><?= htmlspecialchars(date('M j, Y g:i A', strtotime((string) $req['requested_at']))) ?></span>
                                </div>
                                <div class="mt-2 d-flex">
                                    <form method="post" class="mr-2">
                                        <input type="hidden" name="spouse_request_action" value="approve">
                                        <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                                        <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check mr-1"></i>Approve</button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="spouse_request_action" value="reject">
                                        <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fas fa-times mr-1"></i>Reject</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!empty($outgoing_spouse_requests)): ?>
                        <h6 class="font-weight-bold mb-2 mt-3">Awaiting Approval</h6>
                        <?php foreach ($outgoing_spouse_requests as $req): ?>
                            <div class="border rounded p-2 mb-2 bg-white">
                                <strong><?= htmlspecialchars($req['target_name'] ?: 'Member') ?></strong>
                                <?php if (!empty($req['crn'])): ?>
                                    <span class="text-muted">(<?= htmlspecialchars($req['crn']) ?>)</span>
                                <?php endif; ?>
                                <span class="text-muted"> - requested <?= htmlspecialchars(date('M j, Y g:i A', strtotime((string) $req['requested_at']))) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-4 text-center align-self-start mb-3 mb-md-0">
                <img class="profile-user-img img-fluid img-circle"
                     src="<?php echo !empty($member['photo']) && file_exists(__DIR__ . '/../uploads/members/' . $member['photo']) ? BASE_URL . '/uploads/members/' . rawurlencode($member['photo']) . '?v=' . time() : BASE_URL . '/assets/img/undraw_profile.svg'; ?>"
                     alt="Profile picture" style="width:120px;height:120px;object-fit:cover;">
                <h3 class="profile-username mt-2 mb-0"><?= htmlspecialchars($full_name !== '' ? $full_name : 'N/A') ?></h3>
                <p class="text-muted mb-2">CRN: <?= htmlspecialchars((string) ($member['crn'] ?? 'N/A')) ?></p>
                <?php if (trim((string) ($member['church_name'] ?? '')) !== ''): ?>
                    <div><span class="badge badge-light"><?= htmlspecialchars((string) $member['church_name']) ?></span></div>
                <?php endif; ?>
            </div>
            <div class="col-md-8">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <div class="border rounded p-3 h-100 bg-light">
                            <div class="small text-muted mb-1">Membership Status</div>
                            <div class="font-weight-bold"><?= htmlspecialchars($membership_status_label) ?></div>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="border rounded p-3 h-100 bg-light">
                            <div class="small text-muted mb-1">Bible Class</div>
                            <div class="font-weight-bold"><?= $display_value($member['class_name'] ?? null) ?></div>
                        </div>
                    </div>
                    <div class="col-md-12 mb-3">
                        <div class="border rounded p-3 h-100 bg-light">
                            <div class="small text-muted mb-2">Organizations</div>
                            <?php if (!empty($orgs)): ?>
                                <?php foreach ($orgs as $org): ?>
                                    <span class="badge badge-info mr-1 mb-1"><?= htmlspecialchars($org) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-muted">No organizations assigned</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($has_spouse_reference): ?>
                        <div class="col-md-12 mb-3">
                            <div class="border rounded p-3 h-100 bg-light">
                                <div class="small text-muted mb-2">Spouse</div>
                                <div class="d-flex align-items-center">
                                    <button type="button" class="btn p-0 border-0 bg-transparent mr-3" data-toggle="modal" data-target="#spouseImageModal" title="Expand spouse photo">
                                        <img src="<?= htmlspecialchars($spouse_photo_url) ?>" alt="Spouse photo"
                                             style="width:56px;height:56px;object-fit:cover;border-radius:50%;border:2px solid #dee2e6;">
                                    </button>
                                    <div>
                                        <div class="font-weight-bold"><?= $display_value($spouse_full_name !== '' ? $spouse_full_name : null) ?></div>
                                        <?php if ($spouse_crn !== ''): ?>
                                            <small class="text-muted d-block">CRN: <?= htmlspecialchars($spouse_crn) ?></small>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-link btn-sm p-0 mt-1" data-toggle="modal" data-target="#spouseImageModal">
                                            <i class="fas fa-search-plus mr-1"></i>Expand Photo
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-6">
                <div class="card mb-4 shadow rounded border-0 bg-white h-100">
                    <div class="card-header bg-primary text-white font-weight-bold"><i class="fas fa-user mr-2"></i>Personal & Family Information</div>
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-6 mb-2"><span class="font-weight-bold">First Name:</span><br><?= $display_value($member['first_name'] ?? null) ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Middle Name:</span><br><?= $display_value($member['middle_name'] ?? null) ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Last Name:</span><br><?= $display_value($member['last_name'] ?? null) ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Gender:</span><br><?= $display_value($member['gender'] ?? null) ?></div>
                            <div class="col-12 mb-2">
                                <span class="font-weight-bold">Date of Birth:</span><br>
                                <?= htmlspecialchars($format_member_date($member['dob'] ?? null)) ?>
                                <?php if ($age_label !== 'N/A'): ?>
                                    <small class="text-muted">(Age: <?= htmlspecialchars($age_label) ?>)</small>
                                <?php endif; ?>
                            </div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Day Born:</span><br><?= $display_value($member['day_born'] ?? null) ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Place of Birth:</span><br><?= $display_value($member['place_of_birth'] ?? null) ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Marital Status:</span><br><?= $display_value($member['marital_status'] ?? null) ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Marriage Type:</span><br><?= $display_value($member['marriage_type'] ?? null) ?></div>
                            <div class="col-12 mb-2">
                                <span class="font-weight-bold">Spouse:</span><br>
                                <?= $display_value($spouse_full_name !== '' ? $spouse_full_name : null) ?>
                                <?php if ($spouse_crn !== ''): ?>
                                    <small class="text-muted d-block">CRN: <?= htmlspecialchars($spouse_crn) ?></small>
                                <?php endif; ?>
                                <?php if ($has_spouse_reference): ?>
                                    <button type="button" class="btn btn-link btn-sm p-0 mt-1" data-toggle="modal" data-target="#spouseImageModal">
                                        <i class="fas fa-image mr-1"></i>View Spouse Photo
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Home Town:</span><br><?= $display_value($member['home_town'] ?? null) ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Region:</span><br><?= $display_value($member['region'] ?? null) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card mb-4 shadow rounded border-0 bg-white h-100">
                    <div class="card-header bg-success text-white font-weight-bold"><i class="fas fa-address-book mr-2"></i>Contact, Membership & Work</div>
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-6 mb-2"><span class="font-weight-bold">Phone:</span><br><?= $display_value($member['phone'] ?? null) ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Telephone:</span><br><?= $display_value($member['telephone'] ?? null) ?></div>
                            <div class="col-12 mb-2"><span class="font-weight-bold">Email:</span><br><?= $display_value($member['email'] ?? null) ?></div>
                            <div class="col-12 mb-2"><span class="font-weight-bold">Address:</span><br><?= $display_value($member['address'] ?? null) ?></div>
                            <div class="col-12 mb-2"><span class="font-weight-bold">GPS Address:</span><br><?= $display_value($member['gps_address'] ?? null) ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Date of Enrollment:</span><br><?= htmlspecialchars($format_member_date($member['date_of_enrollment'] ?? null)) ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Membership Status:</span><br><?= htmlspecialchars($membership_status_label) ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Baptized:</span><br><?= $yes_no_badge($member['baptized'] ?? null) ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Confirmed:</span><br><?= $yes_no_badge($member['confirmed'] ?? null) ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Date of Baptism:</span><br><?= htmlspecialchars($format_member_date($member['date_of_baptism'] ?? null)) ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Date of Confirmation:</span><br><?= htmlspecialchars($format_member_date($member['date_of_confirmation'] ?? null)) ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Employment Status:</span><br><?= $display_value($member['employment_status'] ?? null) ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Profession:</span><br><?= $display_value($member['profession'] ?? null) ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Occupation:</span><br><?= $display_value($member['occupation'] ?? null) ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Employer:</span><br><?= $display_value($member['employer'] ?? null) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($has_transfer_details): ?>
            <div class="card mb-4 shadow rounded border-0 bg-white">
                <div class="card-header bg-info text-white font-weight-bold"><i class="fas fa-random mr-2"></i>Transfer Details</div>
                <div class="card-body p-3">
                    <div class="row">
                        <div class="col-md-4 mb-2"><span class="font-weight-bold">Transfer From Other Chapel:</span><br><?= $transfer_from_other_chapel ? '<span class="badge badge-warning">Yes</span>' : '<span class="badge badge-secondary">No</span>' ?></div>
                        <div class="col-md-4 mb-2"><span class="font-weight-bold">Transfer Diocese:</span><br><?= $display_value($member['transfer_diocese'] ?? null) ?></div>
                        <div class="col-md-4 mb-2"><span class="font-weight-bold">Transfer Circuit:</span><br><?= $display_value($member['transfer_circuit'] ?? null) ?></div>
                        <div class="col-md-4 mb-2"><span class="font-weight-bold">Transfer Society:</span><br><?= $display_value($member['transfer_society'] ?? null) ?></div>
                        <div class="col-md-4 mb-2">
                            <span class="font-weight-bold">Removal Note Provided:</span><br>
                            <?php if (((int) ($member['removal_note_provided'] ?? 0)) === 1): ?>
                                <span class="badge badge-success">Provided</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Not Provided</span>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4 mb-2"><span class="font-weight-bold">Superintendent Name:</span><br><?= $display_value($member['superintendent_name'] ?? null) ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="card mb-4 shadow rounded border-0 bg-white">
            <div class="card-header bg-info text-white font-weight-bold"><i class="fas fa-user-shield mr-2"></i>Emergency Contacts</div>
            <div class="card-body">
                <div class="row">
                    <?php if (count($contacts) === 0): ?>
                        <div class="col-12 text-center py-4">
                            <span class="text-muted"><i class="fas fa-exclamation-circle fa-2x mb-2"></i><br>No emergency contacts recorded.</span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($contacts as $contact): ?>
                            <div class="col-md-6 col-lg-4 mb-3">
                                <div class="card h-100 border-info shadow-sm">
                                    <div class="card-body">
                                        <h5 class="card-title mb-2"><i class="fas fa-user-friends text-info mr-1"></i> <?= htmlspecialchars((string) ($contact['name'] ?? 'N/A')) ?></h5>
                                        <p class="mb-1"><span class="font-weight-bold">Mobile:</span> <?= htmlspecialchars((string) ($contact['mobile'] ?? 'N/A')) ?></p>
                                        <p class="mb-1"><span class="font-weight-bold">Relationship:</span> <?= htmlspecialchars((string) ($contact['relationship'] ?? 'N/A')) ?></p>
                                        <?php if (!empty($contact['linked_member_crn'])): ?>
                                            <div class="mt-2">
                                                <small class="text-muted">Linked member CRN: <?= htmlspecialchars((string) $contact['linked_member_crn']) ?></small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <a href="<?= BASE_URL; ?>/views/member_profile_edit.php" class="btn btn-primary btn-block"><b>Edit Profile</b></a>
    </div>
</div>

<?php if ($has_spouse_reference): ?>
    <div class="modal fade" id="spouseImageModal" tabindex="-1" role="dialog" aria-labelledby="spouseImageModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="spouseImageModalLabel">
                        <?= htmlspecialchars($spouse_full_name !== '' ? $spouse_full_name : 'Spouse') ?>
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
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
?>
