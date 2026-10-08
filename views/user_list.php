<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$is_super_admin = is_super_admin();
if (!$is_super_admin && !has_permission('view_user_list')) {
    http_response_code(403);
    include __DIR__ . '/errors/403.php';
    exit;
}

$can_add = $is_super_admin || has_permission('create_user');
$can_edit = $is_super_admin || has_permission('edit_user');
$can_delete = $is_super_admin || has_permission('delete_user');
$can_activate_user = $is_super_admin || has_permission('activate_user') || has_permission('edit_user');
$can_activate_member = $is_super_admin || has_permission('activate_member');
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$filter_church = max(0, (int) ($_GET['church_id'] ?? 0));
$filter_class = max(0, (int) ($_GET['class_id'] ?? 0));
$filter_org = max(0, (int) ($_GET['organization_id'] ?? 0));
$search = trim((string) ($_GET['search'] ?? ''));
$actor_church_id = 0;
if (!$is_super_admin) {
    $actor_user_id = (int) ($_SESSION['user_id'] ?? 0);
    $actor_stmt = $conn->prepare("SELECT church_id FROM users WHERE id = ? AND status = 'active' LIMIT 1");
    $actor_stmt->bind_param('i', $actor_user_id);
    $actor_stmt->execute();
    $actor_church_id = (int) ($actor_stmt->get_result()->fetch_assoc()['church_id'] ?? 0);
    $actor_stmt->close();
    if ($actor_church_id < 1) {
        http_response_code(403);
        include __DIR__ . '/errors/403.php';
        exit;
    }
    $filter_church = $actor_church_id;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = (int) ($_GET['per_page'] ?? 20);
$per_page = in_array($per_page, [10, 20, 50, 100], true) ? $per_page : 20;
$where = [];
$params = [];
$types = '';
if (!$is_super_admin) {
    $where[] = 'u.church_id = ?';
    $params[] = $actor_church_id;
    $types .= 'i';
} elseif ($filter_church > 0) {
    $where[] = 'u.church_id = ?';
    $params[] = $filter_church;
    $types .= 'i';
}
if ($filter_class > 0) {
    $where[] = 'm.class_id = ?';
    $params[] = $filter_class;
    $types .= 'i';
}
if ($filter_org > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM member_organizations mo_filter WHERE mo_filter.member_id = m.id AND mo_filter.organization_id = ?)';
    $params[] = $filter_org;
    $types .= 'i';
}
if ($search !== '') {
    $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR m.crn LIKE ? OR m.phone LIKE ? OR CONCAT_WS(" ", m.first_name, m.middle_name, m.last_name) LIKE ?)';
    $needle = '%' . $search . '%';
    array_push($params, $needle, $needle, $needle, $needle, $needle, $needle);
    $types .= 'ssssss';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = $conn->prepare(
    'SELECT COUNT(DISTINCT u.id) AS total,
            COALESCE(SUM(u.status = "active"), 0) AS active_accounts,
            COALESCE(SUM(u.status <> "active"), 0) AS inactive_accounts,
            COALESCE(SUM(u.member_id IS NULL), 0) AS unlinked_accounts
     FROM users u LEFT JOIN members m ON m.id = u.member_id' . $whereSql
);
if ($params) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$summary = $countStmt->get_result()->fetch_assoc() ?: [];
$countStmt->close();
$total_count = (int) ($summary['total'] ?? 0);
$total_pages = max(1, (int) ceil($total_count / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$sql = 'SELECT DISTINCT u.id AS user_id, u.name AS user_name, u.email AS user_email,
               u.phone AS user_phone, u.status AS user_status, u.member_id,
               u.church_id AS user_church_id, m.crn, m.phone, m.email AS member_email,
               m.class_id, m.status AS member_status, m.is_archived AS member_is_archived,
               bc.name AS class_name, c.name AS church_name
        FROM users u
        LEFT JOIN members m ON m.id = u.member_id
        LEFT JOIN bible_classes bc ON bc.id = m.class_id
        LEFT JOIN churches c ON c.id = u.church_id'
        . $whereSql . ' ORDER BY u.name, u.id LIMIT ? OFFSET ?';
$queryParams = array_merge($params, [$per_page, $offset]);
$stmt = $conn->prepare($sql);
$queryTypes = $types . 'ii';
$stmt->bind_param($queryTypes, ...$queryParams);
$stmt->execute();
$user_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Bounded eager loading avoids the former per-row role and leadership queries.
$rolesByUser = $classLeadershipByUser = $classLeadershipByMember = [];
$orgLeadershipByUser = $orgLeadershipByMember = $orgMembershipsByMember = [];
$userIds = array_values(array_unique(array_map('intval', array_column($user_rows, 'user_id'))));
$memberIds = array_values(array_filter(array_unique(array_map('intval', array_column($user_rows, 'member_id')))));
if ($userIds) {
    $idList = implode(',', $userIds);
    $result = $conn->query("SELECT ur.user_id, r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id IN ($idList) AND ur.is_active = 1 AND r.is_active = 1 AND (ur.expires_at IS NULL OR ur.expires_at > NOW()) ORDER BY r.name");
    while ($result && ($row = $result->fetch_assoc())) $rolesByUser[(int) $row['user_id']][] = (string) $row['name'];

    $identityClause = 'assignment.user_id IN (' . $idList . ')' . ($memberIds ? ' OR assignment.member_id IN (' . implode(',', $memberIds) . ')' : '');
    $result = $conn->query("SELECT assignment.user_id, assignment.member_id, assignment.leader_role, bc.name FROM bible_class_leaders assignment JOIN bible_classes bc ON bc.id = assignment.class_id WHERE assignment.status = 'active' AND ($identityClause) ORDER BY bc.name");
    while ($result && ($row = $result->fetch_assoc())) {
        $label = ucfirst((string) $row['leader_role']) . ': ' . (string) $row['name'];
        if ((int) ($row['user_id'] ?? 0) > 0) $classLeadershipByUser[(int) $row['user_id']][] = $label;
        if ((int) ($row['member_id'] ?? 0) > 0) $classLeadershipByMember[(int) $row['member_id']][] = $label;
    }
    $result = $conn->query("SELECT assignment.user_id, assignment.member_id, assignment.leader_role, organization.name FROM organization_leaders assignment JOIN organizations organization ON organization.id = assignment.organization_id WHERE assignment.status = 'active' AND ($identityClause) ORDER BY organization.name");
    while ($result && ($row = $result->fetch_assoc())) {
        $label = ucfirst((string) $row['leader_role']) . ': ' . (string) $row['name'];
        if ((int) ($row['user_id'] ?? 0) > 0) $orgLeadershipByUser[(int) $row['user_id']][] = $label;
        if ((int) ($row['member_id'] ?? 0) > 0) $orgLeadershipByMember[(int) $row['member_id']][] = $label;
    }
}
if ($memberIds) {
    $memberList = implode(',', $memberIds);
    $result = $conn->query("SELECT membership.member_id, organization.name FROM member_organizations membership JOIN organizations organization ON organization.id = membership.organization_id WHERE membership.member_id IN ($memberList) ORDER BY organization.name");
    while ($result && ($row = $result->fetch_assoc())) $orgMembershipsByMember[(int) $row['member_id']][] = (string) $row['name'];
}

$churches = [];
$churchSql = $is_super_admin ? 'SELECT id, name FROM churches ORDER BY name' : 'SELECT id, name FROM churches WHERE id = ' . $actor_church_id;
$result = $conn->query($churchSql);
while ($result && ($row = $result->fetch_assoc())) $churches[] = $row;
$classes = $organizations = [];
if ($filter_church > 0) {
    $filterStmt = $conn->prepare('SELECT id, name FROM bible_classes WHERE church_id = ? ORDER BY name');
    $filterStmt->bind_param('i', $filter_church); $filterStmt->execute();
    $classes = $filterStmt->get_result()->fetch_all(MYSQLI_ASSOC); $filterStmt->close();
    $filterStmt = $conn->prepare('SELECT id, name FROM organizations WHERE church_id = ? ORDER BY name');
    $filterStmt->bind_param('i', $filter_church); $filterStmt->execute();
    $organizations = $filterStmt->get_result()->fetch_all(MYSQLI_ASSOC); $filterStmt->close();
}
$pageLink = static function (int $targetPage): string {
    $query = $_GET; $query['page'] = $targetPage;
    return '?' . http_build_query($query);
};

ob_start();
?>
<link rel="stylesheet" href="<?= $h(BASE_URL) ?>/assets/css/directory-workspace.css">
<div class="container-fluid directory-workspace py-3">
    <?php if (isset($_GET['saved']) && in_array($_GET['saved'], ['created', 'updated'], true)): ?><div class="alert alert-success">User access <?= $h($_GET['saved']) ?> successfully.</div><?php endif; ?>
    <?php if (isset($_GET['retired'])): ?><div class="alert alert-success">User access was retired. The account and assignment history were retained for audit.</div><?php endif; ?>
    <?php if (isset($_GET['activated'])): ?><div class="alert alert-success"><?= isset($_GET['membership_activated']) ? 'The linked membership is active and the back-office access prerequisites are satisfied.' : 'Back-office access was activated successfully.' ?></div><?php endif; ?>
    <?php if (!empty($_SESSION['flash_error'])): ?><div class="alert alert-danger"><?= $h($_SESSION['flash_error']) ?></div><?php unset($_SESSION['flash_error']); endif; ?>
    <?php if (($_GET['onboarding'] ?? '') === 'sent'): ?><div class="alert alert-success">The onboarding SMS was accepted by the provider. The user must change the temporary password at first login.</div><?php elseif (($_GET['onboarding'] ?? '') === 'failed'): ?><div class="alert alert-warning">The account was saved, but the onboarding SMS was not delivered. Review the SMS delivery audit before retrying.</div><?php elseif (($_GET['onboarding'] ?? '') === 'skipped'): ?><div class="alert alert-info">The account was saved, but onboarding SMS delivery was skipped.</div><?php endif; ?>

    <section class="directory-hero mb-4"><div class="row align-items-center"><div class="col-lg-8"><span class="directory-eyebrow">Identity &amp; access governance</span><h1><i class="fas fa-users-cog mr-2"></i>User Directory</h1><p>Review linked membership, active roles, leadership assignments, and back-office access from one governed workspace.</p></div><div class="col-lg-4 directory-actions justify-content-lg-end"><?php if ($can_add): ?><a href="user_form.php" class="btn btn-light"><i class="fas fa-user-plus mr-2"></i>Add user</a><?php endif; ?><?php if ($is_super_admin || has_permission('view_access_reviews')): ?><a href="access_reviews.php" class="btn btn-outline-light"><i class="fas fa-clipboard-check mr-2"></i>Access reviews</a><?php endif; ?></div></div></section>

    <div class="row mb-4">
        <div class="col-6 col-xl-3 mb-3"><div class="metric-card"><span class="metric-label">Matched accounts</span><span class="metric-value"><?= number_format($total_count) ?></span><span class="metric-note">Current filter result</span></div></div>
        <div class="col-6 col-xl-3 mb-3"><div class="metric-card"><span class="metric-label">Active access</span><span class="metric-value text-success"><?= number_format((int) ($summary['active_accounts'] ?? 0)) ?></span><span class="metric-note">Eligible back-office accounts</span></div></div>
        <div class="col-6 col-xl-3 mb-3"><div class="metric-card"><span class="metric-label">Inactive access</span><span class="metric-value text-secondary"><?= number_format((int) ($summary['inactive_accounts'] ?? 0)) ?></span><span class="metric-note">Activation or review required</span></div></div>
        <div class="col-6 col-xl-3 mb-3"><div class="metric-card"><span class="metric-label">Not linked</span><span class="metric-value text-warning"><?= number_format((int) ($summary['unlinked_accounts'] ?? 0)) ?></span><span class="metric-note">No membership relationship</span></div></div>
    </div>

    <form method="get" class="filter-card mb-4" id="userFilterForm"><div class="form-row align-items-end">
        <div class="form-group col-lg-3 col-md-6 mb-2"><label for="search">Search directory</label><input type="search" class="form-control" id="search" name="search" value="<?= $h($search) ?>" placeholder="Name, email, phone or CRN"></div>
        <div class="form-group col-lg-2 col-md-6 mb-2"><label for="church_id">Church</label><select class="form-control" id="church_id" name="church_id" <?= !$is_super_admin ? 'disabled' : '' ?>><option value="">All churches</option><?php foreach ($churches as $church): ?><option value="<?= (int) $church['id'] ?>" <?= $filter_church === (int) $church['id'] ? 'selected' : '' ?>><?= $h($church['name']) ?></option><?php endforeach; ?></select><?php if (!$is_super_admin): ?><input type="hidden" name="church_id" value="<?= $actor_church_id ?>"><?php endif; ?></div>
        <div class="form-group col-lg-2 col-md-6 mb-2"><label for="class_id">Bible Class</label><select class="form-control" id="class_id" name="class_id" <?= $filter_church < 1 ? 'disabled' : '' ?>><option value="">All classes</option><?php foreach ($classes as $class): ?><option value="<?= (int) $class['id'] ?>" <?= $filter_class === (int) $class['id'] ? 'selected' : '' ?>><?= $h($class['name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group col-lg-2 col-md-6 mb-2"><label for="organization_id">Organization</label><select class="form-control" id="organization_id" name="organization_id" <?= $filter_church < 1 ? 'disabled' : '' ?>><option value="">All organizations</option><?php foreach ($organizations as $organization): ?><option value="<?= (int) $organization['id'] ?>" <?= $filter_org === (int) $organization['id'] ? 'selected' : '' ?>><?= $h($organization['name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group col-lg-1 col-md-3 mb-2"><label for="per_page">Rows</label><select class="form-control" id="per_page" name="per_page"><?php foreach ([10,20,50,100] as $size): ?><option value="<?= $size ?>" <?= $per_page === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?></select></div>
        <div class="form-group col-lg-2 col-md-9 mb-2"><div class="d-flex"><button class="btn btn-primary flex-fill mr-2" type="submit"><i class="fas fa-filter mr-1"></i>Apply</button><a href="user_list.php" class="btn btn-outline-secondary" aria-label="Clear filters"><i class="fas fa-undo"></i></a></div></div>
    </div></form>

    <section class="directory-card mb-4">
        <header class="directory-card-header"><div><h2 class="directory-card-title">People with system access</h2><small class="text-muted">Showing <?= $total_count ? number_format($offset + 1) : 0 ?>–<?= number_format(min($offset + $per_page, $total_count)) ?> of <?= number_format($total_count) ?></small></div><span class="chip chip-muted">Page <?= $page ?> of <?= $total_pages ?></span></header>
        <div class="table-responsive"><table class="table directory-table"><thead><tr><th>Identity</th><th>Contact</th><th>Class &amp; church</th><th>Roles &amp; relationships</th><th>Status</th><th>Actions</th></tr></thead><tbody>
        <?php if (!$user_rows): ?><tr><td colspan="6"><div class="empty-state"><i class="fas fa-user-friends fa-2x mb-3"></i><h3 class="h6 font-weight-bold">No users found</h3><p class="mb-0">Adjust the filters or create a new account.</p></div></td></tr>
        <?php else: foreach ($user_rows as $u):
            $userId = (int) $u['user_id']; $memberId = (int) ($u['member_id'] ?? 0);
            $roles = array_values(array_unique($rolesByUser[$userId] ?? []));
            $rowIsSuperAdmin = (bool) array_filter($roles, static fn($role) => in_array(strtolower(trim($role)), ['super admin','super administrator'], true));
            $classLeadership = array_values(array_unique(array_merge($classLeadershipByUser[$userId] ?? [], $memberId ? ($classLeadershipByMember[$memberId] ?? []) : [])));
            $orgLeadership = array_values(array_unique(array_merge($orgLeadershipByUser[$userId] ?? [], $memberId ? ($orgLeadershipByMember[$memberId] ?? []) : [])));
            $orgMemberships = $memberId ? array_values(array_unique($orgMembershipsByMember[$memberId] ?? [])) : [];
            $memberStatus = (string) ($u['member_status'] ?? '');
        ?><tr>
            <td data-label="Identity"><div class="identity-name"><?= $h($u['user_name']) ?></div><span class="identity-meta"><?= $memberId ? 'CRN ' . $h($u['crn'] ?: 'not assigned') : 'No linked membership' ?></span></td>
            <td data-label="Contact"><div><?= $h($u['user_email'] ?: $u['member_email'] ?: 'No email') ?></div><span class="identity-meta"><?= $h($u['phone'] ?: $u['user_phone'] ?: 'No phone') ?></span></td>
            <td data-label="Class and church"><div><?= $h($u['class_name'] ?: 'No Bible Class') ?></div><span class="identity-meta"><?= $h($u['church_name'] ?: 'Church unavailable') ?></span></td>
            <td data-label="Roles and relationships"><?php foreach ($roles as $role): ?><span class="chip"><?= $h($role) ?></span><?php endforeach; ?><?php foreach ($classLeadership as $relationship): ?><span class="chip chip-success"><i class="fas fa-book-open mr-1"></i><?= $h($relationship) ?></span><?php endforeach; ?><?php foreach ($orgLeadership as $relationship): ?><span class="chip chip-info"><i class="fas fa-sitemap mr-1"></i><?= $h($relationship) ?></span><?php endforeach; ?><?php if ($orgMemberships): ?><span class="identity-meta mt-1">Member of <?= $h(implode(', ', $orgMemberships)) ?></span><?php elseif (!$roles && !$classLeadership && !$orgLeadership): ?><span class="text-muted small">No active assignments</span><?php endif; ?></td>
            <td data-label="Status"><span class="chip <?= $u['user_status'] === 'active' ? 'chip-success' : 'chip-muted' ?>">Access: <?= $h(ucfirst((string) $u['user_status'])) ?></span><?php if (!$memberId): ?><span class="chip chip-warning">Membership: not linked</span><?php elseif ((int) ($u['member_is_archived'] ?? 0) === 1): ?><span class="chip chip-muted">Membership: archived</span><?php else: ?><span class="chip <?= $memberStatus === 'active' ? 'chip-success' : 'chip-warning' ?>">Membership: <?= $h(ucfirst($memberStatus ?: 'unknown')) ?></span><?php endif; ?></td>
            <td data-label="Actions"><div class="row-actions">
            <?php if ($rowIsSuperAdmin): ?><button class="btn btn-sm btn-outline-secondary" disabled title="Protected Super Administrator account"><i class="fas fa-shield-alt"></i></button>
            <?php else: ?>
                <?php if ($can_edit): ?><a href="user_form.php?id=<?= $userId ?>" class="btn btn-sm btn-outline-primary" title="Edit account" aria-label="Edit account"><i class="fas fa-edit"></i></a><?php endif; ?>
                <?php if ($u['user_status'] === 'active'): ?>
                    <?php if ($memberId && (int) ($u['member_is_archived'] ?? 0) === 0 && in_array($memberStatus, ['pending','de-activated'], true) && $can_activate_user && $can_activate_member): ?><form method="post" action="user_activate.php" onsubmit="return confirm('This account already has access. Activate its linked membership now and record the reconciliation?');"><?= csrf_input() ?><input type="hidden" name="id" value="<?= $userId ?>"><input type="hidden" name="activate_linked_member" value="1"><button class="btn btn-sm btn-warning" title="Activate linked membership"><i class="fas fa-link"></i></button></form><?php endif; ?>
                    <?php if ($can_delete): ?><form method="post" action="user_deactivate.php" onsubmit="return confirm('Deactivate this user?');"><?= csrf_input() ?><input type="hidden" name="id" value="<?= $userId ?>"><button class="btn btn-sm btn-outline-danger" title="Deactivate access"><i class="fas fa-user-slash"></i></button></form><?php endif; ?>
                <?php elseif ($u['user_status'] === 'inactive' && $can_activate_user): ?>
                    <?php if ($memberStatus === 'active'): ?><form method="post" action="user_activate.php" onsubmit="return confirm('Activate this back-office account?');"><?= csrf_input() ?><input type="hidden" name="id" value="<?= $userId ?>"><button class="btn btn-sm btn-outline-success" title="Activate back-office access"><i class="fas fa-user-check"></i></button></form>
                    <?php elseif ($memberId && (int) ($u['member_is_archived'] ?? 0) === 0 && in_array($memberStatus, ['pending','de-activated'], true) && $can_activate_member): ?><form method="post" action="user_activate.php" onsubmit="return confirm('Activate the linked membership first, then activate this back-office account? Both actions will be audited.');"><?= csrf_input() ?><input type="hidden" name="id" value="<?= $userId ?>"><input type="hidden" name="activate_linked_member" value="1"><button class="btn btn-sm btn-success" title="Activate membership &amp; access"><i class="fas fa-user-check mr-1"></i>Activate both</button></form>
                    <?php else: ?><button class="btn btn-sm btn-outline-secondary" disabled title="Activate or restore the linked membership first"><i class="fas fa-user-lock"></i></button><?php endif; ?>
                <?php endif; ?>
                <?php if ($can_delete): ?><form method="post" action="user_delete.php" onsubmit="return confirm('Retire this back-office access? The account and assignment history will be retained.');"><?= csrf_input() ?><input type="hidden" name="id" value="<?= $userId ?>"><button class="btn btn-sm btn-outline-dark" title="Retire access"><i class="fas fa-archive"></i></button></form><?php endif; ?>
            <?php endif; ?></div></td>
        </tr><?php endforeach; endif; ?>
        </tbody></table></div>
        <?php if ($total_count > 0): ?><footer class="directory-footer d-flex flex-column flex-md-row justify-content-between align-items-md-center"><small class="text-muted mb-2 mb-md-0"><?= $search !== '' ? 'Filtered by “' . $h($search) . '” · ' : '' ?><?= number_format($total_count) ?> account<?= $total_count === 1 ? '' : 's' ?></small><?php if ($total_pages > 1): ?><nav aria-label="User directory pages"><ul class="pagination pagination-sm mb-0"><?php if ($page > 1): ?><li class="page-item"><a class="page-link" href="<?= $h($pageLink($page - 1)) ?>">&lsaquo;</a></li><?php endif; ?><?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?><li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= $h($pageLink($p)) ?>"><?= $p ?></a></li><?php endfor; ?><?php if ($page < $total_pages): ?><li class="page-item"><a class="page-link" href="<?= $h($pageLink($page + 1)) ?>">&rsaquo;</a></li><?php endif; ?></ul></nav><?php endif; ?></footer><?php endif; ?>
    </section>
</div>
<script>
$(function(){
    $('#church_id').on('change',function(){
        var churchId=$(this).val();
        $('#class_id,#organization_id').prop('disabled',!churchId);
        $('#class_id').html('<option value="">All classes</option>');
        $('#organization_id').html('<option value="">All organizations</option>');
        if(!churchId)return;
        $.get('ajax_get_classes_by_church.php',{church_id:churchId},function(data){$('#class_id').append(data);});
        $.get('ajax_get_organizations_by_church.php',{church_id:churchId},function(data){$('#organization_id').append(data);});
    });
});
</script>
<?php
$page_content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
