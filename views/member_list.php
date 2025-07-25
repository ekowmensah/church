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
$where_conditions = ["m.status = 'active'"];
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

// Bible Class filter
if (!empty($_GET['class_id'])) {
    $where_conditions[] = "m.class_id = ?";
    $params[] = $_GET['class_id'];
    $param_types .= "i";
}

// Organization filter - handled through organization_membership_approvals table
if (!empty($_GET['organization_id'])) {
    $where_conditions[] = "EXISTS (SELECT 1 FROM organization_membership_approvals oma WHERE oma.member_id = m.id AND oma.organization_id = ? AND oma.status = 'approved')";
    $params[] = $_GET['organization_id'];
    $param_types .= "i";
}

// Gender filter
if (!empty($_GET['gender'])) {
    $where_conditions[] = "m.gender = ?";
    $params[] = $_GET['gender'];
    $param_types .= "s";
}

// Birth month filter
if (!empty($_GET['birth_month'])) {
    $where_conditions[] = "MONTH(STR_TO_DATE(m.day_born, '%Y-%m-%d')) = ?";
    $params[] = $_GET['birth_month'];
    $param_types .= "s";
}

$where_clause = implode(' AND ', $where_conditions);

// Count total filtered members
$count_query = "SELECT COUNT(*) as cnt FROM members m WHERE $where_clause";
if (!empty($params)) {
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->bind_param($param_types, ...$params);
    $count_stmt->execute();
    $total_members = $count_stmt->get_result()->fetch_assoc()['cnt'];
    $count_stmt->close();
} else {
    $total_members = $conn->query($count_query)->fetch_assoc()['cnt'];
}

if (is_numeric($page_size)) {
    $offset = ($page - 1) * intval($page_size);
    $total_pages = ceil($total_members / intval($page_size));
} else {
    // 'all' selected
    $offset = 0;
    $total_pages = 1;
    $page = 1;
}

// Fetch paginated members with class and church names
$base_query = "SELECT m.*, c.name AS class_name, ch.name AS church_name 
               FROM members m 
               LEFT JOIN bible_classes c ON m.class_id = c.id 
               LEFT JOIN churches ch ON m.church_id = ch.id 
               WHERE $where_clause 
               ORDER BY m.last_name ASC, m.first_name ASC, m.middle_name ASC";

if ($page_size !== 'all') {
    $base_query .= " LIMIT $offset, $page_size";
}

if (!empty($params)) {
    $stmt = $conn->prepare($base_query);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $members = $stmt->get_result();
} else {
    $members = $conn->query($base_query);
}

ob_start();
?>
<!-- Enhanced CSS for Modern Member List -->
<style>
.member-card {
    background: #fff;
    border-radius: 20px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.1);
    border: none;
    overflow: hidden;
}

.member-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 2rem;
    color: white;
    position: relative;
}

.member-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="20" cy="20" r="2" fill="%23ffffff" opacity="0.1"/><circle cx="80" cy="80" r="2" fill="%23ffffff" opacity="0.1"/></svg>');
    z-index: 1;
}

.member-title {
    font-size: 1.8rem;
    font-weight: 600;
    margin: 0;
    position: relative;
    z-index: 1;
}

.member-subtitle {
    opacity: 0.9;
    margin-top: 0.5rem;
    position: relative;
    z-index: 1;
}

.member-header .btn {
    position: relative;
    z-index: 10;
}

