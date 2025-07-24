<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_health_list')) {
    http_response_code(403);
    if (file_exists(__DIR__.'/errors/403.php')) {
        include __DIR__.'/errors/403.php';
    } else if (file_exists(dirname(__DIR__).'/views/errors/403.php')) {
        include dirname(__DIR__).'/views/errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    }
    exit;
}

// Set permission flags for UI elements
$can_add = $is_super_admin || has_permission('create_health');
$can_edit = $is_super_admin || has_permission('edit_health');
$can_delete = $is_super_admin || has_permission('delete_health');
$can_view = true; // Already validated above

// Filters
$church_id = isset($_GET['church_id']) ? intval($_GET['church_id']) : '';
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : '';
$organization_id = isset($_GET['organization_id']) ? intval($_GET['organization_id']) : '';
$member = trim($_GET['member'] ?? '');
$recorded_by = trim($_GET['recorded_by'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$where = [];
$params = [];
$types = '';
if ($member !== '') {
    $where[] = "((m.first_name LIKE CONCAT('%', ?, '%') OR m.last_name LIKE CONCAT('%', ?, '%') OR m.crn LIKE CONCAT('%', ?, '%'))
        OR (ss.first_name LIKE CONCAT('%', ?, '%') OR ss.last_name LIKE CONCAT('%', ?, '%') OR ss.srn LIKE CONCAT('%', ?, '%'))
    )";
    for ($i = 0; $i < 6; $i++) $params[] = $member;
    $types .= 'ssssss';
}
if ($recorded_by !== '') {
    $where[] = "u.name LIKE CONCAT('%', ?, '%')";
    $params[] = $recorded_by;
    $types .= 's';
}
if ($date_from !== '') {
    $where[] = "hr.recorded_at >= ?";
    $params[] = $date_from . ' 00:00:00';
    $types .= 's';
}
if ($date_to !== '') {
    $where[] = "hr.recorded_at <= ?";
    $params[] = $date_to . ' 23:59:59';
    $types .= 's';
}
$join_member_org = '';
if ($organization_id) {
    $join_member_org = 'INNER JOIN member_organizations mo ON mo.member_id = m.id';
    $where[] = "mo.organization_id = ?";
    $params[] = $organization_id;
    $types .= 'i';
}
if ($church_id) {
    $where[] = "m.church_id = ?";
    $params[] = $church_id;
    $types .= 'i';
}
if ($class_id) {
    $where[] = "m.class_id = ?";
    $params[] = $class_id;
    $types .= 'i';
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Count total
$sql_count = "SELECT COUNT(*) FROM health_records hr LEFT JOIN members m ON hr.member_id = m.id LEFT JOIN sunday_school ss ON hr.sundayschool_id = ss.id LEFT JOIN users u ON hr.recorded_by = u.id $join_member_org $where_sql";
$stmt = $conn->prepare($sql_count);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$stmt->bind_result($total);
$stmt->fetch();
$stmt->close();
$total_pages = max(1, ceil($total/$per_page));
$offset = ($page-1)*$per_page;
// Fetch records
// Consolidated: Only latest record per member
$sql = "SELECT hr.id, hr.member_id, hr.sundayschool_id, m.crn, m.first_name AS m_first_name, m.last_name AS m_last_name, m.middle_name AS m_middle_name, ss.srn, ss.first_name AS ss_first_name, ss.last_name AS ss_last_name, ss.middle_name AS ss_middle_name, hr.vitals, hr.notes, hr.recorded_at, u.name AS recorded_by
FROM health_records hr
LEFT JOIN members m ON hr.member_id = m.id
LEFT JOIN sunday_school ss ON hr.sundayschool_id = ss.id
LEFT JOIN users u ON hr.recorded_by = u.id
$join_member_org
$where_sql
ORDER BY hr.recorded_at DESC
LIMIT $per_page OFFSET $offset";
$stmt = $conn->prepare($sql);
if ($types && count($params) > 0) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0 text-gray-800"><i class="fas fa-heartbeat mr-2"></i>Health Records</h1>
    <?php if ($can_add): ?>
        <a href="health_form.php" class="btn btn-primary"><i class="fas fa-plus mr-1"></i> Add New Record</a>
    <?php endif; ?>
</div>
<div class="card shadow mb-4">
    <div class="card-header py-3 bg-info text-white">
        <h6 class="m-0 font-weight-bold">Search & Filter</h6>
        <button class="btn btn-link text-white float-right p-0" type="button" data-toggle="collapse" data-target="#filterCard" aria-expanded="false" aria-controls="filterCard"><i class="fas fa-filter"></i></button>
    </div>
    <div class="collapse show" id="filterCard">
        <div class="card-body pb-2">
            <form class="form-row" method="get" id="filterForm">
                <div class="form-group col-md-2 mb-2">
                    <select class="form-control" name="church_id" id="church_id">
                        <option value="">-- Select Church --</option>
                        <?php
                        $churches = $conn->query("SELECT id, name FROM churches ORDER BY name ASC");
                        $selected_church_id = isset($_GET['church_id']) ? intval($_GET['church_id']) : '';
                        if ($churches && $churches->num_rows > 0):
                            while($c = $churches->fetch_assoc()): ?>
                                <option value="<?= $c['id'] ?>" <?= ($selected_church_id==$c['id']?'selected':'') ?>><?= htmlspecialchars($c['name']) ?></option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>
                <div class="form-group col-md-2 mb-2">
                    <select class="form-control" name="class_id" id="class_id">
                        <option value="">-- Select Class --</option>
                    </select>
                </div>
                <div class="form-group col-md-2 mb-2">
                    <select class="form-control" name="organization_id" id="organization_id">
                        <option value="">-- Select Organization --</option>
                    </select>
                </div>

                <div class="form-group col-md-2 mb-2">
                    <input type="text" class="form-control" name="member" placeholder="Member Name or CRN" value="<?= htmlspecialchars($member) ?>">
                </div>
                <div class="form-group col-md-2 mb-2">
                    <input type="text" class="form-control" name="recorded_by" placeholder="Recorded By" value="<?= htmlspecialchars($recorded_by) ?>">
                </div>
                <div class="form-group col-md-1 mb-2">
                    <input type="date" class="form-control" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="form-group col-md-1 mb-2">
                    <input type="date" class="form-control" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="form-group col-md-2 mb-2 d-flex align-items-center">
                    <button type="submit" class="btn btn-info mr-2"><i class="fas fa-search"></i> Search</button>
                    <a href="health_list.php" class="btn btn-secondary"><i class="fas fa-times"></i></a>
                </div>
            </form>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                function loadClasses(churchId, selectedClassId = '') {
                    var classSelect = document.getElementById('class_id');
                    classSelect.innerHTML = '<option value="">-- Select Class --</option>';
                    if (churchId) {
                        fetch('ajax_get_classes_by_church.php?church_id=' + churchId)
                            .then(res => res.text())
                            .then(html => {
                                classSelect.innerHTML = html;
                                if (selectedClassId) classSelect.value = selectedClassId;
                            });
                    }
                }

                function loadOrganizations(churchId, selectedOrgId = '') {
                    var orgSelect = document.getElementById('organization_id');
                    orgSelect.innerHTML = '<option value="">-- Select Organization --</option>';
                    if (churchId) {
                        fetch('ajax_get_organizations_by_church.php?church_id=' + churchId)
                            .then(res => res.text())
                            .then(html => {
                                orgSelect.innerHTML = '<option value="">-- Select Organization --</option>' + html;
                                if (selectedOrgId) orgSelect.value = selectedOrgId;
                            });
                    }
                }
                var churchSelect = document.getElementById('church_id');
                var classSelect = document.getElementById('class_id');
                var orgSelect = document.getElementById('organization_id');

                // On page load, pre-populate class if church is set
                var selectedChurchId = '<?= isset($_GET['church_id']) ? intval($_GET['church_id']) : '' ?>';
                var selectedClassId = '<?= isset($_GET['class_id']) ? intval($_GET['class_id']) : '' ?>';
                var selectedOrgId = '<?= isset($_GET['organization_id']) ? intval($_GET['organization_id']) : '' ?>';

                if (selectedChurchId) {
                    loadClasses(selectedChurchId, selectedClassId);
                    loadOrganizations(selectedChurchId, selectedOrgId);
                }
                churchSelect.addEventListener('change', function() {
                    loadClasses(this.value);
                    loadOrganizations(this.value);

                });
            });
            </script>
        </div>
    </div>
