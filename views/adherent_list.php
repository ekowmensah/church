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

if (!$is_super_admin && !has_permission('view_member')) {
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
$can_add = $is_super_admin || has_permission('create_member');
$can_edit = $is_super_admin || has_permission('edit_member');
$can_delete = $is_super_admin || has_permission('delete_member');
$can_view = true; // Already validated above

// Modal accumulator for all modals in this file
$modal_html = '';

// Pagination setup
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$allowed_sizes = [20,40,60,80,100,'all'];
$page_size = isset($_GET['page_size']) && (in_array($_GET['page_size'], $allowed_sizes)) ? $_GET['page_size'] : 20;

// Build WHERE clause with filters
$where_conditions = ["m.status = 'active'", "m.membership_status = 'Adherent'"];
$params = [];
$param_types = "";

// Search filter
if (!empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $where_conditions[] = "(CONCAT(m.last_name, ' ', m.first_name, ' ', m.middle_name) LIKE ? OR m.crn LIKE ? OR m.phone LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $param_types .= "sss";
}

// Church filter
if (!empty($_GET['church_id'])) {
    $where_conditions[] = "m.church_id = ?";
    $params[] = $_GET['church_id'];
    $param_types .= "i";
}

// Bible class filter
if (!empty($_GET['class_id'])) {
    $where_conditions[] = "m.class_id = ?";
    $params[] = $_GET['class_id'];
    $param_types .= "i";
}

// Gender filter
if (!empty($_GET['gender'])) {
    $where_conditions[] = "m.gender = ?";
    $params[] = $_GET['gender'];
    $param_types .= "s";
}

// Build the main query
$where_clause = implode(' AND ', $where_conditions);

// Count total adherents for pagination
$count_sql = "SELECT COUNT(*) as total FROM members m WHERE $where_clause";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($param_types, ...$params);
}
$count_stmt->execute();
$total_adherents = $count_stmt->get_result()->fetch_assoc()['total'];

// Calculate pagination
$total_pages = ($page_size === 'all') ? 1 : ceil($total_adherents / $page_size);
$offset = ($page_size === 'all') ? 0 : ($page - 1) * $page_size;
$limit_clause = ($page_size === 'all') ? '' : "LIMIT $page_size OFFSET $offset";

// Main query to get adherents with their latest adherent record
$sql = "
    SELECT 
        m.id, m.crn, m.last_name, m.first_name, m.middle_name, m.phone, m.gender, 
        m.day_born, m.photo, m.membership_status, m.status,
        c.name as church_name,
        cl.name as class_name,
        a.date_became_adherent,
        a.reason as adherent_reason,
        u.name as marked_by_name,
        a.created_at as adherent_created_at
    FROM members m
    LEFT JOIN churches c ON m.church_id = c.id
    LEFT JOIN bible_classes cl ON m.class_id = cl.id
    LEFT JOIN (
        SELECT a1.member_id, a1.date_became_adherent, a1.reason, a1.marked_by, a1.created_at
        FROM adherents a1
        INNER JOIN (
            SELECT member_id, MAX(created_at) as max_created
            FROM adherents
            GROUP BY member_id
        ) a2 ON a1.member_id = a2.member_id AND a1.created_at = a2.max_created
    ) a ON m.id = a.member_id
    LEFT JOIN users u ON a.marked_by = u.id
    WHERE $where_clause
    ORDER BY a.created_at DESC, m.last_name ASC, m.first_name ASC
    $limit_clause
";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$adherents = $stmt->get_result();

ob_start();
?>

