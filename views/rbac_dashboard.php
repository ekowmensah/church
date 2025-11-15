<?php
/**
 * RBAC Management Dashboard
 * Comprehensive interface for managing roles, permissions, and audit logs
 */

session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';

// Authentication check
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('manage_roles')) {
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    exit;
}

$page_title = 'RBAC Management Dashboard';
ob_start();
?>

<div class="rbac-dashboard">
    <!-- Header -->
    <div class="dashboard-header mb-4">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="h3 mb-1">
                    <i class="fas fa-shield-alt mr-2"></i>
                    RBAC Management Dashboard
                </h1>
                <p class="text-muted mb-0">Manage roles, permissions, and access control</p>
            </div>
            <div>
                <a href="role_form.php" class="btn btn-primary">
                    <i class="fas fa-plus mr-1"></i> New Role
                </a>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card stats-card border-left-primary">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Total Roles
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800" id="totalRoles">
                                <i class="fas fa-spinner fa-spin"></i>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-user-tag fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card stats-card border-left-success">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Total Permissions
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800" id="totalPermissions">
                                <i class="fas fa-spinner fa-spin"></i>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-key fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card stats-card border-left-info">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Active Users
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800" id="activeUsers">
                                <i class="fas fa-spinner fa-spin"></i>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-users fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card stats-card border-left-warning">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Audit Logs (7d)
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800" id="auditLogs">
                                <i class="fas fa-spinner fa-spin"></i>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-clipboard-list fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Tabs -->
    <div class="card shadow">
        <div class="card-header">
            <ul class="nav nav-tabs card-header-tabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" data-toggle="tab" href="#rolesTab" role="tab">
                        <i class="fas fa-user-tag mr-1"></i> Roles
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-toggle="tab" href="#permissionsTab" role="tab">
                        <i class="fas fa-key mr-1"></i> Permissions
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-toggle="tab" href="#auditTab" role="tab">
                        <i class="fas fa-clipboard-list mr-1"></i> Audit Logs
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-toggle="tab" href="#templatesTab" role="tab">
                        <i class="fas fa-copy mr-1"></i> Templates
                    </a>
                </li>
            </ul>
        </div>

        <div class="card-body">
            <div class="tab-content">
                <!-- Roles Tab -->
                <div class="tab-pane fade show active" id="rolesTab" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Role Management</h5>
                        <a href="role_list.php" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-external-link-alt mr-1"></i> Full View
                        </a>
                    </div>
                    <div id="rolesContent">
                        <div class="text-center py-4">
                            <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
                            <p class="text-muted mt-2">Loading roles...</p>
                        </div>
                    </div>
                </div>

                <!-- Permissions Tab -->
                <div class="tab-pane fade" id="permissionsTab" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Permission Categories</h5>
                        <input type="text" id="permissionSearch" class="form-control form-control-sm" style="max-width: 300px;" placeholder="Search permissions...">
                    </div>
                    <div id="permissionsContent">
                        <div class="text-center py-4">
                            <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
                            <p class="text-muted mt-2">Loading permissions...</p>
                        </div>
                    </div>
                </div>

                <!-- Audit Tab -->
                <div class="tab-pane fade" id="auditTab" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Recent Audit Logs</h5>
                        <select id="auditFilter" class="form-control form-control-sm" style="max-width: 200px;">
                            <option value="all">All Actions</option>
                            <option value="permission_check">Permission Checks</option>
                            <option value="permission_grant">Permission Grants</option>
                            <option value="permission_revoke">Permission Revokes</option>
                            <option value="role_create">Role Created</option>
                            <option value="role_update">Role Updated</option>
                            <option value="role_delete">Role Deleted</option>
                        </select>
                    </div>
                    <div id="auditContent">
                        <div class="text-center py-4">
                            <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
                            <p class="text-muted mt-2">Loading audit logs...</p>
                        </div>
                    </div>
                </div>

                <!-- Templates Tab -->
                <div class="tab-pane fade" id="templatesTab" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Role Templates</h5>
                        <select id="templateCategory" class="form-control form-control-sm" style="max-width: 200px;">
                            <option value="">All Categories</option>
                            <option value="church">Church</option>
                            <option value="ministry">Ministry</option>
                        </select>
                    </div>
                    <div id="templatesContent">
                        <div class="text-center py-4">
                            <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
                            <p class="text-muted mt-2">Loading templates...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$parsed = parse_url(BASE_URL);
