<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_sunday_school_list')) {
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
$can_add = $is_super_admin || has_permission('create_sunday_school');
$can_edit = $is_super_admin || has_permission('edit_sunday_school');
$can_delete = $is_super_admin || has_permission('delete_sunday_school');
$can_view = true; // Already validated above

// Build WHERE clause with filters
$where_conditions = [];
$params = [];
$param_types = "";

// Search filter
if (!empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $where_conditions[] = "(CONCAT(ss.last_name, ' ', ss.first_name, ' ', ss.middle_name) LIKE ? OR ss.srn LIKE ? OR ss.contact LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $param_types .= "sss";
}

// Church filter
if (!empty($_GET['church_id'])) {
    $where_conditions[] = "ss.church_id = ?";
    $params[] = $_GET['church_id'];
    $param_types .= "i";
}

// Bible class filter
if (!empty($_GET['class_id'])) {
    $where_conditions[] = "ss.class_id = ?";
    $params[] = $_GET['class_id'];
    $param_types .= "i";
}

// Gender filter
if (!empty($_GET['gender'])) {
    $where_conditions[] = "ss.gender = ?";
    $params[] = $_GET['gender'];
    $param_types .= "s";
}

// Status filter
if (!empty($_GET['status_filter'])) {
    if ($_GET['status_filter'] === 'active') {
        $where_conditions[] = "(ss.transferred_at IS NULL OR ss.transferred_at = '')";
    } elseif ($_GET['status_filter'] === 'transferred') {
        $where_conditions[] = "ss.transferred_at IS NOT NULL AND ss.transferred_at != ''";
    }
}

// Build the WHERE clause
$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Pagination setup
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$allowed_sizes = [20,40,60,80,100,'all'];
$page_size = isset($_GET['page_size']) && in_array($_GET['page_size'], $allowed_sizes) ? $_GET['page_size'] : 20;

// Count total records with filters
$count_sql = "SELECT COUNT(*) as cnt FROM sunday_school ss $where_clause";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($param_types, ...$params);
}
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_assoc()['cnt'];
// Build main query with filters
$base_sql = "SELECT ss.*, 
               CASE 
                   WHEN ss.father_is_member = 'yes' AND fm.id IS NOT NULL 
                   THEN CONCAT(fm.last_name, ' ', fm.first_name, ' ', COALESCE(fm.middle_name, ''))
                   ELSE ss.father_name 
               END as display_father_name,
               CASE 
                   WHEN ss.mother_is_member = 'yes' AND mm.id IS NOT NULL 
                   THEN CONCAT(mm.last_name, ' ', mm.first_name, ' ', COALESCE(mm.middle_name, ''))
                   ELSE ss.mother_name 
               END as display_mother_name
        FROM sunday_school ss 
        LEFT JOIN members fm ON ss.father_member_id = fm.id AND ss.father_is_member = 'yes'
        LEFT JOIN members mm ON ss.mother_member_id = mm.id AND ss.mother_is_member = 'yes'
        $where_clause
        ORDER BY ss.last_name, ss.first_name, ss.srn";

if (is_numeric($page_size)) {
    $offset = ($page - 1) * intval($page_size);
    $total_pages = ceil($total_records / intval($page_size));
    $sql = $base_sql . " LIMIT $offset, $page_size";
} else {
    $offset = 0;
    $total_pages = 1;
    $page = 1;
    $sql = $base_sql;
}

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$sundayschool = $stmt->get_result();

ob_start();
?>
<style>
.filter-section {
    background: rgba(102, 126, 234, 0.05);
    border-radius: 15px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    border: 1px solid #e3f2fd;
}

.sunday-school-header {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    padding: 2rem;
    border-radius: 15px;
    margin-bottom: 2rem;
}

