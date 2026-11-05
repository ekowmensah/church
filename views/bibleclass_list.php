<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/bible_class_capacity.php';
require_once __DIR__ . '/../helpers/csrf.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$is_super_admin = is_super_admin();
if (!$is_super_admin && !has_permission('view_bibleclass_list')) {
    http_response_code(403);
    include __DIR__ . '/errors/403.php';
    exit;
}

$can_add = $is_super_admin || has_permission('create_bibleclass');
$can_edit = $is_super_admin || has_permission('edit_bibleclass');
$can_delete = $is_super_admin || has_permission('delete_bibleclass');
$can_export = $is_super_admin || has_permission('export_bibleclass');
$capacity_rules_available = bible_class_rules_table_exists($conn);
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$actorChurchId = 0;
$filterChurchId = max(0, (int) ($_GET['church_id'] ?? 0));
if (!$is_super_admin) {
    $actorUserId = (int) ($_SESSION['user_id'] ?? 0);
    $stmt = $conn->prepare("SELECT church_id FROM users WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->bind_param('i', $actorUserId);
    $stmt->execute();
    $actorChurchId = (int) ($stmt->get_result()->fetch_assoc()['church_id'] ?? 0);
    $stmt->close();
    if ($actorChurchId < 1) {
        http_response_code(403);
        include __DIR__ . '/errors/403.php';
        exit;
    }
    $filterChurchId = $actorChurchId;
}

$search = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$requestedPerPage = (int) ($_GET['per_page'] ?? 25);
$perPage = in_array($requestedPerPage, [10, 25, 50, 100], true) ? $requestedPerPage : 25;
$whereParts = [];
$queryTypes = '';
$queryParams = [];
if ($filterChurchId > 0) {
    $whereParts[] = 'bc.church_id = ?';
    $queryTypes = 'i';
    $queryParams[] = $filterChurchId;
}
if ($search !== '') {
    $whereParts[] = "(bc.name LIKE CONCAT('%', ?, '%')
        OR bc.code LIKE CONCAT('%', ?, '%')
        OR church.name LIKE CONCAT('%', ?, '%'))";
    $queryTypes .= 'sss';
    array_push($queryParams, $search, $search, $search);
}
$where = $whereParts ? ' WHERE ' . implode(' AND ', $whereParts) : '';