$AJAX_BASE = isset($parsed['path']) ? rtrim($parsed['path'], '/') : '';
?>

<script>
const BASE_URL = "<?= $AJAX_BASE ?>";
const API_BASE = BASE_URL + '/api/rbac';

// Load statistics
function loadStatistics() {
    // Load roles count
    fetch(API_BASE + '/roles.php')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('totalRoles').textContent = data.data.total;
            }
        });

    // Load permissions count
    fetch(API_BASE + '/permissions.php')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('totalPermissions').textContent = data.data.total;
            }
        });

    // Load audit statistics
    fetch(API_BASE + '/audit.php?stats')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('activeUsers').textContent = data.data.statistics.unique_users;
                document.getElementById('auditLogs').textContent = data.data.statistics.total_entries;
            }
        });
}

// Load roles
function loadRoles() {
    fetch(API_BASE + '/roles.php')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const roles = data.data.roles;
                let html = '<div class="table-responsive"><table class="table table-hover"><thead><tr><th>Role</th><th>Users</th><th>Permissions</th><th>Actions</th></tr></thead><tbody>';
                
                roles.forEach(role => {
                    html += `
                        <tr>
                            <td><strong>${role.name}</strong><br><small class="text-muted">${role.description || 'No description'}</small></td>
                            <td><span class="badge badge-info">${role.user_count}</span></td>
                            <td><span class="badge badge-success">${role.permission_count}</span></td>
                            <td>
                                <a href="role_form.php?id=${role.id}" class="btn btn-sm btn-warning" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                            </td>
                        </tr>
                    `;
                });
                
                html += '</tbody></table></div>';
                document.getElementById('rolesContent').innerHTML = html;
            }
        });
}

