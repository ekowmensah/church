<?php
//if (session_status() === PHP_SESSION_NONE) session_start();
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

if (!$is_super_admin && !has_permission('create_member')) {
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
$can_view = true; // Already validated above

$error = '';
$success = '';

// Pagination settings
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = isset($_GET['per_page']) ? max(10, min(500, intval($_GET['per_page']))) : 20;
$offset = ($page - 1) * $per_page;

// Search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$church_filter = isset($_GET['church_id']) ? intval($_GET['church_id']) : 0;
$class_filter = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build WHERE clause for filtering
$where_conditions = ["status = 'pending'"];
$params = [];
$types = '';

if ($search) {
    $where_conditions[] = "(first_name LIKE ? OR middle_name LIKE ? OR last_name LIKE ? OR crn LIKE ? OR phone LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param]);
    $types .= 'sssss';
}

if ($church_filter) {
    $where_conditions[] = "church_id = ?";
    $params[] = $church_filter;
    $types .= 'i';
}

if ($class_filter) {
    $where_conditions[] = "class_id = ?";
    $params[] = $class_filter;
    $types .= 'i';
}

if ($date_from) {
    $where_conditions[] = "DATE(created_at) >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if ($date_to) {
    $where_conditions[] = "DATE(created_at) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

$where_clause = implode(' AND ', $where_conditions);

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM members WHERE $where_clause";
$count_stmt = $conn->prepare($count_sql);
if ($params) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $per_page);
$count_stmt->close();

// Get pending members with pagination
$sql = "SELECT m.id, m.first_name, m.middle_name, m.last_name, m.phone, m.crn, m.registration_token, 
               m.created_at, c.name as church_name, bc.name as class_name 
        FROM members m 
        LEFT JOIN churches c ON m.church_id = c.id 
        LEFT JOIN bible_classes bc ON m.class_id = bc.id 
        WHERE $where_clause 
        ORDER BY m.created_at DESC 
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
if ($params) {
    $params[] = $per_page;
    $params[] = $offset;
    $types .= 'ii';
    $stmt->bind_param($types, ...$params);
} else {
    $stmt->bind_param('ii', $per_page, $offset);
}
$stmt->execute();
$pending_members = $stmt->get_result();
$stmt->close();

// Get statistics
$stats = [];

// Total pending members
$stats['total_pending'] = $total_records;

// Pending by church
$church_stats = $conn->query("
    SELECT c.name as church_name, COUNT(m.id) as count 
    FROM members m 
    LEFT JOIN churches c ON m.church_id = c.id 
    WHERE m.status = 'pending' 
    GROUP BY m.church_id, c.name 
    ORDER BY count DESC
");
$stats['by_church'] = $church_stats->fetch_all(MYSQLI_ASSOC);

// Recently completed (last 7 days) - using created_at as proxy since updated_at doesn't exist
$completed_stats = $conn->query("
    SELECT COUNT(*) as count 
    FROM members 
    WHERE status = 'active' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$stats['recently_completed'] = $completed_stats->fetch_assoc()['count'];

// Average completion time - simplified since we don't have updated_at column
$stats['avg_completion_days'] = 'N/A';

// Fetch dropdowns for filters
$churches = $conn->query("SELECT id, name FROM churches ORDER BY name ASC");
$classes = $conn->query("SELECT id, name FROM bible_classes ORDER BY name ASC");

ob_start();
?>
<!-- Header Section -->
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0 text-gray-800">Member Registration</h1>
        <p class="mb-0 text-muted">Manage pending member registrations and completions</p>
    </div>
    <div class="d-flex align-items-center">
        <a href="member_list.php" class="btn btn-secondary btn-sm mr-2">
            <i class="fas fa-arrow-left"></i> Back to List
        </a>
        <a href="member_upload_enhanced.php" class="btn btn-primary btn-sm">
            <i class="fas fa-upload"></i> Bulk Upload
        </a>
    </div>
</div>

<!-- Statistics Dashboard -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-warning shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                            Pending Registrations
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= number_format($stats['total_pending']) ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-user-clock fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-success shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                            Completed (7 Days)
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= number_format($stats['recently_completed']) ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-user-check fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-info shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                            Avg. Completion Time
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $stats['avg_completion_days'] ?> days</div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-clock fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-primary shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                            Completion Rate
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                            <?php 
                            $total_members = $conn->query("SELECT COUNT(*) as count FROM members")->fetch_assoc()['count'];
                            $completion_rate = $total_members > 0 ? round((($total_members - $stats['total_pending']) / $total_members) * 100, 1) : 0;
                            echo $completion_rate . '%';
                            ?>
                        </div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-percentage fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters and Search -->
<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">Search & Filter</h6>
    </div>
    <div class="card-body">
        <form method="GET" class="row">
            <div class="col-md-3 mb-3">
                <label for="search" class="form-label">Search</label>
                <input type="text" class="form-control" id="search" name="search" 
                       value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Name, CRN, or Phone">
            </div>
            <div class="col-md-2 mb-3">
                <label for="church_id" class="form-label">Church</label>
                <select class="form-control" id="church_id" name="church_id">
                    <option value="">All Churches</option>
                    <?php while ($church = $churches->fetch_assoc()): ?>
                        <option value="<?= $church['id'] ?>" <?= $church_filter == $church['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($church['name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-2 mb-3">
                <label for="class_id" class="form-label">Bible Class</label>
                <select class="form-control" id="class_id" name="class_id">
                    <option value="">All Classes</option>
                    <?php while ($class = $classes->fetch_assoc()): ?>
                        <option value="<?= $class['id'] ?>" <?= $class_filter == $class['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($class['name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-2 mb-3">
                <label for="date_from" class="form-label">From Date</label>
                <input type="date" class="form-control" id="date_from" name="date_from" 
                       value="<?= htmlspecialchars($date_from) ?>">
            </div>
            <div class="col-md-2 mb-3">
                <label for="date_to" class="form-label">To Date</label>
                <input type="date" class="form-control" id="date_to" name="date_to" 
                       value="<?= htmlspecialchars($date_to) ?>">
            </div>
            <div class="col-md-1 mb-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </form>
        <?php if ($search || $church_filter || $class_filter || $date_from || $date_to): ?>
            <div class="mt-2">
                <a href="register_member.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-times"></i> Clear Filters
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($pending_members && $pending_members->num_rows > 0): ?>
<div class="card mb-4 shadow">
    <div class="card-header py-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div class="d-flex align-items-center mb-2 mb-md-0">
                <h6 class="m-0 font-weight-bold text-primary mr-3">
                    Pending Members (<?= number_format($total_records) ?> total)
                </h6>
                <div class="d-flex align-items-center">
                    <label class="mb-0 mr-2 text-muted small">Show:</label>
                    <select class="form-control form-control-sm mr-2" id="perPageSelect" style="width: auto;">
                        <option value="10" <?= $per_page == 10 ? 'selected' : '' ?>>10</option>
                        <option value="20" <?= $per_page == 20 ? 'selected' : '' ?>>20</option>
                        <option value="50" <?= $per_page == 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $per_page == 100 ? 'selected' : '' ?>>100</option>
                        <option value="200" <?= $per_page == 200 ? 'selected' : '' ?>>200</option>
                        <option value="500" <?= $per_page == 500 ? 'selected' : '' ?>>500</option>
                    </select>
                    <span class="text-muted small">per page</span>
                </div>
            </div>
            <div class="d-flex align-items-center">
                <span class="text-muted mr-3 small">Page <?= $page ?> of <?= $total_pages ?></span>
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="select-all-btn">
                        <i class="fas fa-check-square"></i> Select All
                    </button>
                    <div class="btn-group" role="group">
                        <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" 
                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            Bulk Actions
                        </button>
                        <div class="dropdown-menu">
                            <a class="dropdown-item" href="#" id="bulk-register-btn">
                                <i class="fas fa-user-check"></i> Bulk Register
                            </a>
                            <a class="dropdown-item" href="#" id="bulk-resend-btn">
                                <i class="fas fa-paper-plane"></i> Resend Links
                            </a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item text-danger" href="#" id="bulk-delete-btn">
                                <i class="fas fa-trash"></i> Delete Selected
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th width="40">
                            <input type="checkbox" id="select-all-checkbox" class="form-check-input">
                        </th>
                        <th>CRN</th>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Church</th>
                        <th>Bible Class</th>
                        <th>Created</th>
                        <th width="200">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while($pm = $pending_members->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <input type="checkbox" class="form-check-input member-checkbox" 
                                   value="<?= $pm['id'] ?>" data-crn="<?= htmlspecialchars($pm['crn']) ?>">
                        </td>
                        <td>
                            <span class="font-weight-bold text-primary"><?= htmlspecialchars($pm['crn']) ?></span>
                        </td>
                        <td>
                            <div>
                                <strong><?= htmlspecialchars($pm['first_name'] . ' ' . $pm['last_name']) ?></strong>
                                <?php if ($pm['middle_name']): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($pm['middle_name']) ?></small>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($pm['phone']): ?>
                                <a href="tel:<?= htmlspecialchars($pm['phone']) ?>" class="text-decoration-none">
                                    <i class="fas fa-phone text-success"></i> <?= htmlspecialchars($pm['phone']) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">No phone</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-info"><?= htmlspecialchars($pm['church_name'] ?? 'Unknown') ?></span>
                        </td>
                        <td>
                            <span class="badge badge-secondary"><?= htmlspecialchars($pm['class_name'] ?? 'Unknown') ?></span>
                        </td>
                        <td>
                            <small class="text-muted">
                                <?= date('M j, Y', strtotime($pm['created_at'])) ?>
                                <br><?= date('g:i A', strtotime($pm['created_at'])) ?>
                            </small>
                        </td>
                        <td>
                            <div class="btn-group" role="group">
                                <a href="complete_registration_admin.php?id=<?= urlencode($pm['id']) ?>" 
                                   class="btn btn-sm btn-success" title="Complete Registration">
                                    <i class="fas fa-user-check"></i>
                                </a>
                                <button type="button" class="btn btn-sm btn-info resend-link-btn" 
                                        data-id="<?= htmlspecialchars($pm['id']) ?>" title="Resend Link">
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                                <a href="member_delete.php?id=<?= urlencode($pm['id']) ?>" 
                                   class="btn btn-sm btn-danger" title="Delete"
                                   onclick="return confirm('Are you sure you want to delete this pending member?');">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="card-footer">
        <nav aria-label="Pending members pagination">
            <ul class="pagination justify-content-center mb-0">
<?php
                // Build query parameters for pagination links
                $query_params = [];
                if ($search) $query_params[] = 'search=' . urlencode($search);
                if ($church_filter) $query_params[] = 'church_id=' . $church_filter;
                if ($class_filter) $query_params[] = 'class_id=' . $class_filter;
                if ($date_from) $query_params[] = 'date_from=' . $date_from;
                if ($date_to) $query_params[] = 'date_to=' . $date_to;
                if ($per_page != 20) $query_params[] = 'per_page=' . $per_page;
                $query_string = $query_params ? '&' . implode('&', $query_params) : '';
                ?>
                
                <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=1<?= $query_string ?>">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                    </li>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?= $page - 1 ?><?= $query_string ?>">
                            <i class="fas fa-angle-left"></i>
                        </a>
                    </li>
                <?php endif; ?>
                
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?><?= $query_string ?>">
                            <?= $i ?>
                        </a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?= $page + 1 ?><?= $query_string ?>">
                            <i class="fas fa-angle-right"></i>
                        </a>
                    </li>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?= $total_pages ?><?= $query_string ?>">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>


<script>
document.addEventListener('DOMContentLoaded', function() {
    // Select all functionality
    const selectAllCheckbox = document.getElementById('select-all-checkbox');
    const memberCheckboxes = document.querySelectorAll('.member-checkbox');
    const selectAllBtn = document.getElementById('select-all-btn');
    
    // Select all checkbox functionality
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            memberCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkActionButtons();
        });
    }
    
    // Select all button functionality
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function() {
            const allChecked = Array.from(memberCheckboxes).every(cb => cb.checked);
            memberCheckboxes.forEach(checkbox => {
                checkbox.checked = !allChecked;
            });
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = !allChecked;
            }
            updateBulkActionButtons();
        });
    }
    
    // Individual checkbox change
    memberCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const allChecked = Array.from(memberCheckboxes).every(cb => cb.checked);
            const noneChecked = Array.from(memberCheckboxes).every(cb => !cb.checked);
            
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = allChecked;
                selectAllCheckbox.indeterminate = !allChecked && !noneChecked;
            }
            updateBulkActionButtons();
        });
    });
    
    function updateBulkActionButtons() {
        const checkedCount = Array.from(memberCheckboxes).filter(cb => cb.checked).length;
        const bulkButtons = document.querySelectorAll('#bulk-register-btn, #bulk-resend-btn, #bulk-delete-btn');
        
        bulkButtons.forEach(btn => {
            if (checkedCount > 0) {
                btn.classList.remove('disabled');
                btn.style.pointerEvents = 'auto';
            } else {
                btn.classList.add('disabled');
                btn.style.pointerEvents = 'none';
            }
        });
        
        // Update select all button text
        if (selectAllBtn) {
            const allChecked = Array.from(memberCheckboxes).every(cb => cb.checked);
            selectAllBtn.innerHTML = allChecked ? 
                '<i class="fas fa-square"></i> Deselect All' : 
                '<i class="fas fa-check-square"></i> Select All';
        }
    }
    
    // Bulk actions
    document.getElementById('bulk-register-btn')?.addEventListener('click', function(e) {
        e.preventDefault();
        const selected = Array.from(memberCheckboxes).filter(cb => cb.checked);
        if (selected.length === 0) return;
        
        // Show bulk registration modal
        showBulkRegistrationModal(selected.map(cb => cb.value));
    });
    
    document.getElementById('bulk-resend-btn')?.addEventListener('click', function(e) {
        e.preventDefault();
        const selected = Array.from(memberCheckboxes).filter(cb => cb.checked);
        if (selected.length === 0) return;
        
        if (confirm(`Are you sure you want to resend registration links to ${selected.length} selected members?`)) {
            const ids = selected.map(cb => cb.value);
            bulkResendLinks(ids);
        }
    });
    
    document.getElementById('bulk-delete-btn')?.addEventListener('click', function(e) {
        e.preventDefault();
        const selected = Array.from(memberCheckboxes).filter(cb => cb.checked);
        if (selected.length === 0) return;
        
        if (confirm(`Are you sure you want to DELETE ${selected.length} selected pending members? This action cannot be undone.`)) {
            const ids = selected.map(cb => cb.value);
            bulkDeleteMembers(ids);
        }
    });
    
    // Initialize bulk action buttons state
    updateBulkActionButtons();
    
    // Per page selector functionality
    const perPageSelect = document.getElementById('perPageSelect');
    if (perPageSelect) {
        perPageSelect.addEventListener('change', function() {
            // Get current URL parameters
            const urlParams = new URLSearchParams(window.location.search);
            
            // Set the new per_page value
            urlParams.set('per_page', this.value);
            
            // Remove page parameter to reset to page 1
            urlParams.delete('page');
            
            // Redirect with new parameters
            window.location.href = window.location.pathname + '?' + urlParams.toString();
        });
    }
    
    // Individual resend link functionality
    document.querySelectorAll('.resend-link-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            var origText = this.innerHTML;
            this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            this.disabled = true;
            fetch('ajax_resend_registration_link.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'id=' + encodeURIComponent(id)
            })
            .then(resp => resp.json())
            .then(data => {
                this.innerHTML = origText;
                this.disabled = false;
                alert(data.message);
            })
            .catch(() => {
                this.textContent = origText;
                this.disabled = false;
                alert('Network error.');
            });
        });
    });
    
    // Bulk resend links function
    function bulkResendLinks(ids) {
        const btn = document.getElementById('bulk-resend-btn');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
        btn.disabled = true;
        
        fetch('ajax_bulk_resend_links.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ids: ids})
        })
        .then(resp => resp.json())
        .then(data => {
            btn.innerHTML = originalText;
            btn.disabled = false;
            alert(data.message || 'Links sent successfully!');
            if (data.success) {
                // Uncheck all checkboxes
                document.querySelectorAll('.member-checkbox').forEach(cb => cb.checked = false);
                document.getElementById('select-all-checkbox').checked = false;
                updateBulkActionButtons();
            }
        })
        .catch(error => {
            btn.innerHTML = originalText;
            btn.disabled = false;
            alert('Error sending links. Please try again.');
        });
    }
    
    // Bulk delete members function
    function bulkDeleteMembers(ids) {
        const btn = document.getElementById('bulk-delete-btn');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
        btn.disabled = true;
        
        fetch('ajax_bulk_delete_members.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ids: ids})
        })
        .then(resp => resp.json())
        .then(data => {
            btn.innerHTML = originalText;
            btn.disabled = false;
            alert(data.message || 'Members deleted successfully!');
            if (data.success) {
                // Reload the page to refresh the list
                window.location.reload();
            }
        })
        .catch(error => {
            btn.innerHTML = originalText;
            btn.disabled = false;
            alert('Error deleting members. Please try again.');
        });
    }
    
    // Bulk registration modal functions
    function showBulkRegistrationModal(memberIds) {
        document.getElementById('selectedMemberCount').textContent = memberIds.length;
        $('#bulkRegistrationModal').modal('show');
        
        // Store member IDs for later use
        window.selectedMemberIds = memberIds;
    }
    
    // Confirm bulk registration
    document.getElementById('confirmBulkRegistration')?.addEventListener('click', function() {
        if (!window.selectedMemberIds || window.selectedMemberIds.length === 0) {
            alert('No members selected');
            return;
        }
        
        const form = document.getElementById('bulkRegistrationForm');
        const formData = new FormData(form);
        const defaults = {};
        
        // Convert form data to object
        for (let [key, value] of formData.entries()) {
            defaults[key] = value;
        }
        
        // Show progress
        document.getElementById('bulkRegistrationProgress').style.display = 'block';
        const progressBar = document.querySelector('#bulkRegistrationProgress .progress-bar');
        progressBar.style.width = '50%';
        
        // Disable button
        const btn = this;
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        btn.disabled = true;
        
        // Send request
        fetch('ajax_bulk_register_members.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                member_ids: window.selectedMemberIds,
                defaults: defaults
            })
        })
        .then(resp => resp.json())
        .then(data => {
            progressBar.style.width = '100%';
            
            setTimeout(() => {
                btn.innerHTML = originalText;
                btn.disabled = false;
                document.getElementById('bulkRegistrationProgress').style.display = 'none';
                progressBar.style.width = '0%';
                
                if (data.success) {
                    alert(data.message);
                    $('#bulkRegistrationModal').modal('hide');
                    
                    // Refresh the page to update the list
                    window.location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            }, 1000);
        })
        .catch(error => {
            console.error('Error:', error);
            btn.innerHTML = originalText;
            btn.disabled = false;
            document.getElementById('bulkRegistrationProgress').style.display = 'none';
            progressBar.style.width = '0%';
            alert('Network error occurred. Please try again.');
        });
    });
});
</script>

