<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/leader_helpers.php';

function build_org_leader_redirect_url($org_id, $start_date = null, $end_date = null, $member_search = '') {
    $params = [];

    if ($org_id) {
        $params['org_id'] = (int) $org_id;
    }
    if ($start_date) {
        $params['start_date'] = $start_date;
    }
    if ($end_date) {
        $params['end_date'] = $end_date;
    }
    if ($member_search !== '') {
        $params['member_search'] = $member_search;
    }

    $query = http_build_query($params);
    return 'my_organization_leader.php' . ($query ? '?' . $query : '');
}

function normalize_dashboard_date($value, $fallback) {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $fallback;
    }

    $parts = explode('-', $value);
    if (count($parts) !== 3) {
        return $fallback;
    }

    if (!checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
        return $fallback;
    }

    return $value;
}

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
$member_id = $_SESSION['member_id'] ?? null;
$session_user_id = (int) ($_SESSION['user_id'] ?? 0);
$org_leaderships = is_organization_leader($conn, $user_id, $member_id);

if (!$org_leaderships) {
    http_response_code(403);
    echo '<div class="alert alert-danger">You are not assigned as an organization leader.</div>';
    exit;
}

$organizations = [];
foreach ($org_leaderships as $org) {
    $organizations[] = [
        'organization_id' => (int) $org['organization_id'],
        'org_name' => $org['org_name'],
        'description' => $org['description'],
        'church_id' => (int) ($org['church_id'] ?? 0),
    ];
}

usort($organizations, static function ($left, $right) {
    return strcasecmp((string) $left['org_name'], (string) $right['org_name']);
});

$org_id = isset($_REQUEST['org_id']) ? (int) $_REQUEST['org_id'] : 0;
if ($org_id <= 0) {
    $org_id = (int) $organizations[0]['organization_id'];
}

$leader_info = null;
foreach ($organizations as $org) {
    if ((int) $org['organization_id'] === $org_id) {
        $leader_info = $org;
        break;
    }
}

if (!$leader_info) {
    $leader_info = $organizations[0];
    $org_id = (int) $leader_info['organization_id'];
}

$org_name = $leader_info['org_name'];
$org_description = (string) ($leader_info['description'] ?? '');
$church_id = (int) ($leader_info['church_id'] ?? 0);

$default_start_date = date('Y-m-01');
$default_end_date = date('Y-m-t');
$start_date = normalize_dashboard_date($_GET['start_date'] ?? $default_start_date, $default_start_date);
$end_date = normalize_dashboard_date($_GET['end_date'] ?? $default_end_date, $default_end_date);
if ($start_date > $end_date) {
    $temp = $start_date;
    $start_date = $end_date;
    $end_date = $temp;
}

