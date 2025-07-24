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

if (!$is_super_admin && !has_permission('manage_roles')) {
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
$can_add = $is_super_admin || has_permission('create_role');
$can_edit = $is_super_admin || has_permission('edit_role');
$can_delete = $is_super_admin || has_permission('delete_role');
$can_view = true; // Already validated above

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0 text-gray-800"><i class="fas fa-user-tag mr-2"></i>Roles</h1>
    <a href="role_form.php" class="btn btn-primary"><i class="fas fa-plus mr-1"></i> Add Role</a>
</div>
<div id="roleAlert"></div>
<div class="card shadow mb-4">
    <div class="card-header py-3 bg-primary text-white">
        <h6 class="m-0 font-weight-bold">Role List</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover" id="rolesTable">
                <thead class="thead-light">
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="rolesTbody">
                    <tr><td colspan="3" class="text-center"><span id="rolesLoading"><i class="fas fa-spinner fa-spin"></i> Loading...</span></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
require_once __DIR__.'/../config/config.php';
// Use BASE_URL from config.php, but use only the path part for AJAX
$parsed = parse_url(BASE_URL);
$AJAX_BASE = isset($parsed['path']) ? rtrim($parsed['path'], '/') : '';
?>
<script>
const BASE_URL = "<?= $AJAX_BASE ?>";
function fetchRoles() {
    fetch(BASE_URL + '/controllers/role_api.php')
        .then(res => res.json())
        .then(data => {
            const tbody = document.getElementById('rolesTbody');
            tbody.innerHTML = '';
            if (data.success && data.roles.length > 0) {
                data.roles.forEach(role => {
                    if (role.name.toLowerCase() === 'super admin') return;
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>${role.id}</td>
                        <td>${role.name}</td>
                        <td>
    <a href="role_form.php?id=${role.id}" class="btn btn-sm btn-warning mr-1" title="Edit"><i class="fas fa-edit"></i></a>
    <button class="btn btn-sm btn-info mr-1" title="Manage Permissions" onclick="openPermissionsModal(${role.id}, '${role.name.replace(/'/g, "&#39;")}')"><i class="fas fa-key"></i></button>
    <button class="btn btn-sm btn-danger" title="Delete" onclick="deleteRole(${role.id}, this)"><i class="fas fa-trash"></i></button>
</td>
                    `;
                    tbody.appendChild(tr);
                });
            } else {
                tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">No roles found.</td></tr>';
            }
        })
        .catch(() => {
            document.getElementById('rolesTbody').innerHTML = '<tr><td colspan="3" class="text-center text-danger">Error loading roles.</td></tr>';
        });
}
function deleteRole(id, btn) {
    if (!confirm('Are you sure you want to delete this role?')) return;
    btn.disabled = true;
    fetch('controllers/role_api.php', {
        method: 'DELETE',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id=' + encodeURIComponent(id)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showRoleAlert('Role deleted successfully.', 'success');
            fetchRoles();
        } else {
            showRoleAlert('Failed to delete role: ' + (data.error || 'Unknown error'), 'danger');
        }
    })
    .catch(() => {
        showRoleAlert('Error deleting role.', 'danger');
    })
    .finally(() => { btn.disabled = false; });
}
function showRoleAlert(msg, type) {
    const alertDiv = document.getElementById('roleAlert');
    alertDiv.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show" role="alert">
        ${msg}
        <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
    </div>`;
    setTimeout(() => { alertDiv.innerHTML = ''; }, 5000);
}
document.addEventListener('DOMContentLoaded', fetchRoles);

// Permissions Modal logic
function openPermissionsModal(roleId, roleName) {
    $('#permissionsRoleName').text(roleName);
    $('#permissionsRoleId').val(roleId);
    $('#permissionsModal').modal('show');
    loadRolePermissions(roleId);
}

function loadRolePermissions(roleId) {
    $('#permissionsList').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i> Loading permissions...</div>');
    
    // Load both permissions and categories
    Promise.all([
        fetch(BASE_URL + '/controllers/role_permission_api.php?role_id=' + encodeURIComponent(roleId)),
        fetch(BASE_URL + '/controllers/permission_categories_api.php')
    ])
    .then(responses => Promise.all(responses.map(r => r.json())))
    .then(([permData, catData]) => {
        if (!permData.success) {
            $('#permissionsList').html('<div class="alert alert-danger">Failed to load permissions.</div>');
            return;
        }
        
        const permissions = permData.permissions;
        const categories = catData.success ? catData.categories : {};
        
        // Create search and bulk actions
        let html = `
            <div class="mb-3">
                <div class="row">
                    <div class="col-md-8">
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                            </div>
                            <input type="text" class="form-control" id="permissionSearch" placeholder="Search permissions...">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="btn-group w-100">
                            <button type="button" class="btn btn-outline-success btn-sm" id="selectAllPerms">
                                <i class="fas fa-check-double"></i> Select All
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm" id="deselectAllPerms">
                                <i class="fas fa-times"></i> Clear All
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <form id="rolePermissionsForm">
                <div class="accordion" id="permissionsAccordion">
        `;
        
        // Group permissions by category
        const permissionsByCategory = {};
        const categoryIcons = {
            'Dashboard': 'fas fa-tachometer-alt',
            'Members': 'fas fa-users',
            'Attendance': 'fas fa-calendar-check',
            'Payments': 'fas fa-credit-card',
            'Reports': 'fas fa-chart-line',
            'Bible Classes': 'fas fa-book-open',
            'Class Groups': 'fas fa-layer-group',
            'Organizations': 'fas fa-building',
            'Events': 'fas fa-calendar-alt',
            'Feedback': 'fas fa-comments',
            'Health': 'fas fa-heartbeat',
            'SMS': 'fas fa-sms',
            'Visitors': 'fas fa-user-plus',
            'Sunday School': 'fas fa-church',
            'Transfers': 'fas fa-exchange-alt',
            'Roles & Permissions': 'fas fa-key',
            'Audit & Logs': 'fas fa-clipboard-list',
            'User Management': 'fas fa-user-cog',
            'AJAX/API': 'fas fa-code',
            'Bulk': 'fas fa-layer-group',
            'Advanced': 'fas fa-cogs',
            'System': 'fas fa-server'
        };
        
        // Initialize categories
        Object.keys(categories).forEach(cat => {
            permissionsByCategory[cat] = [];
        });
        permissionsByCategory['Other'] = [];
        
        // Categorize permissions
        permissions.forEach(perm => {
            let categorized = false;
            for (const [category, categoryPerms] of Object.entries(categories)) {
                if (categoryPerms.includes(perm.name)) {
                    permissionsByCategory[category].push(perm);
                    categorized = true;
                    break;
                }
            }
            if (!categorized) {
                permissionsByCategory['Other'].push(perm);
            }
        });
        
        // Generate accordion for each category
        let categoryIndex = 0;
        Object.entries(permissionsByCategory).forEach(([category, categoryPerms]) => {
            if (categoryPerms.length === 0) return;
            
            const categoryId = `category_${categoryIndex}`;
            const icon = categoryIcons[category] || 'fas fa-folder';
            const assignedCount = categoryPerms.filter(p => p.assigned).length;
            const totalCount = categoryPerms.length;
            
            html += `
                <div class="card">
                    <div class="card-header p-0" id="heading_${categoryId}">
                        <h6 class="mb-0">
                            <button class="btn btn-link btn-block text-left d-flex justify-content-between align-items-center" 
                                    type="button" data-toggle="collapse" data-target="#collapse_${categoryId}" 
                                    aria-expanded="true" aria-controls="collapse_${categoryId}">
                                <span>
                                    <i class="${icon} mr-2"></i>
                                    ${category}
                                    <small class="text-muted ml-2">(${assignedCount}/${totalCount})</small>
                                </span>
                                <div class="btn-group btn-group-sm" onclick="event.stopPropagation();">
                                    <button type="button" class="btn btn-outline-success btn-xs category-select-all" 
                                            data-category="${categoryId}" title="Select all in ${category}">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-xs category-select-none" 
                                            data-category="${categoryId}" title="Deselect all in ${category}">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </button>
                        </h6>
                    </div>
                    <div id="collapse_${categoryId}" class="collapse ${categoryIndex === 0 ? 'show' : ''}" 
                         aria-labelledby="heading_${categoryId}" data-parent="#permissionsAccordion">
                        <div class="card-body">
                            <div class="row">
            `;
            
            // Add permissions in this category
            categoryPerms.forEach((perm, index) => {
                const permDescription = getPermissionDescription(perm.name);
                html += `
                    <div class="col-md-6 mb-2 permission-item" data-permission-name="${perm.name.toLowerCase()}">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input category-${categoryId}" 
                                   id="perm_${perm.id}" name="permissions[]" value="${perm.id}" 
                                   ${perm.assigned ? 'checked' : ''}>
                            <label class="custom-control-label" for="perm_${perm.id}">
                                <strong>${formatPermissionName(perm.name)}</strong>
                                ${permDescription ? `<br><small class="text-muted">${permDescription}</small>` : ''}
                            </label>
                        </div>
                    </div>
                `;
            });
            
            html += `
                            </div>
                        </div>
                    </div>
                </div>
            `;
            categoryIndex++;
        });
        
        html += `
                </div>
            </form>
        `;
        
        $('#permissionsList').html(html);
        
        // Bind search functionality
        $('#permissionSearch').on('input', function() {
            const searchTerm = $(this).val().toLowerCase();
            $('.permission-item').each(function() {
                const permissionName = $(this).data('permission-name');
                if (permissionName.includes(searchTerm)) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        });
        
        // Bind bulk actions
        $('#selectAllPerms').on('click', function() {
            $('#rolePermissionsForm input[type="checkbox"]').prop('checked', true);
        });
        
        $('#deselectAllPerms').on('click', function() {
            $('#rolePermissionsForm input[type="checkbox"]').prop('checked', false);
        });
        
        // Bind category bulk actions
        $('.category-select-all').on('click', function() {
            const category = $(this).data('category');
            $(`.category-${category}`).prop('checked', true);
        });
        
        $('.category-select-none').on('click', function() {
            const category = $(this).data('category');
            $(`.category-${category}`).prop('checked', false);
        });
        
    })
    .catch(() => {
        $('#permissionsList').html('<div class="alert alert-danger">Error loading permissions.</div>');
    });
}

// Helper function to format permission names
function formatPermissionName(name) {
    return name.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
}

// Helper function to get permission descriptions
function getPermissionDescription(name) {
    const descriptions = {
        'view_dashboard': 'Access the main dashboard',
        'create_member': 'Add new church members',
        'edit_member': 'Modify member information',
        'delete_member': 'Remove members from the system',
        'view_payment_list': 'View all payment records',
        'make_payment': 'Process member payments',
        'send_sms': 'Send SMS messages to members',
        'view_reports_dashboard': 'Access reports overview',
        'manage_roles': 'Create and modify user roles',
        'manage_permissions': 'Assign permissions to roles',
        'backup_database': 'Create system backups',
        'restore_database': 'Restore from backups'
    };
    return descriptions[name] || '';
}

$('#savePermissionsBtn').on('click', function() {
    const roleId = $('#permissionsRoleId').val();
    const formData = $('#rolePermissionsForm').serialize() + '&role_id=' + encodeURIComponent(roleId);
    $('#savePermissionsBtn').prop('disabled', true).text('Saving...');
    fetch(BASE_URL + '/controllers/role_permission_api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            $('#permissionsModal').modal('hide');
            showRoleAlert('Permissions updated.', 'success');
        } else {
            $('#permissionsModalError').text('Failed to update permissions.');
        }
    })
    .catch(() => {
        $('#permissionsModalError').text('Error updating permissions.');
    })
    .finally(() => {
        $('#savePermissionsBtn').prop('disabled', false).text('Save Changes');
    });
});
</script>

<style>
.permission-item {
  transition: all 0.2s ease;
}

.permission-item:hover {
  background-color: #f8f9fa;
  border-radius: 4px;
  padding: 2px;
}

.custom-control-label {
  cursor: pointer;
  font-size: 0.9rem;
}

.custom-control-label strong {
  color: #495057;
}

.accordion .card {
  border: 1px solid #dee2e6;
  margin-bottom: 2px;
}

.accordion .card-header {
  background-color: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
}

.accordion .btn-link {
  color: #495057;
  text-decoration: none;
  font-weight: 500;
}

.accordion .btn-link:hover {
  color: #007bff;
  text-decoration: none;
}

.btn-xs {
  padding: 0.125rem 0.25rem;
  font-size: 0.75rem;
  line-height: 1.2;
  border-radius: 0.15rem;
}

#permissionSearch {
  border-radius: 0.375rem;
}

.input-group-text {
  background-color: #e9ecef;
  border-color: #ced4da;
}

.modal-xl {
  max-width: 1200px;
}

@media (max-width: 768px) {
  .modal-xl {
    max-width: 95%;
    margin: 1rem auto;
  }
  
  .permission-item {
    margin-bottom: 0.5rem;
  }
  
  .btn-group .btn {
    font-size: 0.8rem;
    padding: 0.25rem 0.5rem;
  }
}
</style>

<?php ob_start(); ?>
<!-- Permissions Modal -->
<div class="modal fade" id="permissionsModal" tabindex="-1" role="dialog" aria-labelledby="permissionsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl" role="document">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="permissionsModalLabel">
          <i class="fas fa-key mr-2"></i>Manage Permissions for <span id="permissionsRoleName"></span>
        </h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
        <input type="hidden" id="permissionsRoleId">
        <div id="permissionsModalError" class="alert alert-danger" style="display: none;"></div>
        <div id="permissionsList"></div>
      </div>
      <div class="modal-footer bg-light">
        <div class="d-flex justify-content-between w-100">
          <div class="text-muted small">
            <i class="fas fa-info-circle mr-1"></i>
            Use search to quickly find permissions, or use category bulk actions
          </div>
          <div>
            <button type="button" class="btn btn-secondary" data-dismiss="modal">
              <i class="fas fa-times mr-1"></i>Cancel
            </button>
            <button type="button" class="btn btn-primary" id="savePermissionsBtn">
              <i class="fas fa-save mr-1"></i>Save Changes
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php $modal_html = ob_get_clean(); ?>
<?php
$page_content = ob_get_clean();
echo $modal_html;
require_once __DIR__.'/../includes/layout.php';