<style>
.card.border-left-warning {
    border-left: 0.25rem solid #f6c23e !important;
}
.card.border-left-success {
    border-left: 0.25rem solid #1cc88a !important;
}
.card.border-left-info {
    border-left: 0.25rem solid #36b9cc !important;
}
.card.border-left-primary {
    border-left: 0.25rem solid #4e73df !important;
}
.pagination .page-link {
    color: #4e73df;
}
.pagination .page-item.active .page-link {
    background-color: #4e73df;
    border-color: #4e73df;
}
.btn-group .dropdown-menu {
    box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
}
.table th {
    border-top: none;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.5px;
}
.badge {
    font-size: 0.7rem;
    padding: 0.35em 0.65em;
}
</style>

<?php
// Capture modal HTML separately like visitor_list.php
ob_start();
?>
<!-- Bulk Registration Modal -->
<div class="modal fade" id="bulkRegistrationModal" tabindex="-1" role="dialog" aria-labelledby="bulkRegistrationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bulkRegistrationModalLabel">
                    <i class="fas fa-users"></i> Bulk Member Registration
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <strong>Selected Members:</strong> <span id="selectedMemberCount">0</span> members will be registered with the default values below.
                </div>
                
                <form id="bulkRegistrationForm">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="bulk_gender">Default Gender</label>
                                <select class="form-control" id="bulk_gender" name="gender">
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="bulk_dob">Default Date of Birth</label>
                                <input type="date" class="form-control" id="bulk_dob" name="dob" value="1990-01-01">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="bulk_marital_status">Default Marital Status</label>
                                <select class="form-control" id="bulk_marital_status" name="marital_status">
                                    <option value="Single">Single</option>
                                    <option value="Married">Married</option>
                                    <option value="Divorced">Divorced</option>
                                    <option value="Widowed">Widowed</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="bulk_region">Default Region</label>
                                <select class="form-control" id="bulk_region" name="region">
                                    <option value="Greater Accra">Greater Accra</option>
                                    <option value="Ashanti">Ashanti</option>
                                    <option value="Central">Central</option>
                                    <option value="Eastern">Eastern</option>
                                    <option value="Northern">Northern</option>
                                    <option value="Upper East">Upper East</option>
                                    <option value="Upper West">Upper West</option>
                                    <option value="Volta">Volta</option>
                                    <option value="Western">Western</option>
                                    <option value="Brong Ahafo">Brong Ahafo</option>
                                    <option value="Western North">Western North</option>
                                    <option value="Ahafo">Ahafo</option>
                                    <option value="Bono">Bono</option>
                                    <option value="Bono East">Bono East</option>
                                    <option value="Oti">Oti</option>
                                    <option value="Savannah">Savannah</option>
                                    <option value="North East">North East</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="bulk_employment_status">Default Employment Status</label>
                                <select class="form-control" id="bulk_employment_status" name="employment_status">
                                    <option value="Formal">Formal</option>
                                    <option value="Informal">Informal</option>
                                    <option value="Self Employed">Self Employed</option>
                                    <option value="Retired">Retired</option>
                                    <option value="Student">Student</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="bulk_membership_status">Default Membership Status</label>
                                <select class="form-control" id="bulk_membership_status" name="membership_status">
                                    <option value="Full Member">Full Member</option>
                                    <option value="Catechumen">Catechumen</option>
                                    <option value="Adherent">Adherent</option>
                                    <option value="Juvenile">Juvenile</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="bulk_baptized">Default Baptized Status</label>
                                <select class="form-control" id="bulk_baptized" name="baptized">
                                    <option value="Yes">Yes</option>
                                    <option value="No">No</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="bulk_confirmed">Default Confirmed Status</label>
                                <select class="form-control" id="bulk_confirmed" name="confirmed">
                                    <option value="Yes">Yes</option>
                                    <option value="No">No</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="bulk_password">Default Password</label>
                                <input type="text" class="form-control" id="bulk_password" name="password" value="123456">
                                <small class="form-text text-muted">All members will receive this password via SMS</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="bulk_place_of_birth">Default Place of Birth</label>
                                <input type="text" class="form-control" id="bulk_place_of_birth" name="place_of_birth" value="Ghana">
                            </div>
                        </div>
                    </div>
                </form>
                
                <div id="bulkRegistrationProgress" class="mt-3" style="display: none;">
                    <div class="progress">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
                    </div>
                    <div class="text-center mt-2">
                        <small class="text-muted">Processing bulk registration...</small>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmBulkRegistration">
                    <i class="fas fa-user-check"></i> Register Selected Members
                </button>
            </div>
        </div>
    </div>
</div>
<?php
$modal_html = ob_get_clean();
$page_content = ob_get_clean();
include '../includes/layout.php';