$member_search = trim((string) ($_GET['member_search'] ?? ''));
$member_search_results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = trim((string) ($_POST['action'] ?? ''));
    $target_org_id = (int) ($_POST['organization_id'] ?? 0);
    $target_member_id = (int) ($_POST['member_id'] ?? 0);

    if ($target_org_id !== $org_id) {
        $error = 'You cannot manage memberships for another organization from this page.';
    } elseif ($action === 'add_member') {
        if ($target_member_id <= 0) {
            $error = 'Select a valid member to add.';
        } else {
            $member_sql = "
                SELECT m.id, m.first_name, m.last_name
                FROM members m
                WHERE m.id = ? AND m.status = 'active'
            ";
            if ($church_id > 0) {
                $member_sql .= " AND m.church_id = ?";
            }
            $member_sql .= " LIMIT 1";

            $member_stmt = $conn->prepare($member_sql);
            if ($church_id > 0) {
                $member_stmt->bind_param('ii', $target_member_id, $church_id);
            } else {
                $member_stmt->bind_param('i', $target_member_id);
            }
            $member_stmt->execute();
            $member_result = $member_stmt->get_result();

            if ($member_result->num_rows === 0) {
                $error = 'The selected member could not be found in this church.';
            } else {
                $member_data = $member_result->fetch_assoc();

                $exists_stmt = $conn->prepare('SELECT 1 FROM member_organizations WHERE member_id = ? AND organization_id = ? LIMIT 1');
                $exists_stmt->bind_param('ii', $target_member_id, $org_id);
                $exists_stmt->execute();
                $already_member = $exists_stmt->get_result()->num_rows > 0;
                $exists_stmt->close();

                if ($already_member) {
                    $error = 'That member already belongs to this organization.';
                } else {
                    $conn->begin_transaction();
                    try {
                        add_member_to_organization($conn, $target_member_id, $org_id);

                        $notes = 'Added directly by organization leader from My Organization Leadership.';
                        $approval_stmt = $conn->prepare("
                            UPDATE organization_membership_approvals
                            SET status = 'approved', approved_by = ?, approved_at = NOW(), notes = ?
                            WHERE member_id = ? AND organization_id = ? AND status = 'pending'
                        ");
                        $approval_stmt->bind_param('isii', $session_user_id, $notes, $target_member_id, $org_id);
                        if (!$approval_stmt->execute()) {
                            throw new Exception($approval_stmt->error ?: 'Failed to sync pending approval records.');
                        }
                        $approval_stmt->close();

                        $conn->commit();
                        $_SESSION['org_member_success'] = 'Added ' . $member_data['first_name'] . ' ' . $member_data['last_name'] . ' to ' . $org_name . '.';
                        header('Location: ' . build_org_leader_redirect_url($org_id, $start_date, $end_date));
                        exit;
                    } catch (Exception $e) {
                        $conn->rollback();
                        $error = 'Error adding member: ' . $e->getMessage();
                    }
                }
            }

            $member_stmt->close();
        }
    } elseif ($action === 'remove_member') {
        if ($target_member_id <= 0) {
            $error = 'Invalid member selected for removal.';
        } else {
            $member_stmt = $conn->prepare("
                SELECT m.id, m.first_name, m.last_name
                FROM members m
                INNER JOIN member_organizations mo ON mo.member_id = m.id
                WHERE m.id = ? AND mo.organization_id = ?
                LIMIT 1
            ");
            $member_stmt->bind_param('ii', $target_member_id, $org_id);
            $member_stmt->execute();
            $member_result = $member_stmt->get_result();

            if ($member_result->num_rows === 0) {
                $error = 'That membership record could not be found.';
            } else {
                $member_data = $member_result->fetch_assoc();

                if (is_active_organization_leader_member($conn, $org_id, $target_member_id)) {
                    $error = 'You cannot remove the active leader from this organization. Reassign leadership first.';
                } else {
                    $delete_stmt = $conn->prepare('DELETE FROM member_organizations WHERE member_id = ? AND organization_id = ?');
                    $delete_stmt->bind_param('ii', $target_member_id, $org_id);
                    if (!$delete_stmt->execute()) {
                        $error = 'Failed to remove member: ' . $delete_stmt->error;
                    } else {
                        $_SESSION['org_member_success'] = 'Removed ' . $member_data['first_name'] . ' ' . $member_data['last_name'] . ' from ' . $org_name . '.';
                        header('Location: ' . build_org_leader_redirect_url($org_id, $start_date, $end_date, $member_search));
                        exit;
                    }
                    $delete_stmt->close();
                }
            }

            $member_stmt->close();
        }
    }
}

$members = get_organization_members($conn, $org_id);
$total_members = count($members);
$payment_stats = get_organization_payment_stats($conn, $org_id, $start_date, $end_date);
$attendance_stats = get_organization_attendance_stats($conn, $org_id, $start_date, $end_date);
$pending_count = get_organization_pending_membership_count($conn, $org_id);
$active_leader_member_ids = get_active_organization_leader_member_ids($conn, $org_id);
$recent_payments = get_organization_recent_payments($conn, $org_id, $start_date, $end_date, 10);
$upcoming_sessions = get_upcoming_organization_sessions($conn, $church_id, 5);

if (strlen($member_search) >= 2) {
    $member_search_results = search_organization_member_candidates($conn, $org_id, $member_search, $church_id, 25);
}

$unique_payers = (int) ($payment_stats['unique_payers'] ?? 0);
$total_amount = (float) ($payment_stats['total_amount'] ?? 0);
$attendance_rate = (float) ($attendance_stats['attendance_rate'] ?? 0);
$total_present = (int) ($attendance_stats['total_present'] ?? 0);
$total_sessions = (int) ($attendance_stats['total_sessions'] ?? 0);

if (isset($_SESSION['org_member_success'])) {
    $success = $_SESSION['org_member_success'];
    unset($_SESSION['org_member_success']);
}

ob_start();
?>
<style>
:root {
    --org-hero-start: #0b3c5d;
    --org-hero-end: #168aad;
    --org-accent: #f4a261;
    --org-accent-soft: #fff4e8;
    --org-border: #d7e3ea;
    --org-surface: #ffffff;
    --org-muted: #6c7a89;
    --org-ink: #16324f;
}

.org-leader-shell {
    color: var(--org-ink);
}

.org-leader-hero {
    background:
        radial-gradient(circle at top right, rgba(244, 162, 97, 0.32), transparent 28%),
        linear-gradient(135deg, var(--org-hero-start), var(--org-hero-end));
    color: #fff;
    border-radius: 24px;
    padding: 28px 30px;
    margin-bottom: 24px;
    box-shadow: 0 18px 38px rgba(15, 45, 69, 0.18);
}

.org-leader-hero h2,
.org-leader-hero h4,
.org-leader-hero p,
.org-leader-hero label {
    color: inherit;
}

.org-hero-kicker {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.16);
    border-radius: 999px;
    padding: 6px 12px;
    font-size: 0.85rem;
    letter-spacing: 0.03em;
    margin-bottom: 14px;
}