.member-header .stats-card {
    position: relative;
    z-index: 10;
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

.member-table {
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
}

.member-table thead {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
}

.member-table thead th {
    border: none;
    font-weight: 600;
    padding: 1rem 0.75rem;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.member-table tbody tr {
    transition: all 0.3s ease;
    border-bottom: 1px solid #f1f3f4;
}

.member-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(102, 126, 234, 0.05), rgba(118, 75, 162, 0.05));
    transform: scale(1.01);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.member-table tbody td {
    padding: 1rem 0.75rem;
    vertical-align: middle;
    border: none;
}

.member-photo {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    border: 3px solid #667eea;
    object-fit: cover;
    box-shadow: 0 3px 10px rgba(102, 126, 234, 0.3);
    transition: transform 0.3s ease;
}

.member-photo:hover {
    transform: scale(1.1);
}

.status-badge {
    border-radius: 15px;
    padding: 0.25rem 0.5rem;
    font-weight: 600;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.status-active {
    background: linear-gradient(135deg, #28a745, #20c997);
    color: white;
}

.status-inactive {
    background: linear-gradient(135deg, #6c757d, #495057);
    color: white;
}

.status-pending {
    background: linear-gradient(135deg, #ffc107, #fd7e14);
    color: white;
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
    border-radius: 20px;
    padding: 0.3rem 0.6rem;
    margin: 0.1rem;
    font-size: 0.8rem;
    border: none;
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

.btn-health {
    background: linear-gradient(135deg, #fd7e14, #e55a00);
    color: white;
}

.btn-message {
    background: linear-gradient(135deg, #6f42c1, #5a2d91);
    color: white;
}

.alert-modern {
    border-radius: 15px;
    border: none;
    padding: 1rem 1.5rem;
    margin-bottom: 1.5rem;
}

.filter-section {
    background: #f8f9fc;
    border-radius: 15px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    border: 1px solid #e3f2fd;
}

@media (max-width: 768px) {
    .member-header {
        padding: 1.5rem;
    }
    
    .member-title {
        font-size: 1.5rem;
    }
}
</style>

<div class="card member-card shadow mb-4">
    <div class="member-header">
        <div class="d-flex align-items-center justify-content-between flex-wrap">
            <div>
                <h1 class="member-title">
                    <i class="fas fa-users mr-3"></i>
                    Church Members
                </h1>
                <p class="member-subtitle mb-0">
                    Manage and view all church member information
                </p>
            </div>
            <div class="d-flex align-items-center">
                <?php if ($can_add): ?>
                    <a href="member_form.php" class="btn btn-light btn-lg mr-3">
                        <i class="fas fa-user-plus mr-2"></i>Add Member
                    </a>
                    <a href="member_upload.php" class="btn btn-outline-light mr-3">
                        <i class="fas fa-file-upload mr-2"></i>Bulk Upload
                    </a>
                    <a href="deleted_members_list.php" class="btn btn-outline-light">
                        <i class="fas fa-trash-alt mr-2"></i>Deleted
                    </a>
                <?php endif; ?>
                <div class="stats-card ml-3">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-chart-bar fa-2x mr-3"></i>
                        <div>
                            <div class="h4 mb-0"><?= number_format($total_members) ?></div>
                            <small>Total Members</small>
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
        <?php if (isset($_GET['added'])): ?>
            <div class="alert alert-success alert-modern">
                <i class="fas fa-user-plus mr-2"></i>Member added successfully!
            </div>
        <?php elseif (isset($_GET['updated'])): ?>
            <div class="alert alert-success alert-modern">
                <i class="fas fa-user-edit mr-2"></i>Member updated successfully!
            </div>
        <?php elseif (isset($_GET['deleted'])): ?>
            <div class="alert alert-success alert-modern">
                <i class="fas fa-user-minus mr-2"></i>Member deleted successfully!
            </div>
        <?php elseif (isset($_GET['deactivated'])): ?>
            <div class="alert alert-warning alert-modern">
                <i class="fas fa-user-times mr-2"></i>Member de-activated successfully!
            </div>
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
                            <i class="fas fa-search mr-1"></i>Search Members
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
                        <label for="organization_filter" class="form-label font-weight-bold">
                            <i class="fas fa-users mr-1"></i>Organization
                        </label>
                        <select class="form-control" id="organization_filter" name="organization_id">
                            <option value="">All Organizations</option>
                            <?php
                            $organizations = $conn->query("SELECT id, name FROM organizations ORDER BY name");
                            while($org = $organizations->fetch_assoc()):
                            ?>
                                <option value="<?= $org['id'] ?>" <?= ($_GET['organization_id'] ?? '') == $org['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($org['name']) ?>
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
                    <div class="col-md-3 mb-3">
                        <label for="birth_month" class="form-label font-weight-bold">
                            <i class="fas fa-birthday-cake mr-1"></i>Birth Month
                        </label>
                        <select class="form-control" id="birth_month" name="birth_month">
                            <option value="">All Months</option>
                            <?php
                            $months = [
                                '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
                                '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
                                '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
                            ];
                            foreach($months as $num => $name):
                            ?>
                                <option value="<?= $num ?>" <?= ($_GET['birth_month'] ?? '') == $num ? 'selected' : '' ?>>
                                    <?= $name ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="page_size" class="form-label font-weight-bold">
                            <i class="fas fa-list mr-1"></i>Show
                        </label>
                        <select name="page_size" id="page_size" class="form-control">
                            <?php foreach ([20,40,60,80,100] as $opt): ?>
                                <option value="<?= $opt ?>"<?= $page_size == $opt ? ' selected' : '' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                            <option value="all"<?= $page_size === 'all' ? ' selected' : '' ?>>All</option>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary-modern mr-2">
                            <i class="fas fa-search mr-1"></i>Search & Filter
                        </button>
                        <a href="member_list.php" class="btn btn-outline-modern">
                            <i class="fas fa-times mr-1"></i>Clear
                        </a>
                    </div>
                </div>
            </form>
            </div> <!-- End collapse -->
        </div>
        
        <!-- Member Table -->
        <div class="table-responsive">
            <table class="table member-table" id="memberTable" width="100%" cellspacing="0">
                <thead>
                    <tr>
                        <th>Photo</th>
                        <th>CRN</th>
                        <th>Full Name</th>
                        <th>Phone</th>
                        <th>Bible Class</th>
                        <th>Day Born & Gender</th>
                        <th>Status</th>
                        <?php if ($can_edit || $can_delete): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $members->fetch_assoc()): ?>
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
                                <img src="<?= $photo_url ?>" alt="photo" class="member-photo">
                            </td>
                            <td class="text-nowrap">
                                <div><small><?=htmlspecialchars($row['crn'])?></small></div>
                                <div class="total-payments text-success font-weight-bold" data-member-id="<?= $row['id'] ?>" style="font-size: 0.7rem;">
                                    <i class="fas fa-spinner fa-spin"></i>
                                </div>
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
                                <?php if ($row['status'] == 'active'): ?>
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-check-circle text-success" title="Active" style="font-size: 1.2rem;"></i>
                                        <?php if ($can_edit): ?>
                                            <a href="member_deactivate.php?id=<?= $row['id'] ?>" class="action-btn btn-health ml-2" onclick="return confirm('De-activate this member? This will set their status to Pending.')" title="De-Activate">
                                                <i class="fas fa-user-slash"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif ($row['status'] == 'pending' && !empty($row['deactivated_at'])): ?>
                                    <span class="status-badge status-inactive">
                                        <i class="fas fa-times-circle mr-1"></i>De-Activated
                                    </span>
                                    <a href="member_activate.php?id=<?= $row['id'] ?>" class="action-btn btn-edit ml-1" title="Activate member" onclick="return confirm('Activate this member?')">
                                        <i class="fas fa-user-check"></i>
                                    </a>
                                <?php elseif ($row['status'] == 'pending'): ?>
                                    <span class="status-badge status-pending">
                                        <i class="fas fa-clock mr-1"></i>Pending
                                    </span>
                                    <button type="button" class="action-btn btn-message ml-1 resend-token-btn" data-member-id="<?= $row['id'] ?>" title="Resend registration link via SMS">
                                        <i class="fas fa-sms"></i>
                                    </button>
                                <?php else: ?>
                                    <span class="status-badge status-inactive">
                                        <i class="fas fa-question-circle mr-1"></i>Unknown
                                    </span>
                                <?php endif; ?>
                            </td>
                            <?php if ($can_edit || $can_delete): ?>
                                <td class="text-nowrap">
                                    <a href="member_view.php?id=<?=$row['id']?>" class="action-btn btn-view" title="View Member">
                                        <i class="fas fa-user"></i>
                                    </a>
                                    <button type="button" class="action-btn btn-message ml-1" title="Send Message" data-toggle="modal" data-target="#sendMessageModal_<?=$row['id']?>">
                                        <i class="fas fa-envelope"></i>
                                    </button>
                                    <?php
                                     $member_id = $row['id'];
                                     $member_name = trim($row['last_name'].' '.$row['first_name'].' '.$row['middle_name']);
                                     $member_phone = $row['phone'];
                                    
                                     include __DIR__.'/send_message_modal.php';
                                     $modal_html .= ob_get_clean();
                                     ?>
                                    <a href="health_list.php?member_id=<?=$row['id']?>" class="action-btn btn-health ml-1" title="Health Records">
                                        <i class="fas fa-notes-medical"></i>
                                    </a>
                                    <?php if ($can_edit): ?>
                                        <a href="admin_member_edit.php?id=<?=$row['id']?>" class="action-btn btn-edit ml-1" title="Edit Member">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <!-- Pagination controls -->
        <nav aria-label="Member list pagination">
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
                    <li class="page-item<?= $i == $page ? ' active' : '' ?>">
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
            <div class="text-center text-muted small mb-3">Page <?= $page ?> of <?= $total_pages ?> (<?= $total_members ?> members)</div>
        </nav>
    </div>
</div>
<!-- DataTables scripts -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap4.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script>
$(document).ready(function() {
    // DataTable for export only, disable paging/filtering
    try {
        $('#memberTable').DataTable({
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
    // AJAX fetch total payments for each member
    $('.total-payments').each(function() {
        var span = $(this);
        var memberId = span.data('member-id');
        $.get('ajax_get_total_payments.php', {member_id: memberId}, function(res) {
            console.log('Payments for member', memberId, ':', res);
            if (res && typeof res.total !== 'undefined') {
                span.text('₵' + parseFloat(res.total).toLocaleString(undefined, {minimumFractionDigits: 2}));
            }
        }, 'json');
    });

    // Handle collapsible filter section
    $('#filterCollapse').on('show.bs.collapse', function () {
        $('#filterToggleIcon').removeClass('fa-chevron-down').addClass('fa-chevron-up');
    });

    $('#filterCollapse').on('hide.bs.collapse', function () {
        $('#filterToggleIcon').removeClass('fa-chevron-up').addClass('fa-chevron-down');
    });
});
</script>
<script>
$('.resend-token-btn').on('click', function() {
    var btn = $(this);
    var memberId = btn.data('member-id');
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
    $.post('ajax_resend_token_sms.php', {member_id: memberId}, function(res) {
        btn.prop('disabled', false).html('<i class="fas fa-sms"></i>');
        alert('SMS sent: ' + res);
    }).fail(function(xhr) {
        btn.prop('disabled', false).html('<i class="fas fa-sms"></i>');
        var msg = xhr.responseText ? xhr.responseText : 'Failed to send SMS';
        alert('Error: ' + msg);
    });
});
</script>
<style>
.btn-xs {
    padding: 0.14rem 0.34rem !important;
    font-size: 0.89rem !important;
    line-height: 1.15 !important;
    border-radius: 0.22rem !important;
}
#memberTable th, #memberTable td {
    font-size: 1.01rem !important;
    padding-top: 0.38rem !important;
    padding-bottom: 0.38rem !important;
    padding-left: 0.48rem !important;
    padding-right: 0.48rem !important;
}
#memberTable thead th {
    font-size: 0.98rem !important;
    font-weight: 600;
}
#memberTable {
    font-size: 1.01rem !important;
}
</style>
<script>
var BASE_URL = "<?= addslashes(BASE_URL) ?>";
</script>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';