<style>
.adherent-header {
    background: linear-gradient(135deg, #8e44ad, #9b59b6);
    color: white;
    padding: 2rem;
    border-radius: 15px;
    margin-bottom: 2rem;
}

.adherent-title {
    font-size: 2.5rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.adherent-subtitle {
    font-size: 1.1rem;
    opacity: 0.9;
}

.adherent-card {
    border: none;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.stats-card {
    background: rgba(255,255,255,0.2);
    border-radius: 15px;
    padding: 1.5rem;
    backdrop-filter: blur(10px);
    transition: transform 0.3s ease;
}

.stats-card:hover {
    transform: translateY(-5px);
}

.adherent-table {
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
}

.adherent-table thead {
    background: linear-gradient(135deg, #8e44ad, #9b59b6);
    color: white;
}

.adherent-table thead th {
    border: none;
    font-weight: 600;
    padding: 1rem 0.75rem;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.adherent-table tbody tr:hover {
    background-color: #f8f9fa;
    transform: translateY(-1px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.adherent-table tbody td {
    padding: 1rem 0.75rem;
    vertical-align: middle;
    border: none;
}

.member-photo {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    border: 3px solid #8e44ad;
    object-fit: cover;
    box-shadow: 0 3px 10px rgba(142, 68, 173, 0.3);
    transition: transform 0.3s ease;
}

.member-photo:hover {
    transform: scale(1.1);
}

.adherent-badge {
    background: linear-gradient(135deg, #8e44ad, #9b59b6);
    color: white;
    font-size: 0.7rem;
    padding: 0.3rem 0.6rem;
    border-radius: 15px;
}

.gender-badge {
    border-radius: 15px;
    padding: 0.3rem 0.6rem;
    font-size: 0.75rem;
    font-weight: 600;
}

.gender-male {
    background: linear-gradient(135deg, #007bff, #0056b3);
    color: white;
}

.gender-female {
    background: linear-gradient(135deg, #e83e8c, #d91a72);
    color: white;
}

.action-btn {
    border: none;
    border-radius: 8px;
    padding: 0.4rem 0.8rem;
    margin: 0.1rem;
    font-size: 0.8rem;
    cursor: pointer;
    transition: all 0.3s ease;
}

.action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
}

.btn-view {
    background: linear-gradient(135deg, #17a2b8, #138496);
    color: white;
}

.btn-edit {
    background: linear-gradient(135deg, #28a745, #1e7e34);
    color: white;
}

.btn-history {
    background: linear-gradient(135deg, #34495e, #2c3e50);
    color: white;
}

.btn-message {
    background: linear-gradient(135deg, #6f42c1, #5a2d91);
    color: white;
}

.btn-health {
    background: linear-gradient(135deg, #fd7e14, #e55a00);
    color: white;
}

.btn-revert {
    background: linear-gradient(135deg, #dc3545, #c82333);
    color: white;
}

.filter-section {
    background: #f8f9fc;
    border-radius: 15px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    border: 1px solid #e3f2fd;
}

.alert-modern {
    border-radius: 15px;
    border: none;
    padding: 1rem 1.5rem;
    margin-bottom: 1.5rem;
}

@media (max-width: 768px) {
    .adherent-header {
        padding: 1.5rem;
    }
    
    .adherent-title {
        font-size: 1.5rem;
    }
}
</style>

<div class="card adherent-card shadow mb-4">
    <div class="adherent-header">
        <div class="d-flex align-items-center justify-content-between flex-wrap">
            <div>
                <h1 class="adherent-title">
                    <i class="fas fa-user-tag mr-3"></i>
                    Church Adherents
                </h1>
                <p class="adherent-subtitle mb-0">
                    Manage and view all church adherent members
                </p>
            </div>
            <div class="d-flex align-items-center">
                <?php if ($can_add): ?>
                    <a href="member_list.php" class="btn btn-light btn-lg mr-3">
                        <i class="fas fa-users mr-2"></i>All Members
                    </a>
                <?php endif; ?>
                <div class="stats-card ml-3">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-chart-bar fa-2x mr-3"></i>
                        <div>
                            <div class="h4 mb-0"><?= number_format($total_adherents) ?></div>
                            <small>Total Adherents</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body p-4">
        <!-- Alert Messages -->
        <?php if (!empty($_SESSION['flash_success'])): ?>
            <div class="alert alert-success alert-modern alert-dismissible fade show">
                <i class="fas fa-check-circle mr-2"></i>
                <?= htmlspecialchars($_SESSION['flash_success']) ?>
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
            <?php unset($_SESSION['flash_success']); ?>
        <?php elseif (!empty($_SESSION['flash_error'])): ?>
            <div class="alert alert-danger alert-modern alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <?= htmlspecialchars($_SESSION['flash_error']) ?>
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>
        
        <!-- Search and Filter Section -->
        <div class="filter-section">
            <h5 class="mb-4">
                <button class="btn btn-link p-0 text-decoration-none" type="button" data-toggle="collapse" data-target="#filterCollapse" aria-expanded="false" aria-controls="filterCollapse">
                    <i class="fas fa-search mr-2 text-primary"></i>
                    Search & Filters
                    <i class="fas fa-chevron-down ml-2 text-muted" id="filterToggleIcon"></i>
                </button>
            </h5>
            
            <div class="collapse" id="filterCollapse">
            <!-- Search Form -->
            <form method="get" class="mb-4">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="search" class="form-label font-weight-bold">
                            <i class="fas fa-search mr-1"></i>Search Adherents
                        </label>
                        <input type="text" class="form-control search-box" id="search" name="search" 
                               placeholder="Search by name, CRN, or phone..." 
                               value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="church_filter" class="form-label font-weight-bold">
                            <i class="fas fa-church mr-1"></i>Church
                        </label>
                        <select class="form-control" id="church_filter" name="church_id">
                            <option value="">All Churches</option>
                            <?php
                            $churches = $conn->query("SELECT id, name FROM churches ORDER BY name");
                            while($church = $churches->fetch_assoc()):
                            ?>
                                <option value="<?= $church['id'] ?>" <?= ($_GET['church_id'] ?? '') == $church['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($church['name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label for="class_filter" class="form-label font-weight-bold">
                            <i class="fas fa-book mr-1"></i>Bible Class
                        </label>
                        <select class="form-control" id="class_filter" name="class_id">
                            <option value="">All Classes</option>
                            <?php
                            $classes = $conn->query("SELECT id, name FROM bible_classes ORDER BY name");
                            while($class = $classes->fetch_assoc()):
                            ?>
                                <option value="<?= $class['id'] ?>" <?= ($_GET['class_id'] ?? '') == $class['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($class['name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="gender_filter" class="form-label font-weight-bold">
                            <i class="fas fa-venus-mars mr-1"></i>Gender
                        </label>
                        <select class="form-control" id="gender_filter" name="gender">
                            <option value="">All Genders</option>
                            <option value="Male" <?= ($_GET['gender'] ?? '') == 'Male' ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= ($_GET['gender'] ?? '') == 'Female' ? 'selected' : '' ?>>Female</option>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="page_size" class="form-label font-weight-bold">
                            <i class="fas fa-list mr-1"></i>Show
                        </label>
                        <select name="page_size" id="page_size" class="form-control">
                            <?php foreach ([20,40,60,80,100] as $opt): ?>
                                <option value="<?= $opt ?>"<?= $page_size == $opt ? ' selected' : '' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary mr-2">
                            <i class="fas fa-search mr-1"></i>Search & Filter
                        </button>
                        <a href="adherent_list.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times mr-1"></i>Clear
                        </a>
                    </div>
                </div>
            </form>
            </div> <!-- End collapse -->
        </div>
        
        <!-- Adherent Table -->
        <div class="table-responsive">
            <table class="table adherent-table" id="adherentTable" width="100%" cellspacing="0">
                <thead>
                    <tr>
                        <th>Photo</th>
                        <th>CRN</th>
                        <th>Full Name</th>
                        <th>Phone</th>
                        <th>Bible Class</th>
                        <th>Day Born & Gender</th>
                        <th>Date Became Adherent</th>
                        <th>Reason</th>
                        <th>Marked By</th>
                        <?php if ($can_edit || $can_delete): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $adherents->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <?php
                                $photo_path = !empty($row['photo']) ? __DIR__.'/../uploads/members/' . $row['photo'] : '';
                                if (!empty($row['photo']) && file_exists($photo_path)) {
                                    $photo_url = BASE_URL . '/uploads/members/' . rawurlencode($row['photo']);
                                } else {
                                    $photo_url = BASE_URL . '/assets/img/undraw_profile.svg';
                                }
                                ?>
                                <img src="<?= $photo_url ?>" alt="Member Photo" class="member-photo">
                            </td>
                            <td class="text-nowrap" style="font-size: 0.8rem;">
                                <div class="font-weight-bold"><?=htmlspecialchars($row['crn'])?></div>
                            </td>
                            <td><?=htmlspecialchars(trim($row['last_name'].' '.$row['first_name'].' '.$row['middle_name']))?></td>
                            <td><?=htmlspecialchars($row['phone'])?></td>
                            <td><?=htmlspecialchars($row['class_name'])?></td>
                            <td>
                                <div class="mb-1"><?=htmlspecialchars($row['day_born'])?></div>
                                <span class="gender-badge <?= $row['gender'] == 'Male' ? 'gender-male' : 'gender-female' ?>">
                                    <i class="fas <?= $row['gender'] == 'Male' ? 'fa-mars' : 'fa-venus' ?> mr-1"></i>
                                    <?=htmlspecialchars($row['gender'])?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-primary">
                                    <?= $row['date_became_adherent'] ? date('M j, Y', strtotime($row['date_became_adherent'])) : 'N/A' ?>
                                </span>
                            </td>
                            <td>
                                <div style="max-width: 200px; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($row['adherent_reason']) ?>">
                                    <?= htmlspecialchars(substr($row['adherent_reason'], 0, 50)) ?><?= strlen($row['adherent_reason']) > 50 ? '...' : '' ?>
                                </div>
                            </td>
                            <td>
                                <small><?= htmlspecialchars($row['marked_by_name']) ?></small>
                            </td>
                            <?php if ($can_edit || $can_delete): ?>
                                <td class="text-nowrap">
                                    <a href="member_view.php?id=<?=$row['id']?>" class="action-btn btn-view" title="View Member">
                                        <i class="fas fa-user"></i>
                                    </a>
                                    <button type="button" class="action-btn btn-history ml-1" title="View Adherent History" 
                                            data-toggle="modal" data-target="#adherentHistoryModal" 
                                            data-member-id="<?= $row['id'] ?>" 
                                            data-member-name="<?= htmlspecialchars(trim($row['last_name'].' '.$row['first_name'].' '.$row['middle_name'])) ?>">
                                        <i class="fas fa-history"></i>
                                    </button>
                                    <?php if ($can_edit): ?>
                                        <a href="admin_member_edit.php?id=<?=$row['id']?>" class="action-btn btn-edit ml-1" title="Edit Member">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button type="button" class="action-btn btn-revert ml-1" title="Revert Adherent Status" 
                                                onclick="revertAdherent(<?= $row['id'] ?>, '<?= htmlspecialchars(trim($row['last_name'].' '.$row['first_name'].' '.$row['middle_name'])) ?>')">
                                            <i class="fas fa-undo"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination controls -->
        <nav aria-label="Adherent list pagination">
            <ul class="pagination justify-content-center">
                <?php if ($page > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=1">First</a></li>
                    <li class="page-item"><a class="page-link" href="?page=<?= $page-1 ?>">&laquo; Prev</a></li>
                <?php else: ?>
                    <li class="page-item disabled"><span class="page-link">First</span></li>
                    <li class="page-item disabled"><span class="page-link">&laquo; Prev</span></li>
                <?php endif; ?>
                <?php
                // Show up to 5 pages around current
                $start = max(1, $page-2);
                $end = min($total_pages, $page+2);
                for ($i = $start; $i <= $end; $i++):
                ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <li class="page-item"><a class="page-link" href="?page=<?= $page+1 ?>">Next &raquo;</a></li>
                    <li class="page-item"><a class="page-link" href="?page=<?= $total_pages ?>">Last</a></li>
                <?php else: ?>
                    <li class="page-item disabled"><span class="page-link">Next &raquo;</span></li>
                    <li class="page-item disabled"><span class="page-link">Last</span></li>
                <?php endif; ?>
            </ul>
            <div class="text-center text-muted small mb-3">Page <?= $page ?> of <?= $total_pages ?> (<?= $total_adherents ?> adherents)</div>
        </nav>
    </div>
</div>

<script>
$(document).ready(function() {
    // Handle collapsible filter section
    $('#filterCollapse').on('show.bs.collapse', function () {
        $('#filterToggleIcon').removeClass('fa-chevron-down').addClass('fa-chevron-up');
    });

    $('#filterCollapse').on('hide.bs.collapse', function () {
        $('#filterToggleIcon').removeClass('fa-chevron-up').addClass('fa-chevron-down');
    });
});

function revertAdherent(memberId, memberName) {
    if (confirm('Are you sure you want to revert ' + memberName + ' from adherent status? This will change their membership status back to Full Member.')) {
        $.ajax({
            url: BASE_URL + '/views/ajax_revert_adherent.php',
            type: 'POST',
            data: { member_id: memberId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (response.message || 'Failed to revert adherent status'));
                }
            },
            error: function(xhr) {
                alert('Error: Failed to revert adherent status');
            }
        });
    }
}

var BASE_URL = "<?= addslashes(BASE_URL) ?>";
</script>

<?php
// Include adherent modals using output buffering (following visitor_list pattern)
ob_start();
include 'adherent_modals.php';
$modal_html = ob_get_clean();

// Include adherent scripts
ob_start();
include 'adherent_scripts.php';
$script_html = ob_get_clean();

$page_content = ob_get_clean();
include '../includes/layout.php';
?>

<?= $script_html ?>