.org-hero-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.org-hero-actions .btn {
    border-radius: 999px;
    padding-left: 16px;
    padding-right: 16px;
}

.org-switcher-card,
.org-panel {
    background: var(--org-surface);
    border: 1px solid var(--org-border);
    border-radius: 20px;
    box-shadow: 0 10px 24px rgba(16, 36, 54, 0.06);
}

.org-switcher-card {
    padding: 18px 20px;
    margin-bottom: 20px;
}

.org-panel {
    padding: 22px;
    margin-bottom: 22px;
}

.org-panel-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 18px;
}

.org-panel-title h5 {
    margin-bottom: 0;
}

.org-stat-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 22px;
}

.org-stat-card {
    background: linear-gradient(180deg, #ffffff 0%, #f9fcfe 100%);
    border: 1px solid var(--org-border);
    border-radius: 18px;
    padding: 18px 18px 16px;
    min-height: 145px;
}

.org-stat-label {
    color: var(--org-muted);
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}

.org-stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--org-ink);
    margin: 10px 0 8px;
    line-height: 1.1;
}

.org-stat-meta {
    color: var(--org-muted);
    font-size: 0.92rem;
}

.org-filter-grid,
.org-member-search-grid {
    display: grid;
    grid-template-columns: repeat(12, minmax(0, 1fr));
    gap: 12px;
}

.org-filter-grid > div,
.org-member-search-grid > div {
    min-width: 0;
}

.org-filter-start,
.org-filter-end {
    grid-column: span 4;
}

.org-filter-actions {
    grid-column: span 4;
    display: flex;
    gap: 10px;
    align-items: flex-end;
}

.org-search-input {
    grid-column: span 9;
}

.org-search-actions {
    grid-column: span 3;
    display: flex;
    gap: 10px;
    align-items: flex-end;
}

.org-quick-links {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.org-quick-links .btn,
.org-badge-link {
    border-radius: 999px;
}

.org-badge-link .badge {
    vertical-align: middle;
}

.org-member-table td,
.org-member-table th,
.org-payments-table td,
.org-payments-table th,
.org-search-table td,
.org-search-table th {
    vertical-align: middle;
}

.org-member-table .member-name,
.org-search-table .member-name {
    font-weight: 600;
}

.org-leader-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    background: #e9f6fb;
    color: #0a6d8d;
    font-size: 0.8rem;
    font-weight: 600;
}

.org-session-list {
    display: grid;
    gap: 12px;
}