// Load permissions (optimized - only show summary)
function loadPermissions() {
    console.log('Loading permissions from:', API_BASE + '/permissions.php?grouped=true');
    
    fetch(API_BASE + '/permissions.php?grouped=true')
        .then(r => {
            console.log('Response status:', r.status);
            if (!r.ok) {
                throw new Error('HTTP ' + r.status);
            }
            return r.json();
        })
        .then(data => {
            console.log('Permissions data:', data);
            console.log('Performance:', data.data.performance);
            
            if (data.success) {
                const grouped = data.data.permissions;
                const performance = data.data.performance;
                let html = '<div class="row">';
                
                Object.entries(grouped).forEach(([category, perms]) => {
                    html += `
                        <div class="col-md-4 mb-3">
                            <div class="card">
                                <div class="card-body">
                                    <h6 class="card-title">${category}</h6>
                                    <p class="card-text">
                                        <span class="badge badge-primary">${perms.length} permissions</span>
                                    </p>
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                html += '</div>';
                if (performance) {
                    html += `<div class="alert alert-success mt-3"><i class="fas fa-bolt mr-2"></i>Loaded in ${performance.total_time} (Query: ${performance.query_time})</div>`;
                }
                html += '<div class="alert alert-info mt-3"><i class="fas fa-info-circle mr-2"></i>For detailed permission management, use the <a href="permission_list.php">Permission List</a> page.</div>';
                document.getElementById('permissionsContent').innerHTML = html;
            } else {
                console.error('API returned success=false:', data);
                document.getElementById('permissionsContent').innerHTML = '<div class="alert alert-danger">Error: ' + (data.error || 'Unknown error') + '</div>';
            }
        })
        .catch(err => {
            console.error('Error loading permissions:', err);
            document.getElementById('permissionsContent').innerHTML = '<div class="alert alert-danger">Error loading permissions: ' + err.message + '<br><small>Check browser console for details</small></div>';
        });
}

// Load audit logs (optimized - limit to 10)
function loadAuditLogs(action = 'all') {
    let url = API_BASE + '/audit.php?limit=10';
    if (action !== 'all') {
        url += '&action=' + action;
    }
    
    console.log('Loading audit logs from:', url);
    
    fetch(url)
        .then(r => {
            console.log('Audit response status:', r.status);
            if (!r.ok) {
                throw new Error('HTTP ' + r.status);
            }
            return r.json();
        })
        .then(data => {
            console.log('Audit data:', data);
            
            if (data.success) {
                const logs = data.data.logs;
                if (logs.length === 0) {
                    document.getElementById('auditContent').innerHTML = '<div class="alert alert-info">No audit logs found</div>';
                    return;
                }
                
                let html = '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Time</th><th>User</th><th>Action</th><th>Details</th></tr></thead><tbody>';
                
                logs.forEach(log => {
                    const date = new Date(log.created_at);
                    html += `
                        <tr>
                            <td><small>${date.toLocaleString()}</small></td>
                            <td><small>${log.user_name || 'Unknown'}</small></td>
                            <td><span class="badge badge-secondary">${log.action}</span></td>
                            <td><small>${log.permission_name || log.details || '-'}</small></td>
                        </tr>
                    `;
                });
                
                html += '</tbody></table></div>';
                html += '<div class="alert alert-info mt-3"><i class="fas fa-info-circle mr-2"></i>Showing last 10 entries. For full audit logs, use the <a href="audit_list.php">Audit List</a> page.</div>';
                document.getElementById('auditContent').innerHTML = html;
            } else {
                console.error('Audit API returned success=false:', data);
                document.getElementById('auditContent').innerHTML = '<div class="alert alert-danger">Error: ' + (data.error || 'Unknown error') + '</div>';
            }
        })
        .catch(err => {
            console.error('Error loading audit logs:', err);
            document.getElementById('auditContent').innerHTML = '<div class="alert alert-danger">Error loading audit logs: ' + err.message + '</div>';
        });
}

// Load templates (optimized)
function loadTemplates(category = '') {
    let url = API_BASE + '/templates.php';
    if (category) {
        url += '?category=' + category;
    }
    
    console.log('Loading templates from:', url);
    
    fetch(url)
        .then(r => {
            console.log('Templates response status:', r.status);
            if (!r.ok) {
                throw new Error('HTTP ' + r.status);
            }
            return r.json();
        })
        .then(data => {
            console.log('Templates data:', data);
            
            if (data.success) {
                const templates = data.data.templates;
                let html = '<div class="row">';
                
                templates.forEach(template => {
                    html += `
                        <div class="col-md-6 mb-3">
                            <div class="card">
                                <div class="card-body">
                                    <h6 class="card-title">${template.name}</h6>
                                    <p class="card-text text-muted small">${template.description}</p>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="badge badge-info">${template.permission_count} permissions</span>
                                        <span class="badge badge-secondary">${template.category}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                html += '</div>';
                document.getElementById('templatesContent').innerHTML = html;
            } else {
                console.error('Templates API returned success=false:', data);
                document.getElementById('templatesContent').innerHTML = '<div class="alert alert-danger">Error: ' + (data.error || 'Unknown error') + '</div>';
            }
        })
        .catch(err => {
            console.error('Error loading templates:', err);
            document.getElementById('templatesContent').innerHTML = '<div class="alert alert-danger">Error loading templates: ' + err.message + '</div>';
        });
}

// Event listeners
$(document).ready(function() {
    console.log('Dashboard loaded');
    loadStatistics();
    loadRoles();
    
    // Tab change events using jQuery (Bootstrap tabs)
    $('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
        const target = $(e.target).attr('href');
        console.log('Tab switched to:', target);
        
        if (target === '#permissionsTab') {
            console.log('Loading permissions...');
            loadPermissions();
        } else if (target === '#auditTab') {
            console.log('Loading audit logs...');
            loadAuditLogs();
        } else if (target === '#templatesTab') {
            console.log('Loading templates...');
            loadTemplates();
        }
    });
    
    // Filters
    $('#auditFilter').on('change', function() {
        loadAuditLogs(this.value);
    });
    
    $('#templateCategory').on('change', function() {
        loadTemplates(this.value);
    });
});
</script>

<style>
.stats-card {
    border-left: 4px solid;
}
.border-left-primary {
    border-left-color: #4e73df !important;
}
.border-left-success {
    border-left-color: #1cc88a !important;
}
.border-left-info {
    border-left-color: #36b9cc !important;
}
.border-left-warning {
    border-left-color: #f6c23e !important;
}
.nav-tabs .nav-link {
    color: #6c757d;
}
.nav-tabs .nav-link.active {
    color: #495057;
    font-weight: 600;
}
</style>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
?>
