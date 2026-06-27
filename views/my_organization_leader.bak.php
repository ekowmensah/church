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

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
$member_id = $_SESSION['member_id'] ?? null;
$org_leaderships = is_organization_leader($conn, $user_id, $member_id);

if (!$org_leaderships) {
    http_response_code(403);
    echo '<div class="alert alert-danger">You are not assigned as an organization leader.</div>';
    exit;
}

if (count($org_leaderships) > 1) {
    $org_id = isset($_GET['org_id']) ? intval($_GET['org_id']) : 0;

    if (!$org_id) {
        header('Location: my_organizations_leader.php');
        exit;
    }

    $leader_info = null;
    foreach ($org_leaderships as $org) {
        if ((int) $org['organization_id'] === $org_id) {
            $leader_info = $org;
            break;
        }
    }

    if (!$leader_info) {
        http_response_code(403);
        echo '<div class="alert alert-danger">You are not the leader of this organization.</div>';
        exit;
    }
} else {
    $leader_info = $org_leaderships[0];
    $org_id = (int) $leader_info['organization_id'];
}

$org_name = $leader_info['org_name'];
$org_description = $leader_info['description'];
$church_id = (int) ($leader_info['church_id'] ?? 0);
$session_user_id = (int) ($_SESSION['user_id'] ?? 0);

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
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
            $memberSql = "
                SELECT m.id, m.first_name, m.last_name
                FROM members m
                WHERE m.id = ? AND m.status = 'active'
            ";
            if ($church_id > 0) {
                $memberSql .= " AND m.church_id = ?";
            }
            $memberSql .= " LIMIT 1";

            $memberStmt = $conn->prepare($memberSql);
            if ($church_id > 0) {
                $memberStmt->bind_param('ii', $target_member_id, $church_id);
            } else {
                $memberStmt->bind_param('i', $target_member_id);
            }
            $memberStmt->execute();
            $memberResult = $memberStmt->get_result();

            if ($memberResult->num_rows === 0) {
                $error = 'The selected member could not be found in this church.';
            } else {
                $memberData = $memberResult->fetch_assoc();

                $existsStmt = $conn->prepare('SELECT 1 FROM member_organizations WHERE member_id = ? AND organization_id = ? LIMIT 1');
                $existsStmt->bind_param('ii', $target_member_id, $org_id);
                $existsStmt->execute();
                $existsResult = $existsStmt->get_result();
                $alreadyMember = $existsResult->num_rows > 0;
                $existsStmt->close();

                if ($alreadyMember) {
                    $error = 'That member already belongs to this organization.';
                } else {
                    $conn->begin_transaction();
                    try {
                        add_member_to_organization($conn, $target_member_id, $org_id);

                        $notes = 'Added directly by organization leader from My Organization Leadership.';
                        $approvalStmt = $conn->prepare("
                            UPDATE organization_membership_approvals
                            SET status = 'approved', approved_by = ?, approved_at = NOW(), notes = ?
                            WHERE member_id = ? AND organization_id = ? AND status = 'pending'
                        ");
                        $approvalStmt->bind_param('isii', $session_user_id, $notes, $target_member_id, $org_id);
                        if (!$approvalStmt->execute()) {
                            throw new Exception($approvalStmt->error ?: 'Failed to sync pending approval records.');
                        }
                        $approvalStmt->close();

                        $conn->commit();
                        $_SESSION['org_member_success'] = 'Added ' . $memberData['first_name'] . ' ' . $memberData['last_name'] . ' to ' . $org_name . '.';
                        header('Location: ' . build_org_leader_redirect_url($org_id, $start_date, $end_date));
                        exit;
                    } catch (Exception $e) {
                        $conn->rollback();
                        $error = 'Error adding member: ' . $e->getMessage();
                    }
                }
            }

            $memberStmt->close();
        }
    } elseif ($action === 'remove_member') {
        if ($target_member_id <= 0) {
            $error = 'Invalid member selected for removal.';
        } else {
            $memberStmt = $conn->prepare("
                SELECT m.id, m.first_name, m.last_name
                FROM members m
                INNER JOIN member_organizations mo ON mo.member_id = m.id
                WHERE m.id = ? AND mo.organization_id = ?
                LIMIT 1
            ");
            $memberStmt->bind_param('ii', $target_member_id, $org_id);
            $memberStmt->execute();
            $memberResult = $memberStmt->get_result();

            if ($memberResult->num_rows === 0) {
                $error = 'That membership record could not be found.';
            } else {
                $memberData = $memberResult->fetch_assoc();

                if (is_active_organization_leader_member($conn, $org_id, $target_member_id)) {
                    $error = 'You cannot remove the active leader from this organization. Reassign leadership first.';
                } else {
                    $deleteStmt = $conn->prepare('DELETE FROM member_organizations WHERE member_id = ? AND organization_id = ?');
                    $deleteStmt->bind_param('ii', $target_member_id, $org_id);
                    if (!$deleteStmt->execute()) {
                        $error = 'Failed to remove member: ' . $deleteStmt->error;
                    } else {
                        $_SESSION['org_member_success'] = 'Removed ' . $memberData['first_name'] . ' ' . $memberData['last_name'] . ' from ' . $org_name . '.';
                        header('Location: ' . build_org_leader_redirect_url($org_id, $start_date, $end_date, $member_search));
                        exit;
                    }
                    $deleteStmt->close();
                }
            }

            $memberStmt->close();
        }
    }
}