.org-session-card {
    border: 1px solid var(--org-border);
    border-radius: 16px;
    padding: 16px 18px;
    background: linear-gradient(180deg, #ffffff 0%, #f9fbfc 100%);
}

.org-session-date {
    color: var(--org-muted);
    font-size: 0.92rem;
}

.org-empty {
    border: 1px dashed var(--org-border);
    border-radius: 16px;
    padding: 26px 20px;
    text-align: center;
    color: var(--org-muted);
    background: #fbfdfe;
}

.org-scroll {
    max-height: 480px;
    overflow-y: auto;
}

@media (max-width: 1199.98px) {
    .org-stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 991.98px) {
    .org-filter-start,
    .org-filter-end,
    .org-filter-actions,
    .org-search-input,
    .org-search-actions {
        grid-column: span 12;
    }

    .org-hero-actions {
        justify-content: flex-start;
        margin-top: 18px;
    }
}

@media (max-width: 767.98px) {
    .org-leader-hero {
        padding: 24px 20px;
    }

    .org-stat-grid {
        grid-template-columns: 1fr;
    }

    .org-filter-actions,
    .org-search-actions {
        flex-direction: column;
        align-items: stretch;
    }
}
</style>

<div class="org-leader-shell">
    <div class="org-leader-hero">
        <div class="row align-items-center">
            <div class="col-lg-7">
                <div class="org-hero-kicker">
                    <i class="fas fa-user-tie"></i>
                    <span>Organization Leader Workspace</span>
                </div>
                <h2 class="mb-2">My Organization Leadership</h2>
                <h4 class="mb-2"><?= htmlspecialchars($org_name) ?></h4>
                <p class="mb-0">
                    <?= $org_description !== '' ? htmlspecialchars($org_description) : 'Manage membership, track activity, and stay on top of attendance and giving for your organization.' ?>
                </p>
            </div>
            <div class="col-lg-5">
                <div class="org-hero-actions">
                    <a href="my_organization_attendance.php?org_id=<?= $org_id ?>" class="btn btn-light">
                        <i class="fas fa-clipboard-check mr-1"></i> Mark Attendance
                    </a>
                    <a href="organization_membership_approvals.php?org_id=<?= $org_id ?>" class="btn btn-outline-light org-badge-link">
                        <i class="fas fa-user-check mr-1"></i> Approvals
                        <?php if ($pending_count > 0): ?>
                            <span class="badge badge-warning ml-1"><?= $pending_count ?></span>
                        <?php endif; ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if (count($organizations) > 1): ?>
        <div class="org-switcher-card">
            <form method="get" class="row align-items-end">
                <div class="col-lg-6">
                    <label for="org_id" class="font-weight-bold mb-2">Switch Organization</label>
                    <select name="org_id" id="org_id" class="form-control" onchange="this.form.submit()">
                        <?php foreach ($organizations as $org): ?>
                            <option value="<?= (int) $org['organization_id'] ?>" <?= (int) $org['organization_id'] === $org_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($org['org_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
                <?php if ($member_search !== ''): ?>
                    <input type="hidden" name="member_search" value="<?= htmlspecialchars($member_search) ?>">
                <?php endif; ?>
                <div class="col-lg-6">
                    <p class="text-muted mb-0">You lead <?= count($organizations) ?> organizations. Switching keeps your filter range and search context intact.</p>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <?php if (isset($success)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($success) ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
    <?php endif; ?>

    <div class="org-panel">
        <div class="org-panel-title">
            <h5><i class="fas fa-sliders-h text-primary mr-2"></i>Reporting Window</h5>
            <div class="org-quick-links">
                <a href="leader_export_report.php?type=members&group_type=org&format=csv&org_id=<?= $org_id ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-download mr-1"></i> Members CSV
                </a>
                <a href="leader_export_report.php?type=attendance&group_type=org&format=csv&org_id=<?= $org_id ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-download mr-1"></i> Attendance CSV
                </a>
                <a href="leader_export_report.php?type=payments&group_type=org&format=csv&org_id=<?= $org_id ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-download mr-1"></i> Payments CSV
                </a>
            </div>
        </div>

        <form method="get" class="org-filter-grid">
            <input type="hidden" name="org_id" value="<?= $org_id ?>">
            <div class="org-filter-start">
                <label class="font-weight-bold mb-2">Start Date</label>
                <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
            </div>
            <div class="org-filter-end">
                <label class="font-weight-bold mb-2">End Date</label>
                <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
            </div>
            <div class="org-filter-actions">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="fas fa-filter mr-1"></i> Apply
                </button>
                <a href="<?= htmlspecialchars(build_org_leader_redirect_url($org_id)) ?>" class="btn btn-light border flex-fill">
                    <i class="fas fa-times mr-1"></i> Reset
                </a>
            </div>
        </form>
    </div>

    <div class="org-stat-grid">
        <div class="org-stat-card">
            <div class="org-stat-label">Members</div>
            <div class="org-stat-value"><?= $total_members ?></div>
            <div class="org-stat-meta">Active people currently assigned to this organization.</div>
        </div>
        <div class="org-stat-card">
            <div class="org-stat-label">Pending Requests</div>
            <div class="org-stat-value"><?= $pending_count ?></div>
            <div class="org-stat-meta">Membership requests still waiting for leader action.</div>
        </div>
        <div class="org-stat-card">
            <div class="org-stat-label">Payments</div>
            <div class="org-stat-value">GHS <?= number_format($total_amount, 2) ?></div>
            <div class="org-stat-meta"><?= $unique_payers ?> unique payer<?= $unique_payers === 1 ? '' : 's' ?> in the selected period.</div>
        </div>
        <div class="org-stat-card">
            <div class="org-stat-label">Attendance Rate</div>
            <div class="org-stat-value"><?= number_format($attendance_rate, 1) ?>%</div>
            <div class="org-stat-meta"><?= $total_present ?> present records across <?= $total_sessions ?> session<?= $total_sessions === 1 ? '' : 's' ?>.</div>
        </div>
    </div>

    <div class="org-panel">
        <div class="org-panel-title">
            <div>
                <h5><i class="fas fa-user-plus text-primary mr-2"></i>Manage Membership</h5>
                <p class="text-muted mb-0">Search church members and add them directly into <?= htmlspecialchars($org_name) ?>.</p>
            </div>
            <a href="organization_membership_approvals.php?org_id=<?= $org_id ?>" class="btn btn-outline-primary org-badge-link">
                <i class="fas fa-user-check mr-1"></i> Open Approvals
                <?php if ($pending_count > 0): ?>
                    <span class="badge badge-warning ml-1"><?= $pending_count ?></span>
                <?php endif; ?>
            </a>
        </div>

        <form method="get" class="org-member-search-grid mb-3">
            <input type="hidden" name="org_id" value="<?= $org_id ?>">
            <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
            <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
            <div class="org-search-input">
                <label for="member_search" class="font-weight-bold mb-2">Find Member to Add</label>
                <input
                    type="text"
                    name="member_search"
                    id="member_search"
                    class="form-control"
                    value="<?= htmlspecialchars($member_search) ?>"
                    placeholder="Search by name, CRN, phone, or email"
                >
            </div>
            <div class="org-search-actions">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="fas fa-search mr-1"></i> Search
                </button>
                <?php if ($member_search !== ''): ?>
                    <a href="<?= htmlspecialchars(build_org_leader_redirect_url($org_id, $start_date, $end_date)) ?>" class="btn btn-light border flex-fill">
                        <i class="fas fa-eraser mr-1"></i> Clear
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <?php if (strlen($member_search) < 2): ?>
            <div class="org-empty">
                Enter at least 2 characters to search for members who are eligible to join this organization.
            </div>
        <?php elseif (!empty($member_search_results)): ?>
            <div class="table-responsive">
                <table class="table table-hover org-search-table mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>Member</th>
                            <th>Contact</th>
                            <th class="text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($member_search_results as $search_member): ?>
                            <tr>
                                <td>
                                    <div class="member-name"><?= htmlspecialchars($search_member['first_name'] . ' ' . $search_member['last_name']) ?></div>
                                    <small class="text-muted">CRN: <?= htmlspecialchars($search_member['crn'] ?: 'N/A') ?></small>
                                </td>
                                <td>
                                    <div><?= htmlspecialchars($search_member['email'] ?: 'No email') ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($search_member['phone'] ?: 'No phone') ?></small>
                                </td>
                                <td class="text-right">
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="add_member">
                                        <input type="hidden" name="organization_id" value="<?= $org_id ?>">
                                        <input type="hidden" name="member_id" value="<?= (int) $search_member['id'] ?>">
                                        <button
                                            type="submit"
                                            class="btn btn-success btn-sm"
                                            onclick="return confirm('Add <?= htmlspecialchars($search_member['first_name'] . ' ' . $search_member['last_name'], ENT_QUOTES) ?> to <?= htmlspecialchars($org_name, ENT_QUOTES) ?>?');"
                                        >
                                            <i class="fas fa-plus mr-1"></i> Add Member
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="org-empty">
                No eligible members matched "<?= htmlspecialchars($member_search) ?>".
            </div>
        <?php endif; ?>
    </div>

    <div class="row">
        <div class="col-xl-7 mb-4">
            <div class="org-panel h-100">
                <div class="org-panel-title">
                    <div>
                        <h5><i class="fas fa-users text-primary mr-2"></i>Current Members</h5>
                        <p class="text-muted mb-0">View, inspect, and manage the people currently assigned to this organization.</p>
                    </div>
                    <span class="badge badge-light border px-3 py-2"><?= $total_members ?> member<?= $total_members === 1 ? '' : 's' ?></span>
                </div>

                <?php if (!empty($members)): ?>
                    <div class="table-responsive org-scroll">
                        <table class="table table-hover org-member-table mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>Member</th>
                                    <th>Contact</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($members as $member): ?>
                                    <?php $is_active_leader = in_array((int) $member['id'], $active_leader_member_ids, true); ?>
                                    <tr>
                                        <td>
                                            <div class="member-name"><?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></div>
                                            <small class="text-muted">CRN: <?= htmlspecialchars($member['crn'] ?? 'N/A') ?></small>
                                            <?php if ($is_active_leader): ?>
                                                <div class="org-leader-pill">
                                                    <i class="fas fa-user-tie"></i>
                                                    <span>Active Leader</span>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div><?= htmlspecialchars($member['email'] ?: 'No email') ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($member['phone'] ?: 'No phone') ?></small>
                                        </td>
                                        <td class="text-right">
                                            <a href="leader_member_profile.php?id=<?= $member['id'] ?>" class="btn btn-sm btn-outline-primary mb-1">
                                                <i class="fas fa-eye mr-1"></i> Profile
                                            </a>
                                            <a href="leader_member_payments.php?member_id=<?= $member['id'] ?>" class="btn btn-sm btn-outline-success mb-1">
                                                <i class="fas fa-money-bill-wave mr-1"></i> Payments
                                            </a>
                                            <?php if ($is_active_leader): ?>
                                                <button type="button" class="btn btn-sm btn-outline-secondary mb-1" disabled title="Reassign leadership before removing this member">
                                                    <i class="fas fa-user-minus mr-1"></i> Remove
                                                </button>
                                            <?php else: ?>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="action" value="remove_member">
                                                    <input type="hidden" name="organization_id" value="<?= $org_id ?>">
                                                    <input type="hidden" name="member_id" value="<?= (int) $member['id'] ?>">
                                                    <button
                                                        type="submit"
                                                        class="btn btn-sm btn-outline-danger mb-1"
                                                        onclick="return confirm('Remove <?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name'], ENT_QUOTES) ?> from <?= htmlspecialchars($org_name, ENT_QUOTES) ?>?');"
                                                    >
                                                        <i class="fas fa-user-minus mr-1"></i> Remove
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="org-empty">
                        No members have been assigned to this organization yet.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-xl-5 mb-4">
            <div class="org-panel mb-4">
                <div class="org-panel-title">
                    <div>
                        <h5><i class="fas fa-money-bill-wave text-success mr-2"></i>Recent Payments</h5>
                        <p class="text-muted mb-0">Latest giving activity for members in the selected reporting window.</p>
                    </div>
                </div>

                <?php if (!empty($recent_payments)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover org-payments-table mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Member</th>
                                    <th>Type</th>
                                    <th class="text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_payments as $payment): ?>
                                    <tr>
                                        <td><?= date('M d', strtotime($payment['payment_date'])) ?></td>
                                        <td><?= htmlspecialchars($payment['member_name']) ?></td>
                                        <td><small><?= htmlspecialchars($payment['payment_type_name'] ?? 'N/A') ?></small></td>
                                        <td class="text-right"><strong>GHS <?= number_format((float) $payment['amount'], 2) ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="org-empty">
                        No payments were recorded in this period.
                    </div>
                <?php endif; ?>
            </div>

            <div class="org-panel">
                <div class="org-panel-title">
                    <div>
                        <h5><i class="fas fa-calendar-alt text-info mr-2"></i>Upcoming Attendance Sessions</h5>
                        <p class="text-muted mb-0">Quick access to the next sessions scheduled for your church.</p>
                    </div>
                </div>

                <?php if (!empty($upcoming_sessions)): ?>
                    <div class="org-session-list">
                        <?php foreach ($upcoming_sessions as $session): ?>
                            <div class="org-session-card">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="pr-3">
                                        <strong><?= htmlspecialchars($session['title']) ?></strong>
                                        <div class="org-session-date">
                                            <i class="fas fa-calendar mr-1"></i><?= date('l, F j, Y', strtotime($session['service_date'])) ?>
                                        </div>
                                    </div>
                                    <a href="my_organization_attendance.php?org_id=<?= $org_id ?>&session_id=<?= $session['id'] ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-clipboard-check mr-1"></i> Mark
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="org-empty">
                        No upcoming attendance sessions are scheduled right now.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$page_content = ob_get_clean();
$page_title = 'My Organization Leadership - ' . $org_name;
include '../includes/layout.php';
?>