.sunday-school-title {
    font-size: 2.5rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.stats-card {
    background: rgba(255,255,255,0.2);
    border-radius: 15px;
    padding: 1.5rem;
    backdrop-filter: blur(10px);
}

.table-responsive {
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
}

.table thead {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
}

.table thead th {
    border: none;
    font-weight: 600;
    padding: 1rem 0.75rem;
    font-size: 0.9rem;
}

.table tbody tr:hover {
    background-color: #f8f9fa;
    transform: translateY(-1px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}
</style>

<div class="card shadow mb-4">
    <div class="sunday-school-header">
        <div class="row align-items-center">
            <div class="col-lg-6 col-md-12 mb-3 mb-lg-0">
                <h1 class="sunday-school-title mb-2">
                    <i class="fas fa-child mr-3"></i>
                    Sunday School
                </h1>
                <p class="mb-0" style="font-size: 1.1rem; opacity: 0.9;">
                    Manage and view all Sunday School children
                </p>
            </div>
            <div class="col-lg-6 col-md-12">
                <div class="d-flex flex-wrap align-items-center justify-content-lg-end">
                    <?php if ($can_add): ?>
                        <a href="sundayschool_form.php" class="btn btn-light btn-lg mr-2 mb-2">
                            <i class="fas fa-plus mr-2"></i>Add Child
                        </a>
                    <?php endif; ?>
                    <div class="stats-card mb-2">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-chart-bar fa-2x mr-3"></i>
                            <div>
                                <div class="h4 mb-0"><?= number_format($total_records) ?></div>
                                <small>Total Children</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="card-body">
        <!-- Search and Filter Section -->
        <div class="filter-section">
            <h5 class="mb-4">
                <button class="btn btn-link p-0 text-dark font-weight-bold" type="button" data-toggle="collapse" data-target="#filterCollapse" aria-expanded="false" aria-controls="filterCollapse" style="text-decoration: none;">
                    <i class="fas fa-filter mr-2"></i>Search & Filter Options
                    <i class="fas fa-chevron-down ml-2 text-muted" id="filterToggleIcon"></i>
                </button>
            </h5>
            
            <div class="collapse" id="filterCollapse">
                <form method="get" class="mb-4">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label for="search" class="form-label font-weight-bold">Search</label>
                            <input type="text" class="form-control" id="search" name="search" 
                                   value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" 
                                   placeholder="Name, SRN, or Contact">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label for="church_id" class="form-label font-weight-bold">Church</label>
                            <select class="form-control" id="church_id" name="church_id">
                                <option value="">All Churches</option>
                                <?php
                                $churches_query = "SELECT id, name FROM churches ORDER BY name";
                                $churches_result = $conn->query($churches_query);
                                while ($church = $churches_result->fetch_assoc()):
                                ?>
                                    <option value="<?= $church['id'] ?>" <?= (isset($_GET['church_id']) && $_GET['church_id'] == $church['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($church['name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mb-3">
                            <label for="class_id" class="form-label font-weight-bold">Bible Class</label>
                            <select class="form-control" id="class_id" name="class_id">
                                <option value="">All Classes</option>
                                <?php
                                $classes_query = "SELECT id, name FROM bible_classes ORDER BY name";
                                $classes_result = $conn->query($classes_query);
                                while ($class = $classes_result->fetch_assoc()):
                                ?>
                                    <option value="<?= $class['id'] ?>" <?= (isset($_GET['class_id']) && $_GET['class_id'] == $class['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($class['name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mb-3">
                            <label for="gender" class="form-label font-weight-bold">Gender</label>
                            <select class="form-control" id="gender" name="gender">
                                <option value="">All Genders</option>
                                <option value="Male" <?= (isset($_GET['gender']) && $_GET['gender'] == 'Male') ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= (isset($_GET['gender']) && $_GET['gender'] == 'Female') ? 'selected' : '' ?>>Female</option>
                            </select>
                        </div>
                        <div class="col-md-2 mb-3">
                            <label for="status_filter" class="form-label font-weight-bold">Status</label>
                            <select class="form-control" id="status_filter" name="status_filter">
                                <option value="">All Status</option>
                                <option value="active" <?= (isset($_GET['status_filter']) && $_GET['status_filter'] == 'active') ? 'selected' : '' ?>>Active</option>
                                <option value="transferred" <?= (isset($_GET['status_filter']) && $_GET['status_filter'] == 'transferred') ? 'selected' : '' ?>>Transferred</option>
                            </select>
                        </div>
                        <div class="col-md-2 mb-3">
                            <label for="page_size" class="form-label font-weight-bold">Per Page</label>
                            <select class="form-control" id="page_size" name="page_size">
                                <?php foreach($allowed_sizes as $size): ?>
                                    <option value="<?= $size ?>" <?= $page_size == $size ? 'selected' : '' ?>>
                                        <?= $size == 'all' ? 'All' : $size ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-1 mb-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary btn-block">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                        <div class="col-md-1 mb-3 d-flex align-items-end">
                            <a href="sundayschool_list.php" class="btn btn-secondary btn-block" title="Clear Filters">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <div class="table-responsive">
            <table id="sundayschoolTable" class="table table-bordered table-hover" width="100%">
                <thead class="thead-light">
                    <tr>
                        <th>Photo</th>
                        <th>SRN</th>
                        <th>Full Name</th>
                        <th>DoB</th>
                        <th>Contact</th>
                        <th>School</th>
                        <th>Father</th>
                        <th>Mother</th>
                        <th>Status</th>
                <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while($row = $sundayschool->fetch_assoc()): ?>
                    <tr>
                        <td><?php if($row['photo']): ?><img src="<?=BASE_URL?>/uploads/sundayschool/<?=rawurlencode($row['photo'])?>" style="height:40px;width:40px;object-fit:cover;border-radius:8px;"/><?php endif; ?></td>
                        <td><?=htmlspecialchars($row['srn'])?></td>
                        <td><?=htmlspecialchars(trim($row['last_name'].' '.$row['middle_name'].' '.$row['first_name']))?></td>
                        <td><?php
                            $dob = $row['dob'];
                            $age = '';
                            if ($dob && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
                                $dob_dt = new DateTime($dob);
                                $now = new DateTime();
                                $years = $now->diff($dob_dt)->y;
                                $age = $years.' yrs';
                            }
                        ?>
                        <?=htmlspecialchars($dob)?><?php if($age): ?> <span class="text-muted small">(<?=$age?>)</span><?php endif; ?></td>
                        <td><?=htmlspecialchars($row['contact'])?></td>
                        <td><?=htmlspecialchars($row['school_attend'])?></td>
                        <td><?=htmlspecialchars($row['display_father_name'])?><br><small><?=htmlspecialchars($row['father_contact'])?></small></td>
                        <td><?=htmlspecialchars($row['display_mother_name'])?><br><small><?=htmlspecialchars($row['mother_contact'])?></small></td>
                        <td>
                            <?php if (!empty($row['transferred_at'])): ?>
                                <span class="badge badge-success">Transferred</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Active</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?=BASE_URL?>/views/sundayschool_view.php?id=<?=$row['id']?>" class="btn btn-sm btn-info"><i class="fa fa-eye"></i></a>
                            <?php if ($can_edit): ?>
                            <a href="<?=BASE_URL?>/views/sundayschool_form.php?id=<?=$row['id']?>" class="btn btn-sm btn-warning"><i class="fa fa-edit"></i></a>
                            <?php endif; ?>
                            <?php if ($can_delete): ?>
                            <a href="<?=BASE_URL?>/views/sundayschool_delete.php?id=<?=$row['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this record?');"><i class="fa fa-trash"></i></a>
                            <?php endif; ?>
                            <a href="<?=BASE_URL?>/views/payment_form.php" class="btn btn-sm btn-success"><i class="fa fa-coins"></i> Payment</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <!-- Pagination controls -->
        <nav aria-label="Sunday School pagination">
            <ul class="pagination justify-content-center">
                <?php
                // Build query string for pagination links
                $query_params = $_GET;
                unset($query_params['page']); // Remove page parameter to avoid duplication
                $query_string = http_build_query($query_params);
                $query_prefix = !empty($query_string) ? '?' . $query_string . '&page=' : '?page=';
                ?>
                
                <?php if ($page > 1): ?>
                    <li class="page-item"><a class="page-link" href="<?= $query_prefix ?>1">First</a></li>
                    <li class="page-item"><a class="page-link" href="<?= $query_prefix ?><?= $page-1 ?>">&laquo; Prev</a></li>
                <?php else: ?>
                    <li class="page-item disabled"><span class="page-link">First</span></li>
                    <li class="page-item disabled"><span class="page-link">&laquo; Prev</span></li>
                <?php endif; ?>
                <?php
                $start = max(1, $page-2);
                $end = min($total_pages, $page+2);
                for ($i = $start; $i <= $end; $i++):
                ?>
                    <li class="page-item<?= $i == $page ? ' active' : '' ?>">
                        <a class="page-link" href="<?= $query_prefix ?><?= $i ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <li class="page-item"><a class="page-link" href="<?= $query_prefix ?><?= $page+1 ?>">Next &raquo;</a></li>
                    <li class="page-item"><a class="page-link" href="<?= $query_prefix ?><?= $total_pages ?>">Last</a></li>
                <?php else: ?>
                    <li class="page-item disabled"><span class="page-link">Next &raquo;</span></li>
                    <li class="page-item disabled"><span class="page-link">Last</span></li>
                <?php endif; ?>
            </ul>
            <div class="text-center text-muted small mb-3">Page <?= $page ?> of <?= $total_pages ?> (<?= $total_records ?> records)</div>
        </nav>
    </div>
</div>
<?php $page_content = ob_get_clean(); include '../includes/layout.php'; ?>

<!-- DataTables Buttons dependencies (JS & CSS) -->
<link rel="stylesheet" href="<?= BASE_URL ?>/AdminLTE/plugins/datatables-buttons/css/buttons.bootstrap4.min.css">
<script src="<?= BASE_URL ?>/AdminLTE/plugins/datatables-buttons/js/dataTables.buttons.min.js"></script>
<script src="<?= BASE_URL ?>/AdminLTE/plugins/datatables-buttons/js/buttons.bootstrap4.min.js"></script>
<script src="<?= BASE_URL ?>/AdminLTE/plugins/datatables-buttons/js/buttons.html5.min.js"></script>
<script src="<?= BASE_URL ?>/AdminLTE/plugins/datatables-buttons/js/buttons.print.min.js"></script>
<script src="<?= BASE_URL ?>/AdminLTE/plugins/datatables-buttons/js/buttons.colVis.min.js"></script>

<script>
    var BASE_URL = "<?= addslashes(BASE_URL) ?>";
</script>
<script>
$(document).ready(function() {
    // Handle collapsible filter section
    $('#filterCollapse').on('show.bs.collapse', function () {
        $('#filterToggleIcon').removeClass('fa-chevron-down').addClass('fa-chevron-up');
    });

    $('#filterCollapse').on('hide.bs.collapse', function () {
        $('#filterToggleIcon').removeClass('fa-chevron-up').addClass('fa-chevron-down');
    });
    
    // DataTable for export only, disable paging/filtering
    if (!$.fn.DataTable.isDataTable('#sundayschoolTable')) {
        try {
            $('#sundayschoolTable').DataTable({
                dom: 'Bfrtip',
                paging: false,
                searching: false,
                info: false,
                ordering: true,
                buttons: [
                    'copy', 'csv', 'excel', 'pdf', 'print'
                ]
            });
        } catch (e) {
            console.error('DataTable init failed:', e);
        }
    }
    
    // Smooth scroll to table after filter
    if (window.location.search.includes('search=') || window.location.search.includes('status_filter=')) {
        $('html, body').animate({
            scrollTop: $('.table-responsive').offset().top - 100
        }, 500);
    }
});
</script>
<!-- <script src="<?= BASE_URL ?>/assets/js/sundayschool_list.js"></script> -->