$metricsSql = "SELECT COUNT(*) AS total_classes,
        COALESCE(SUM(COALESCE(member_count.active_member_count, 0)), 0) AS total_members,
        COALESCE(SUM(CASE WHEN "
            . ($capacity_rules_available
                ? 'COALESCE(rule.enforce_limit, 1) = 1 AND COALESCE(member_count.active_member_count, 0) >= COALESCE(rule.max_members, 25)'
                : 'COALESCE(member_count.active_member_count, 0) >= 25')
            . " THEN 1 ELSE 0 END), 0) AS full_classes,
        COALESCE(SUM(CASE WHEN bc.leader_id IS NULL AND NOT EXISTS (
            SELECT 1 FROM bible_class_leaders leader_assignment
             WHERE leader_assignment.class_id = bc.id
               AND leader_assignment.status = 'active'
               AND leader_assignment.leader_role = 'primary'
        ) THEN 1 ELSE 0 END), 0) AS without_leaders
    FROM bible_classes bc
    LEFT JOIN churches church ON church.id = bc.church_id
    " . ($capacity_rules_available ? 'LEFT JOIN bible_class_rules rule ON rule.class_id = bc.id' : '') . "
    LEFT JOIN (
        SELECT class_id, COUNT(*) AS active_member_count
          FROM members
         WHERE class_id IS NOT NULL AND status = 'active' AND is_archived = 0
         GROUP BY class_id
    ) member_count ON member_count.class_id = bc.id
    $where";
$metricsStmt = $conn->prepare($metricsSql);
if ($queryParams) $metricsStmt->bind_param($queryTypes, ...$queryParams);
$metricsStmt->execute();
$metrics = $metricsStmt->get_result()->fetch_assoc() ?: [];
$metricsStmt->close();
$totalClasses = (int) ($metrics['total_classes'] ?? 0);
$totalMembers = (int) ($metrics['total_members'] ?? 0);
$fullClasses = (int) ($metrics['full_classes'] ?? 0);
$withoutLeaders = (int) ($metrics['without_leaders'] ?? 0);
$totalPages = max(1, (int) ceil($totalClasses / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$exportQuery = http_build_query(array_filter([
    'church_id' => $filterChurchId ?: null,
    'q' => $search !== '' ? $search : null,
], static fn($value) => $value !== null && $value !== ''));

$bibleclassSql = "
    SELECT bc.*,
           COALESCE(primary_user.name, legacy_user.name) AS leader_name,
           COALESCE(primary_user.email, legacy_user.email) AS leader_email,
           COALESCE(primary_user.id, legacy_user.id) AS leader_user_id,
           TRIM(CONCAT_WS(' ', primary_member.first_name, primary_member.middle_name, primary_member.last_name)) AS leader_member_name,
           primary_member.email AS leader_member_email,
           primary_member.id AS leader_member_id,
           (SELECT GROUP_CONCAT(DISTINCT COALESCE(assistant_user.name,
                    TRIM(CONCAT_WS(' ', assistant_member.first_name, assistant_member.middle_name, assistant_member.last_name)))
                    ORDER BY COALESCE(assistant_user.name, assistant_member.first_name) SEPARATOR ', ')
              FROM bible_class_leaders assistant_assignment
              LEFT JOIN users assistant_user ON assistant_user.id = assistant_assignment.user_id
              LEFT JOIN members assistant_member ON assistant_member.id = assistant_assignment.member_id
             WHERE assistant_assignment.class_id = bc.id
               AND assistant_assignment.status = 'active'
               AND assistant_assignment.leader_role = 'assistant') AS assistant_leader_names,
           church.name AS church_name,
           COALESCE(member_count.active_member_count, 0) AS active_member_count,
           " . ($capacity_rules_available ? 'COALESCE(rule.max_members, 25)' : '25') . " AS max_members,
           " . ($capacity_rules_available ? 'COALESCE(rule.enforce_limit, 1)' : '1') . " AS enforce_limit
      FROM bible_classes bc
      LEFT JOIN users legacy_user ON legacy_user.id = bc.leader_id
      LEFT JOIN bible_class_leaders primary_assignment
        ON primary_assignment.id = (
            SELECT assignment.id FROM bible_class_leaders assignment
             WHERE assignment.class_id = bc.id
               AND assignment.status = 'active'
               AND assignment.leader_role = 'primary'
             ORDER BY assignment.assigned_date DESC, assignment.id DESC LIMIT 1
        )
      LEFT JOIN users primary_user ON primary_user.id = primary_assignment.user_id
      LEFT JOIN members primary_member ON primary_member.id = primary_assignment.member_id
      LEFT JOIN churches church ON church.id = bc.church_id
      " . ($capacity_rules_available ? 'LEFT JOIN bible_class_rules rule ON rule.class_id = bc.id' : '') . "
      LEFT JOIN (
          SELECT class_id, COUNT(*) AS active_member_count
            FROM members
           WHERE class_id IS NOT NULL AND status = 'active' AND is_archived = 0
           GROUP BY class_id
      ) member_count ON member_count.class_id = bc.id
      $where
     ORDER BY church.name, bc.name, bc.id
     LIMIT ? OFFSET ?";
$stmt = $conn->prepare($bibleclassSql);
$listTypes = $queryTypes . 'ii';
$listParams = array_merge($queryParams, [$perPage, $offset]);
$stmt->bind_param($listTypes, ...$listParams);
$stmt->execute();
$bibleclasses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$churches = [];
$churchSql = $is_super_admin
    ? 'SELECT id, name FROM churches ORDER BY name'
    : 'SELECT id, name FROM churches WHERE id = ' . $actorChurchId;
$result = $conn->query($churchSql);
while ($result && ($row = $result->fetch_assoc())) $churches[] = $row;

ob_start();
?>
<link rel="stylesheet" href="<?= $h(BASE_URL) ?>/assets/css/directory-workspace.css">
<div class="container-fluid directory-workspace py-3">
    <?php if (isset($_GET['added'])): ?><div class="alert alert-success">Bible Class created successfully.</div><?php elseif (isset($_GET['updated'])): ?><div class="alert alert-success">Bible Class updated successfully.</div><?php elseif (isset($_GET['deleted'])): ?><div class="alert alert-success">Bible Class deleted successfully.</div><?php endif; ?>

    <section class="directory-hero mb-4">
        <div class="row align-items-center">
            <div class="col-lg-8"><span class="directory-eyebrow">Membership formation</span><h1><i class="fas fa-book-open mr-2"></i>Bible Class Directory</h1><p>Manage class capacity, leadership, church ownership, and member distribution from a single responsive workspace.</p></div>
            <div class="col-lg-4 directory-actions justify-content-lg-end">
                <?php if ($can_add): ?><a href="bibleclass_form.php" class="btn btn-light"><i class="fas fa-plus mr-2"></i>Add class</a><a href="bibleclass_upload.php" class="btn btn-outline-light"><i class="fas fa-file-upload mr-2"></i>Bulk upload</a><?php endif; ?>
                <?php if ($can_export): ?><div class="dropdown"><button class="btn btn-outline-light dropdown-toggle" type="button" id="classExportMenu" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><i class="fas fa-download mr-2"></i>Export</button><div class="dropdown-menu dropdown-menu-right" aria-labelledby="classExportMenu"><a class="dropdown-item" href="bibleclass_export.php?<?= $h($exportQuery) ?>&amp;format=csv"><i class="fas fa-file-csv mr-2 text-success"></i>CSV</a><a class="dropdown-item" href="bibleclass_export.php?<?= $h($exportQuery) ?>&amp;format=excel"><i class="fas fa-file-excel mr-2 text-success"></i>Excel</a><a class="dropdown-item" href="bibleclass_export.php?<?= $h($exportQuery) ?>&amp;format=pdf"><i class="fas fa-file-pdf mr-2 text-danger"></i>PDF</a><a class="dropdown-item" target="_blank" rel="noopener" href="bibleclass_export.php?<?= $h($exportQuery) ?>&amp;format=print"><i class="fas fa-print mr-2 text-primary"></i>Print</a></div></div><?php endif; ?>
            </div>
        </div>
    </section>

    <div class="row mb-4">
        <div class="col-6 col-xl-3 mb-3"><div class="metric-card"><span class="metric-label">Bible Classes</span><span class="metric-value"><?= number_format($totalClasses) ?></span><span class="metric-note">Matching the current scope</span></div></div>
        <div class="col-6 col-xl-3 mb-3"><div class="metric-card"><span class="metric-label">Active members</span><span class="metric-value text-primary"><?= number_format($totalMembers) ?></span><span class="metric-note">Assigned across listed classes</span></div></div>
        <div class="col-6 col-xl-3 mb-3"><div class="metric-card"><span class="metric-label">At capacity</span><span class="metric-value <?= $fullClasses ? 'text-danger' : 'text-success' ?>"><?= number_format($fullClasses) ?></span><span class="metric-note">Enforced classes with no slots</span></div></div>
        <div class="col-6 col-xl-3 mb-3"><div class="metric-card"><span class="metric-label">Leader gaps</span><span class="metric-value <?= $withoutLeaders ? 'text-warning' : 'text-success' ?>"><?= number_format($withoutLeaders) ?></span><span class="metric-note">Classes needing a primary leader</span></div></div>
    </div>

    <form method="get" class="filter-card mb-4"><div class="form-row align-items-end">
        <?php if ($is_super_admin): ?><div class="form-group col-lg-3 col-md-5 mb-2"><label for="church_id">Church scope</label><select class="form-control" id="church_id" name="church_id"><option value="">All churches</option><?php foreach ($churches as $church): ?><option value="<?= (int) $church['id'] ?>" <?= $filterChurchId === (int) $church['id'] ? 'selected' : '' ?>><?= $h($church['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="form-group col-lg-4 col-md-5 mb-2"><label for="class-search">Search directory</label><input class="form-control" id="class-search" name="q" value="<?= $h($search) ?>" placeholder="Class name, code or church"></div>
        <div class="form-group col-lg-2 col-md-2 mb-2"><label for="per-page">Rows</label><select class="form-control" id="per-page" name="per_page"><?php foreach ([10,25,50,100] as $size): ?><option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?></select></div>
        <div class="form-group col-lg-3 mb-2"><button type="submit" class="btn btn-primary mr-2"><i class="fas fa-filter mr-1"></i>Apply</button><a href="bibleclass_list.php" class="btn btn-outline-secondary"><i class="fas fa-undo mr-1"></i>Reset</a></div>
    </div></form>

    <section class="directory-card mb-4">
        <header class="directory-card-header"><div><h2 class="directory-card-title">Class register</h2><small class="text-muted">Search and navigate the authorized directory without loading every class at once.</small></div><span class="chip chip-muted"><i class="fas fa-shield-alt mr-1"></i>Church scoped</span></header>
        <div class="table-responsive"><table class="table directory-table" id="bibleclassTable"><thead><tr><th>Class</th><th>Members &amp; capacity</th><th>Leadership</th><th>Church</th><?php if ($can_edit || $can_delete): ?><th>Actions</th><?php endif; ?></tr></thead><tbody>
        <?php if (!$bibleclasses): ?><tr><td colspan="<?= ($can_edit || $can_delete) ? 5 : 4 ?>"><div class="empty-state"><i class="fas fa-book-open fa-2x mb-2"></i><div>No Bible Classes match the current filters.</div></div></td></tr><?php endif; ?>
        <?php foreach ($bibleclasses as $row):
            $activeMembers = (int) $row['active_member_count'];
            $maxMembers = max(1, (int) $row['max_members']);
            $enforceLimit = (int) $row['enforce_limit'] === 1;
            $remainingSlots = max(0, $maxMembers - $activeMembers);
            $capacityPercentage = min(100, (int) round(($activeMembers / $maxMembers) * 100));
            $hasLeader = !empty($row['leader_user_id']) || !empty($row['leader_member_id']);
        ?><tr>
            <td data-label="Class"><div class="identity-name"><?= $h($row['name']) ?></div><span class="identity-meta">Code <?= $h($row['code'] ?: 'not assigned') ?></span></td>
            <td data-label="Members and capacity"><strong><?= number_format($activeMembers) ?><?= $enforceLimit ? ' / ' . number_format($maxMembers) : '' ?></strong><?php if ($enforceLimit): ?><span class="chip <?= $remainingSlots === 0 ? 'chip-warning' : 'chip-muted' ?> ml-1"><?= $remainingSlots === 0 ? 'Full' : $remainingSlots . ' open' ?></span><div class="progress-thin" aria-label="<?= $capacityPercentage ?> percent capacity"><span style="width:<?= $capacityPercentage ?>%"></span></div><?php else: ?><span class="identity-meta">Capacity limit not enforced</span><?php endif; ?></td>
            <td data-label="Leadership">
                <?php if (!$hasLeader): ?><span class="chip chip-warning">No primary leader</span><?php if ($can_edit): ?><button type="button" class="btn btn-sm btn-outline-success assign-leader-btn" data-class-id="<?= (int) $row['id'] ?>" data-church-id="<?= (int) $row['church_id'] ?>" data-leader-role="primary"><i class="fas fa-user-plus mr-1"></i>Assign</button><?php endif; ?>
                <?php else: ?><div class="identity-name"><?= $h($row['leader_name'] ?: $row['leader_member_name'] ?: 'Unknown leader') ?></div><span class="identity-meta"><?= $h($row['leader_email'] ?: $row['leader_member_email'] ?: 'No email') ?><?= !empty($row['leader_member_id']) ? ' · Member assignment' : '' ?></span><?php if ($can_edit): ?><div class="row-actions mt-2"><button type="button" class="btn btn-sm btn-outline-primary assign-leader-btn" data-class-id="<?= (int) $row['id'] ?>" data-church-id="<?= (int) $row['church_id'] ?>" data-leader-role="primary"><i class="fas fa-user-edit mr-1"></i>Change</button><button type="button" class="btn btn-sm btn-outline-danger remove-leader-btn" data-class-id="<?= (int) $row['id'] ?>" data-leader-role="primary"><i class="fas fa-user-times"></i></button></div><?php endif; ?><?php endif; ?>
                <?php if (!empty($row['assistant_leader_names'])): ?><span class="identity-meta mt-2"><strong>Assistant:</strong> <?= $h($row['assistant_leader_names']) ?><?php if ($can_edit): ?> <button type="button" class="btn btn-sm btn-link text-danger remove-leader-btn p-0" data-class-id="<?= (int) $row['id'] ?>" data-leader-role="assistant" title="Remove assistant leader"><i class="fas fa-user-times"></i></button><?php endif; ?></span><?php endif; ?>
                <?php if ($can_edit): ?><button type="button" class="btn btn-sm btn-link px-0 assign-leader-btn" data-class-id="<?= (int) $row['id'] ?>" data-church-id="<?= (int) $row['church_id'] ?>" data-leader-role="assistant"><i class="fas fa-user-plus mr-1"></i>Add assistant</button><?php endif; ?>
            </td>
            <td data-label="Church"><?= $h($row['church_name'] ?: 'Not assigned') ?></td>
            <?php if ($can_edit || $can_delete): ?><td data-label="Actions"><div class="row-actions"><?php if ($can_edit): ?><a href="bibleclass_form.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit class"><i class="fas fa-edit"></i></a><?php endif; ?><?php if ($can_delete): ?><form method="post" action="bibleclass_delete.php" onsubmit="return confirm('Delete this Bible Class?');"><?= csrf_input() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-danger" title="Delete class"><i class="fas fa-trash"></i></button></form><?php endif; ?></div></td><?php endif; ?>
        </tr><?php endforeach; ?>
        </tbody></table></div>
        <footer class="directory-footer d-flex flex-column flex-md-row justify-content-between align-items-md-center">
            <small class="text-muted mb-2 mb-md-0"><?php if ($totalClasses): ?>Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $totalClasses)) ?> of <?= number_format($totalClasses) ?> classes<?php else: ?>No classes found<?php endif; ?></small>
            <?php if ($totalPages > 1): ?><nav aria-label="Bible Class directory pages"><ul class="pagination pagination-sm mb-0">
                <?php $previousQuery = array_merge($_GET, ['page' => max(1, $page - 1), 'per_page' => $perPage]); ?><li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="?<?= $h(http_build_query($previousQuery)) ?>" aria-label="Previous page"><i class="fas fa-chevron-left"></i></a></li>
                <?php if ($page > 3): ?><li class="page-item"><a class="page-link" href="?<?= $h(http_build_query(array_merge($_GET, ['page'=>1,'per_page'=>$perPage]))) ?>">1</a></li><?php if ($page > 4): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?><?php endif; ?>
                <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?><li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= $h(http_build_query(array_merge($_GET, ['page'=>$p,'per_page'=>$perPage]))) ?>"><?= $p ?></a></li><?php endfor; ?>
                <?php if ($page < $totalPages - 2): ?><?php if ($page < $totalPages - 3): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?><li class="page-item"><a class="page-link" href="?<?= $h(http_build_query(array_merge($_GET, ['page'=>$totalPages,'per_page'=>$perPage]))) ?>"><?= $totalPages ?></a></li><?php endif; ?>
                <?php $nextQuery = array_merge($_GET, ['page' => min($totalPages, $page + 1), 'per_page' => $perPage]); ?><li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="?<?= $h(http_build_query($nextQuery)) ?>" aria-label="Next page"><i class="fas fa-chevron-right"></i></a></li>
            </ul></nav><?php endif; ?>
        </footer>
    </section>
</div>

<?php ob_start(); ?>
<div class="modal fade" id="assignLeaderModal" tabindex="-1" role="dialog" aria-labelledby="assignLeaderModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title" id="assignLeaderModalLabel">Assign Bible Class Leader</h5><small class="text-muted">Search within the selected class church.</small></div><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>
    <form id="assignLeaderForm" method="post"><div class="modal-body"><?= csrf_input() ?><input type="hidden" name="class_id" id="modal-class-id"><div class="form-group"><label for="bible-leader-role">Assignment type</label><select class="form-control" id="bible-leader-role" name="leader_role"><option value="primary">Primary Leader</option><option value="assistant">Assistant Leader</option></select></div><div class="form-group"><label for="leader-user-id">User or member</label><select class="form-control" id="leader-user-id" name="leader_user_id" style="width:100%" required></select><small class="form-text text-muted">Accounts require the matching role. Members without accounts may be assigned contextually.</small></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success"><i class="fas fa-user-check mr-1"></i>Save assignment</button></div></form>
  </div></div>
</div>
<?php $modal_html = ob_get_clean(); ?>

<script>
$(function(){
    $('.remove-leader-btn').on('click',function(){
        var classId=$(this).data('class-id'),leaderRole=$(this).data('leader-role')||'primary';
        if(!confirm('Remove the '+leaderRole+' leader from this class?'))return;
        $.post('bibleclass_remove_leader.php',{class_id:classId,leader_role:leaderRole,csrf_token:<?= json_encode(csrf_token()) ?>},function(resp){if(resp.success){location.reload();}else{alert(resp.error||'Failed to remove leader.');}},'json').fail(function(xhr){alert('Unable to remove the leader. '+(xhr.responseJSON&&xhr.responseJSON.error?xhr.responseJSON.error:'Please retry.'));});
    });

    $('.assign-leader-btn').on('click',function(){
        var classId=$(this).data('class-id'),churchId=$(this).data('church-id'),leaderRole=$(this).data('leader-role')||'primary';
        $('#modal-class-id').val(classId);$('#bible-leader-role').val(leaderRole);$('#leader-user-id').val(null).trigger('change');
        $('#leader-user-id').select2({dropdownParent:$('#assignLeaderModal'),placeholder:'Search for a user or member',minimumInputLength:1,ajax:{url:'ajax_users_by_church.php',dataType:'json',delay:250,data:function(params){return{q:params.term,church_id:churchId,class_id:classId,leader_role:$('#bible-leader-role').val()};},processResults:function(data){return{results:data.results||[]};},cache:true}});
        $('#assignLeaderModal').modal('show');
    });

    $('#assignLeaderForm').on('submit',function(event){
        event.preventDefault();var form=$(this),button=form.find('button[type="submit"]');button.prop('disabled',true);
        $.post('bibleclass_assign_leader.php',form.serialize(),function(resp){if(resp.success){location.reload();}else{button.prop('disabled',false);alert(resp.error||'Unable to save the assignment.');}},'json').fail(function(xhr){button.prop('disabled',false);alert('Unable to save the assignment. '+(xhr.responseJSON&&xhr.responseJSON.error?xhr.responseJSON.error:'Please retry.'));});
    });
});
</script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