$members = get_organization_members($conn, $org_id);
$total_members = count($members);
$payment_stats = get_organization_payment_stats($conn, $org_id, $start_date, $end_date);
$attendance_stats = get_organization_attendance_stats($conn, $org_id, $start_date, $end_date);

$pending_count = 0;
$pendingStmt = $conn->prepare("
    SELECT COUNT(*) AS pending_count
    FROM organization_membership_approvals
    WHERE organization_id = ? AND status = 'pending'
");
$pendingStmt->bind_param('i', $org_id);
$pendingStmt->execute();
$pendingResult = $pendingStmt->get_result();
if ($pendingRow = $pendingResult->fetch_assoc()) {
    $pending_count = (int) ($pendingRow['pending_count'] ?? 0);
}
$pendingStmt->close();

$active_leader_member_ids = [];
if (organization_leaders_has_member_id($conn)) {
    $leaderMemberStmt = $conn->prepare("
        SELECT DISTINCT
            CASE
                WHEN ol.member_id IS NOT NULL AND ol.member_id > 0 THEN ol.member_id
                ELSE u.member_id
            END AS member_id
        FROM organization_leaders ol
        LEFT JOIN users u ON ol.user_id = u.id
        WHERE ol.organization_id = ? AND ol.status = 'active'
    ");
    $leaderMemberStmt->bind_param('i', $org_id);
} else {
    $leaderMemberStmt = $conn->prepare("
        SELECT DISTINCT u.member_id AS member_id
        FROM organization_leaders ol
        LEFT JOIN users u ON ol.user_id = u.id
        WHERE ol.organization_id = ? AND ol.status = 'active'
    ");
    $leaderMemberStmt->bind_param('i', $org_id);
}
$leaderMemberStmt->execute();
$leaderMemberResult = $leaderMemberStmt->get_result();
while ($leaderMemberRow = $leaderMemberResult->fetch_assoc()) {
    $leaderMemberId = (int) ($leaderMemberRow['member_id'] ?? 0);
    if ($leaderMemberId > 0) {
        $active_leader_member_ids[] = $leaderMemberId;
    }
}
$leaderMemberStmt->close();
$active_leader_member_ids = array_values(array_unique($active_leader_member_ids));

$stmt = $conn->prepare("
    SELECT p.*, pt.name AS payment_type_name,
           CONCAT(m.first_name, ' ', m.last_name) AS member_name
    FROM payments p
    INNER JOIN member_organizations mo ON p.member_id = mo.member_id
    INNER JOIN members m ON p.member_id = m.id
    LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
    WHERE mo.organization_id = ? AND p.payment_date BETWEEN ? AND ?
    ORDER BY p.payment_date DESC
    LIMIT 10
");
$stmt->bind_param('iss', $org_id, $start_date, $end_date);
$stmt->execute();
$recent_payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("
    SELECT ats.*, c.name AS church_name
    FROM attendance_sessions ats
    LEFT JOIN churches c ON ats.church_id = c.id
    WHERE ats.church_id = ? AND ats.service_date >= CURDATE()
    ORDER BY ats.service_date ASC
    LIMIT 5
");
$stmt->bind_param('i', $church_id);
$stmt->execute();
$upcoming_sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (strlen($member_search) >= 2) {
    $searchLike = '%' . $member_search . '%';
    $searchSql = "
        SELECT m.id, m.first_name, m.last_name, m.email, m.phone, m.crn
        FROM members m
        WHERE m.status = 'active'
          AND (
              CONCAT_WS(' ', m.first_name, m.last_name) LIKE ?
              OR m.crn LIKE ?
              OR m.phone LIKE ?
              OR m.email LIKE ?
          )
          AND NOT EXISTS (
              SELECT 1
              FROM member_organizations mo
              WHERE mo.member_id = m.id AND mo.organization_id = ?
          )
    ";
    if ($church_id > 0) {
        $searchSql .= " AND m.church_id = ?";
    }
    $searchSql .= " ORDER BY m.last_name, m.first_name LIMIT 25";

    $searchStmt = $conn->prepare($searchSql);
    if ($church_id > 0) {
        $searchStmt->bind_param('ssssii', $searchLike, $searchLike, $searchLike, $searchLike, $org_id, $church_id);
    } else {
        $searchStmt->bind_param('ssssi', $searchLike, $searchLike, $searchLike, $searchLike, $org_id);
    }
    $searchStmt->execute();
    $searchResult = $searchStmt->get_result();
    while ($row = $searchResult->fetch_assoc()) {
        $member_search_results[] = $row;
    }
    $searchStmt->close();
}

if (isset($_SESSION['org_member_success'])) {
    $success = $_SESSION['org_member_success'];
    unset($_SESSION['org_member_success']);
}

ob_start();
?>
<style>
.leader-dashboard {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
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
    transform: translateY(-5px);
}

.stat-card.members { border-left-color: #f093fb; }
.stat-card.payments { border-left-color: #28a745; }
.stat-card.attendance { border-left-color: #17a2b8; }
.stat-card.rate { border-left-color: #ffc107; }

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

.member-card {
    background: white;
    border-radius: 10px;
    padding: 15px;
    margin-bottom: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    transition: all 0.3s;
}

.member-card:hover {
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    transform: translateX(5px);
}

.section-card {
    background: white;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.08);
}

.filter-card {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
}

.member-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.member-search-table td,
.member-search-table th {
    vertical-align: middle;
}
</style>

<div class="leader-dashboard">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h2><i class="fas fa-users-cog"></i> My Organization Leadership</h2>
            <h4><?= htmlspecialchars($org_name) ?></h4>
            <?php if ($org_description): ?>
            <p class="mb-0"><?= htmlspecialchars($org_description) ?></p>
            <?php endif; ?>
        </div>
        <div>
            <div class="btn-group">
                <a href="my_organization_attendance.php?org_id=<?= $org_id ?>" class="btn btn-light btn-lg">
                    <i class="fas fa-clipboard-check"></i> Mark Attendance
                </a>
                <button type="button" class="btn btn-light btn-lg dropdown-toggle dropdown-toggle-split" data-toggle="dropdown">
                    <span class="sr-only">Toggle Dropdown</span>
                </button>
                <div class="dropdown-menu dropdown-menu-right">
                    <a class="dropdown-item" href="organization_membership_approvals.php?org_id=<?= $org_id ?>">
                        <i class="fas fa-user-check"></i> Membership Approvals
                        <?php if ($pending_count > 0): ?>
                            <span class="badge badge-warning ml-2"><?= $pending_count ?></span>
                        <?php endif; ?>
                    </a>
                    <a class="dropdown-item" href="leader_export_report.php?type=members&group_type=org&format=csv&org_id=<?= $org_id ?>">
                        <i class="fas fa-download"></i> Export Members (CSV)
                    </a>
                    <a class="dropdown-item" href="leader_export_report.php?type=attendance&group_type=org&format=csv&org_id=<?= $org_id ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>">
                        <i class="fas fa-download"></i> Export Attendance (CSV)
                    </a>
                    <a class="dropdown-item" href="leader_export_report.php?type=payments&group_type=org&format=csv&org_id=<?= $org_id ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>">
                        <i class="fas fa-download"></i> Export Payments (CSV)
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

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

<div class="filter-card">
    <form method="get" class="row align-items-end">
        <input type="hidden" name="org_id" value="<?= $org_id ?>">
        <div class="col-md-4">
            <label class="form-label fw-bold">Start Date</label>
            <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-bold">End Date</label>
            <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
        </div>
        <div class="col-md-4">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-filter"></i> Apply Filter
            </button>
            <a href="<?= htmlspecialchars(build_org_leader_redirect_url($org_id)) ?>" class="btn btn-secondary">
                <i class="fas fa-times"></i> Clear
            </a>
        </div>
    </form>
</div>

<div class="section-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h5 class="mb-1">
                <i class="fas fa-user-plus text-primary"></i> Manage Membership
            </h5>
            <p class="text-muted mb-0">Search church members and add them directly to this organization.</p>
        </div>
        <a href="organization_membership_approvals.php?org_id=<?= $org_id ?>" class="btn btn-outline-primary">
            <i class="fas fa-user-check mr-1"></i> Approvals
            <?php if ($pending_count > 0): ?>
                <span class="badge badge-warning ml-1"><?= $pending_count ?></span>
            <?php endif; ?>
        </a>
    </div>

    <form method="get" class="row align-items-end mb-3">
        <input type="hidden" name="org_id" value="<?= $org_id ?>">
        <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
        <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
        <div class="col-md-9">
            <label for="member_search" class="form-label fw-bold">Find Member to Add</label>
            <input
                type="text"
                name="member_search"
                id="member_search"
                class="form-control"
                value="<?= htmlspecialchars($member_search) ?>"
                placeholder="Search by name, CRN, phone, or email"
            >
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-primary btn-block">
                <i class="fas fa-search mr-1"></i> Search
            </button>
        </div>
    </form>

    <?php if (strlen($member_search) < 2): ?>
        <div class="alert alert-light mb-0">Enter at least 2 characters to search for members you can add.</div>
    <?php elseif (!empty($member_search_results)): ?>
        <div class="table-responsive">
            <table class="table table-bordered table-hover member-search-table mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>Member</th>
                        <th>Contact</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($member_search_results as $search_member): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($search_member['first_name'] . ' ' . $search_member['last_name']) ?></strong><br>
                                <small class="text-muted">CRN: <?= htmlspecialchars($search_member['crn'] ?: 'N/A') ?></small>
                            </td>
                            <td>
                                <div><?= htmlspecialchars($search_member['email'] ?: 'No email') ?></div>
                                <small class="text-muted"><?= htmlspecialchars($search_member['phone'] ?: 'No phone') ?></small>
                            </td>
                            <td>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="action" value="add_member">
                                    <input type="hidden" name="organization_id" value="<?= $org_id ?>">
                                    <input type="hidden" name="member_id" value="<?= (int) $search_member['id'] ?>">
                                    <button
                                        type="submit"
                                        class="btn btn-success btn-sm"
                                        onclick="return confirm('Add <?= htmlspecialchars($search_member['first_name'] . ' ' . $search_member['last_name'], ENT_QUOTES) ?> to <?= htmlspecialchars($org_name, ENT_QUOTES) ?>?');"
                                    >
                                        <i class="fas fa-plus mr-1"></i> Add
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="alert alert-info mb-0">No eligible members matched "<?= htmlspecialchars($member_search) ?>".</div>
    <?php endif; ?>
</div>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="stat-card members">
            <div class="stat-label">Total Members</div>
            <div class="stat-value"><?= $total_members ?></div>
            <small class="text-muted">In your organization</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card payments">
            <div class="stat-label">Total Payments</div>
            <div class="stat-value">GHâ‚µ <?= number_format($payment_stats['total_amount'] ?? 0, 2) ?></div>
            <small class="text-muted"><?= $payment_stats['total_payments'] ?? 0 ?> transactions</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card attendance">
            <div class="stat-label">Attendance</div>
            <div class="stat-value"><?= $attendance_stats['total_present'] ?? 0 ?></div>
            <small class="text-muted">Out of <?= $attendance_stats['total_records'] ?? 0 ?> records</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card rate">
            <div class="stat-label">Attendance Rate</div>
            <div class="stat-value"><?= $attendance_stats['attendance_rate'] ?? 0 ?>%</div>
            <small class="text-muted"><?= $attendance_stats['total_sessions'] ?? 0 ?> sessions</small>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="section-card">
            <h5 class="mb-4">
                <i class="fas fa-users text-primary"></i> Organization Members (<?= $total_members ?>)
            </h5>
            <div style="max-height: 500px; overflow-y: auto;">
                <?php foreach ($members as $member): ?>
                <?php $is_active_leader = in_array((int) $member['id'], $active_leader_member_ids, true); ?>
                <div class="member-card">
                    <div class="d-flex align-items-center">
                        <img src="<?= BASE_URL ?>/uploads/members/<?= htmlspecialchars($member['photo'] ?? 'default.png') ?>"
                             alt="Photo"
                             style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover; margin-right: 15px;">
                        <div class="flex-grow-1">
                            <strong><?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></strong>
                            <br>
                            <small class="text-muted">
                                <i class="fas fa-id-card"></i> <?= htmlspecialchars($member['crn'] ?? 'N/A') ?>
                                <?php if ($member['phone']): ?>
                                | <i class="fas fa-phone"></i> <?= htmlspecialchars($member['phone']) ?>
                                <?php endif; ?>
                            </small>
                            <?php if ($is_active_leader): ?>
                            <br>
                            <small class="text-info"><i class="fas fa-user-tie"></i> Active Leader</small>
                            <?php endif; ?>
                        </div>
                        <div class="member-actions">
                            <a href="leader_member_profile.php?id=<?= $member['id'] ?>"
                               class="btn btn-sm btn-outline-primary"
                               title="View Profile">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="leader_member_payments.php?member_id=<?= $member['id'] ?>"
                               class="btn btn-sm btn-outline-success"
                               title="View Payments">
                                <i class="fas fa-money-bill-wave"></i>
                            </a>
                            <?php if ($is_active_leader): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Reassign leadership before removing this member">
                                    <i class="fas fa-user-minus"></i>
                                </button>
                            <?php else: ?>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="action" value="remove_member">
                                    <input type="hidden" name="organization_id" value="<?= $org_id ?>">
                                    <input type="hidden" name="member_id" value="<?= (int) $member['id'] ?>">
                                    <button
                                        type="submit"
                                        class="btn btn-sm btn-outline-danger"
                                        title="Remove Member"
                                        onclick="return confirm('Remove <?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name'], ENT_QUOTES) ?> from <?= htmlspecialchars($org_name, ENT_QUOTES) ?>?');"
                                    >
                                        <i class="fas fa-user-minus"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if (empty($members)): ?>
                <div class="alert alert-info">No members in this organization yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="section-card mb-3">
            <h5 class="mb-4">
                <i class="fas fa-money-bill-wave text-success"></i> Recent Payments
            </h5>
            <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                <table class="table table-sm table-hover">
                    <thead class="thead-light">
                        <tr>
                            <th>Date</th>
                            <th>Member</th>
                            <th>Type</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_payments as $payment): ?>
                        <tr>
                            <td><?= date('M d', strtotime($payment['payment_date'])) ?></td>
                            <td><?= htmlspecialchars($payment['member_name']) ?></td>
                            <td><small><?= htmlspecialchars($payment['payment_type_name'] ?? 'N/A') ?></small></td>
                            <td><strong>GHâ‚µ <?= number_format($payment['amount'], 2) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>

                        <?php if (empty($recent_payments)): ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted">No payments in this period</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="section-card">
            <h5 class="mb-4">
                <i class="fas fa-calendar-alt text-info"></i> Upcoming Attendance Sessions
            </h5>
            <?php foreach ($upcoming_sessions as $session): ?>
            <div class="alert alert-light border-left border-primary">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong><?= htmlspecialchars($session['title']) ?></strong>
                        <br>
                        <small class="text-muted">
                            <i class="fas fa-calendar"></i> <?= date('l, F j, Y', strtotime($session['service_date'])) ?>
                        </small>
                    </div>
                    <a href="my_organization_attendance.php?org_id=<?= $org_id ?>&session_id=<?= $session['id'] ?>"
                       class="btn btn-sm btn-primary">
                        <i class="fas fa-clipboard-check"></i> Mark
                    </a>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if (empty($upcoming_sessions)): ?>
            <div class="alert alert-info">No upcoming sessions scheduled.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$page_content = ob_get_clean();
$page_title = 'My Organization Leadership - ' . $org_name;
include '../includes/layout.php';
?>