</div>
<div class="card shadow mb-4">
    <div class="card-header py-3 bg-primary text-white">
        <h6 class="m-0 font-weight-bold">Health Records List</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="thead-light">
                    <tr>
                        <th>CRN/SRN</th>
                        <th>Type</th>
                        <th>Full Name</th>
                        <th>Notes</th>
                        <th>Last Visit</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($result && $result->num_rows > 0): while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['member_id'] ? htmlspecialchars($row['crn']) : htmlspecialchars($row['srn']) ?></td>
                        <td><?= $row['member_id'] ? 'Member' : 'Child' ?></td>
                        <td>
                            <?php if ($row['member_id']): ?>
                                <?= htmlspecialchars(trim(($row['m_last_name'] ?? '').' '.($row['m_first_name'] ?? '').' '.($row['m_middle_name'] ?? ''))) ?>
                            <?php else: ?>
                                <?= htmlspecialchars(trim(($row['ss_last_name'] ?? '').' '.($row['ss_first_name'] ?? '').' '.($row['ss_middle_name'] ?? ''))) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars(mb_strimwidth($row['notes'],0,30,'...')) ?></td>
                        <td><?= htmlspecialchars($row['recorded_at']) ?></td>
                        <td>
                            <a href="health_records.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-info" title="View History"><i class="fas fa-eye"></i> View History</a>
                            <?php if ($can_edit): ?>
                                <a href="health_form.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                            <?php endif; ?>
                            <a href="health_records.php?id=<?= $row['id'] ?>&print=1" class="btn btn-sm btn-secondary" title="Print/PDF" target="_blank"><i class="fas fa-print"></i></a>
                            <?php if ($can_delete): ?>
                                <a href="#" class="btn btn-sm btn-danger" title="Delete" onclick="return confirm('Delete this health record?');"><i class="fas fa-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="5" class="text-center">No records found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <!-- Pagination -->
        <nav aria-label="Page navigation">
            <ul class="pagination justify-content-center">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item<?= $i == $page ? ' active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"> <?= $i ?> </a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require_once __DIR__.'/../includes/layout.php';
