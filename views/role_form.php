<?php
/**
 * Role Management Form - Create/Edit Roles with Permissions
 * Modern, responsive role management interface
 */

session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/permissions.php';

// Authentication check
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// Super admin check
$is_super_admin = ($_SESSION['user_id'] == 3);

// Initialize variables
$role_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$editing = $role_id > 0;
$page_title = $editing ? 'Edit Role' : 'Create New Role';

// Data containers
$role = ['id' => 0, 'name' => '', 'description' => ''];
$permissions_map = [];
$assigned_permissions = [];
$categories = [];
$errors = [];

// Load permissions
try {
    $stmt = $conn->prepare('SELECT id, name, description FROM permissions ORDER BY name ASC');
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $permissions_map[$row['name']] = $row;
    }
} catch (Exception $e) {
    $errors[] = 'Failed to load permissions.';
    error_log('Load Permissions Error: ' . $e->getMessage());
}

// Load role data if editing
if ($editing && empty($errors)) {
    try {
        $stmt = $conn->prepare('SELECT * FROM roles WHERE id = ?');
        $stmt->bind_param('i', $role_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($role_data = $result->fetch_assoc()) {
            if (!$is_super_admin && strtolower($role_data['name']) === 'super admin') {
                $errors[] = 'Super Admin role cannot be modified.';
            } else {
                $role = $role_data;
                
                // Load assigned permissions
                $stmt = $conn->prepare('SELECT permission_id FROM role_permissions WHERE role_id = ?');
                $stmt->bind_param('i', $role_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                while ($row = $result->fetch_assoc()) {
                    $assigned_permissions[] = (int)$row['permission_id'];
                }
            }
        } else {
            $errors[] = 'Role not found.';
        }
    } catch (Exception $e) {
        $errors[] = 'Failed to load role data.';
        error_log('Load Role Error: ' . $e->getMessage());
    }
}

// Load permission categories
try {
    $categories = require __DIR__ . '/../helpers/permission_categories.php';
    
    // Ensure all permissions are categorized
    $categorized_perms = [];
    foreach ($categories as $cat_perms) {
        $categorized_perms = array_merge($categorized_perms, $cat_perms);
    }
    
    $uncategorized = [];
    foreach ($permissions_map as $perm_name => $perm_data) {
        if (!in_array($perm_name, $categorized_perms)) {
            $uncategorized[] = $perm_name;
        }
    }
    
    if (!empty($uncategorized)) {
        if (!isset($categories['Other'])) {
            $categories['Other'] = [];
        }
        $categories['Other'] = array_merge($categories['Other'], $uncategorized);
    }
} catch (Exception $e) {
    $categories = ['Other' => array_keys($permissions_map)];
    error_log('Load Categories Error: ' . $e->getMessage());
}

// Start output buffering
ob_start();
?>

<div class="role-form-wrapper">
    <div class="container-fluid">
        <div class="row justify-content-center">
            <div class="col-12 col-lg-10 col-xl-8">
                
                <!-- Page Header -->
                <div class="page-header mb-4">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center">
                        <div class="header-content mb-3 mb-md-0">
                            <h1 class="h2 mb-1 text-white">
                                <i class="fas fa-user-tag mr-2"></i>
                                <?= htmlspecialchars($page_title) ?>
                            </h1>
                            <p class="text-white-50 mb-0">
                                <?= $editing ? 'Modify role permissions and settings' : 'Create a new role with specific permissions' ?>
                            </p>
                        </div>
                        <div class="header-actions">
                            <a href="role_list.php" class="btn btn-outline-light">
                                <i class="fas fa-arrow-left mr-1"></i>
                                Back to Roles
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Alert Container -->
                <div id="alertContainer"></div>

                <!-- Error Display -->
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <strong>Error:</strong>
                        <ul class="mb-0 mt-2">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="close" data-dismiss="alert">
                            <span>&times;</span>
                        </button>
                    </div>
                <?php endif; ?>

                <!-- Main Form -->
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white border-bottom">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-cog text-secondary mr-2"></i>
                            Role Configuration
                        </h5>
                    </div>
                    
                    <div class="card-body p-4">
                        <form id="roleForm" novalidate>
                            <!-- Role Name -->
                            <div class="form-group mb-4">
                                <label for="roleName" class="form-label font-weight-medium">
                                    Role Name <span class="text-danger">*</span>
                                </label>
                                <input type="text" 
                                       class="form-control form-control-lg" 
                                       id="roleName" 
                                       name="name"
                                       value="<?= htmlspecialchars($role['name'] ?? '') ?>"
                                       placeholder="Enter role name (e.g., Administrator, Editor, Viewer)"
                                       maxlength="50"
                                       required>
                                <div class="invalid-feedback"></div>
                                <small class="form-text text-muted">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    Choose a descriptive name that clearly identifies the role's purpose.
                                </small>
                            </div>

                            <!-- Role Description -->
                            <div class="form-group mb-4">
                                <label for="roleDescription" class="form-label font-weight-medium">
                                    Description <span class="text-muted">(Optional)</span>
                                </label>
                                <textarea class="form-control" 
                                          id="roleDescription" 
                                          name="description"
                                          rows="3"
                                          placeholder="Describe the role's purpose and responsibilities..."
                                          maxlength="255"><?= htmlspecialchars($role['description'] ?? '') ?></textarea>
                                <small class="form-text text-muted">
                                    Provide additional context about this role's responsibilities.
                                </small>
                            </div>

                            <!-- Permissions Section -->
                            <div class="permissions-section">
                                <div class="section-header mb-3">
                                    <h6 class="section-title mb-2">
                                        <i class="fas fa-key text-warning mr-2"></i>
                                        Role Permissions <span class="text-danger">*</span>
                                    </h6>
                                    <p class="text-muted mb-3">
                                        Select the permissions this role should have. Users assigned to this role will inherit these permissions.
                                    </p>
                                </div>

                                <!-- Permission Controls -->
                                <div class="permission-controls mb-4">
                                    <div class="row align-items-center">
                                        <div class="col-md-6 mb-2 mb-md-0">
                                            <div class="input-group">
                                                <div class="input-group-prepend">
                                                    <span class="input-group-text bg-light">
                                                        <i class="fas fa-search text-muted"></i>
                                                    </span>
                                                </div>
                                                <input type="text" 
                                                       class="form-control" 
                                                       id="permissionSearch" 
                                                       placeholder="Search permissions...">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="btn-group w-100">
                                                <button type="button" 
                                                        class="btn btn-outline-success btn-sm" 
                                                        id="selectAllPermissions">
                                                    <i class="fas fa-check-square mr-1"></i>
                                                    Select All
                                                </button>
                                                <button type="button" 
                                                        class="btn btn-outline-danger btn-sm" 
                                                        id="clearAllPermissions">
                                                    <i class="fas fa-square mr-1"></i>
                                                    Clear All
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Permissions Accordion -->
                                <div class="permissions-accordion" id="permissionsAccordion">
                                    <?php 
                                    $categoryIndex = 0;
                                    $categoryIcons = [
                                        'Dashboard' => 'fas fa-tachometer-alt',
                                        'Members' => 'fas fa-users',
                                        'Attendance' => 'fas fa-calendar-check',
                                        'Payments' => 'fas fa-credit-card',
                                        'Reports' => 'fas fa-chart-line',
                                        'Bible Classes' => 'fas fa-book-open',
                                        'Organizations' => 'fas fa-building',
                                        'Events' => 'fas fa-calendar-alt',
                                        'Health' => 'fas fa-heartbeat',
                                        'SMS' => 'fas fa-sms',
                                        'Visitors' => 'fas fa-user-plus',
                                        'Roles & Permissions' => 'fas fa-key',
                                        'User Management' => 'fas fa-user-cog',
                                        'Other' => 'fas fa-folder'
                                    ];
                                    
                                    foreach ($categories as $category => $categoryPerms): 
                                        // Filter permissions that actually exist
                                        $validPerms = array_filter($categoryPerms, function($permName) use ($permissions_map) {
                                            return isset($permissions_map[$permName]);
                                        });
                                        
                                        if (empty($validPerms)) continue;
                                        
                                        $categoryId = 'category_' . $categoryIndex;
                                        $icon = $categoryIcons[$category] ?? 'fas fa-folder';
                                        $assignedCount = 0;
                                        
                                        // Count assigned permissions in this category
                                        foreach ($validPerms as $permName) {
                                            if (in_array($permissions_map[$permName]['id'], $assigned_permissions)) {
                                                $assignedCount++;
                                            }
                                        }
                                    ?>
                                        <div class="card border mb-2">
                                            <div class="card-header bg-light py-2 px-3">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <button class="btn btn-link text-left p-0 text-decoration-none flex-grow-1" 
                                                            type="button" 
                                                            data-toggle="collapse" 
                                                            data-target="#collapse_<?= $categoryId ?>">
                                                        <div class="d-flex align-items-center">
                                                            <i class="<?= $icon ?> text-primary mr-2"></i>
                                                            <span class="font-weight-medium text-dark">
                                                                <?= htmlspecialchars($category) ?>
                                                            </span>
                                                            <span class="badge badge-<?= $assignedCount > 0 ? 'primary' : 'secondary' ?> ml-2">
                                                                <?= $assignedCount ?>/<?= count($validPerms) ?>
                                                            </span>
                                                        </div>
                                                    </button>
                                                    
                                                    <div class="btn-group btn-group-sm ml-2">
                                                        <button type="button" 
                                                                class="btn btn-outline-success btn-sm category-select-all" 
                                                                data-category="<?= $categoryId ?>" 
                                                                onclick="event.stopPropagation()">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                        <button type="button" 
                                                                class="btn btn-outline-danger btn-sm category-select-none" 
                                                                data-category="<?= $categoryId ?>"
                                                                onclick="event.stopPropagation()">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div id="collapse_<?= $categoryId ?>" 
                                                 class="collapse <?= $categoryIndex === 0 ? 'show' : '' ?>">
                                                <div class="card-body py-3">
                                                    <div class="row">
                                                        <?php foreach ($validPerms as $permName): 
                                                            $perm = $permissions_map[$permName];
                                                            $isAssigned = in_array($perm['id'], $assigned_permissions);
                                                        ?>
                                                            <div class="col-lg-6 mb-3 permission-item" 
                                                                 data-permission-name="<?= strtolower($permName) ?>">
                                                                <div class="custom-control custom-checkbox">
                                                                    <input type="checkbox" 
                                                                           class="custom-control-input category-<?= $categoryId ?>" 
                                                                           id="perm_<?= $perm['id'] ?>" 
                                                                           name="permissions[]" 
                                                                           value="<?= $perm['id'] ?>" 
                                                                           <?= $isAssigned ? 'checked' : '' ?>>
                                                                    <label class="custom-control-label" for="perm_<?= $perm['id'] ?>">
                                                                        <div class="permission-label">
                                                                            <div class="permission-name font-weight-medium">
                                                                                <?= htmlspecialchars(ucwords(str_replace('_', ' ', $permName))) ?>
                                                                            </div>
                                                                            <?php if (!empty($perm['description'])): ?>
                                                                                <div class="permission-description text-muted small">
                                                                                    <?= htmlspecialchars($perm['description']) ?>
                                                                                </div>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php 
                                        $categoryIndex++;
                                    endforeach; 
                                    ?>
                                </div>
                                
                                <div class="invalid-feedback d-block" id="permissionsError" style="display: none;"></div>
                                <small class="form-text text-muted mt-2">
                                    <i class="fas fa-shield-alt mr-1"></i>
                                    Select permissions carefully. Users with this role will have access to all selected features.
                                </small>
                            </div>

                            <!-- Form Actions -->
                            <div class="form-actions mt-5 pt-4 border-top">
                                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center">
                                    <div class="form-info mb-3 mb-sm-0">
                                        <small class="text-muted">
                                            <i class="fas fa-info-circle mr-1"></i>
                                            <?php if ($editing): ?>
                                                Last updated: <?= isset($role['updated_at']) ? date('M j, Y g:i A', strtotime($role['updated_at'])) : 'Never' ?>
                                            <?php else: ?>
                                                All fields marked with <span class="text-danger">*</span> are required.
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <div class="form-buttons">
                                        <button type="button" 
                                                class="btn btn-outline-secondary mr-2" 
                                                onclick="window.location.href='role_list.php'">
                                            <i class="fas fa-times mr-1"></i>
                                            Cancel
                                        </button>
                                        <button type="submit" 
                                                class="btn btn-primary" 
                                                id="saveRoleBtn">
                                            <i class="fas fa-save mr-1"></i>
                                            <?= $editing ? 'Update Role' : 'Create Role' ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$page_content = ob_get_clean();

// Additional CSS for modern styling and layout fixes
$additional_css = '
<style>
.role-form-wrapper {
    margin-left: 0;
    padding: 1rem;
    min-height: 100vh;
    background-color: #f8f9fa;
}

.page-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem;
    border-radius: 0.5rem;
    margin-bottom: 2rem;
}

.card {
    border: none;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    border-radius: 0.5rem;
}

.form-control-lg {
    padding: 0.75rem 1rem;
    font-size: 1.1rem;
}

.permission-controls {
    background-color: #f8f9fa;
    padding: 1rem;
    border-radius: 0.375rem;
    border: 1px solid #e9ecef;
}

.permissions-accordion .card {
    border: 1px solid #dee2e6;
    margin-bottom: 0.5rem;
}

.permission-item:hover {
    background-color: rgba(0, 123, 255, 0.05);
    border-radius: 0.25rem;
    padding: 0.25rem;
    margin: -0.25rem;
}

.form-actions {
    background-color: #f8f9fa;
    margin: 0 -1.5rem -1.5rem;
    padding: 1.5rem;
    border-radius: 0 0 0.5rem 0.5rem;
}

@media (max-width: 768px) {
    .role-form-wrapper {
        padding: 0.5rem;
    }
    
    .page-header {
        padding: 1.5rem;
        text-align: center;
    }
    
    .permission-item .col-lg-6 {
        flex: 0 0 100%;
        max-width: 100%;
    }
}
</style>
';

// Additional JavaScript for enhanced functionality
$additional_js = '
<script>
const EDITING = ' . ($editing ? 'true' : 'false') . ';
const ROLE_ID = ' . $role_id . ';
const BASE_URL = "' . rtrim(BASE_URL, '/') . '";

document.addEventListener("DOMContentLoaded", function() {
    initializeRoleForm();
});

function initializeRoleForm() {
    const form = document.getElementById("roleForm");
    const searchInput = document.getElementById("permissionSearch");
    const selectAllBtn = document.getElementById("selectAllPermissions");
    const clearAllBtn = document.getElementById("clearAllPermissions");
    
    form.addEventListener("submit", handleFormSubmit);
    searchInput.addEventListener("input", filterPermissions);
    selectAllBtn.addEventListener("click", selectAllPermissions);
    clearAllBtn.addEventListener("click", clearAllPermissions);
    
    // Category bulk actions
    document.querySelectorAll(".category-select-all").forEach(btn => {
        btn.addEventListener("click", function() {
            const category = this.dataset.category;
            document.querySelectorAll(`.category-${category}`).forEach(cb => cb.checked = true);
        });
    });
    
    document.querySelectorAll(".category-select-none").forEach(btn => {
        btn.addEventListener("click", function() {
            const category = this.dataset.category;
            document.querySelectorAll(`.category-${category}`).forEach(cb => cb.checked = false);
        });
    });
}

function handleFormSubmit(e) {
    e.preventDefault();
    
    if (!validateForm()) return;
    
    const saveBtn = document.getElementById("saveRoleBtn");
    const originalText = saveBtn.innerHTML;
    
    saveBtn.disabled = true;
    saveBtn.innerHTML = `<i class="fas fa-spinner fa-spin mr-1"></i>Saving...`;
    
    const formData = {
        name: document.getElementById("roleName").value.trim(),
        description: document.getElementById("roleDescription").value.trim(),
        permissions: Array.from(document.querySelectorAll("input[name=\\"permissions[]\\"]:checked"))
                         .map(cb => parseInt(cb.value))
    };
    
    if (EDITING) formData.id = ROLE_ID;
    
    const url = BASE_URL + "/controllers/role_api.php" + (EDITING ? `?id=${ROLE_ID}&action=update` : "");
    
    fetch(url, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-Requested-With": "XMLHttpRequest"
        },
        body: JSON.stringify(formData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert("Role saved successfully!", "success");
            setTimeout(() => window.location.href = "role_list.php", 1500);
        } else {
            showAlert(data.error || "Failed to save role.", "danger");
        }
    })
    .catch(error => {
        console.error("Error:", error);
        showAlert("An error occurred while saving.", "danger");
    })
    .finally(() => {
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalText;
    });
}

function validateForm() {
    let isValid = true;
    
    const roleName = document.getElementById("roleName").value.trim();
    const roleNameInput = document.getElementById("roleName");
    
    if (!roleName) {
        roleNameInput.classList.add("is-invalid");
        roleNameInput.nextElementSibling.textContent = "Role name is required.";
        isValid = false;
    } else if (roleName.length > 50) {
        roleNameInput.classList.add("is-invalid");
        roleNameInput.nextElementSibling.textContent = "Role name must be 50 characters or less.";
        isValid = false;
    } else {
        roleNameInput.classList.remove("is-invalid");
        roleNameInput.classList.add("is-valid");
    }
    
    const checkedPermissions = document.querySelectorAll("input[name=\\"permissions[]\\"]:checked");
    const permissionsError = document.getElementById("permissionsError");
    
    if (checkedPermissions.length === 0) {
        permissionsError.textContent = "Please select at least one permission.";
        permissionsError.style.display = "block";
        isValid = false;
    } else {
        permissionsError.style.display = "none";
    }
    
    return isValid;
}

function filterPermissions() {
    const searchTerm = document.getElementById("permissionSearch").value.toLowerCase();
    const permissionItems = document.querySelectorAll(".permission-item");
    
    permissionItems.forEach(item => {
        const permissionName = item.dataset.permissionName;
        const labelText = item.querySelector("label").textContent.toLowerCase();
        const isVisible = !searchTerm || 
                         permissionName.includes(searchTerm) || 
                         labelText.includes(searchTerm);
        item.style.display = isVisible ? "" : "none";
    });
    
    document.querySelectorAll(".card").forEach(card => {
        const visibleItems = card.querySelectorAll(".permission-item:not([style*=\\"display: none\\"])");
        card.style.display = visibleItems.length > 0 ? "" : "none";
    });
}

function selectAllPermissions() {
    document.querySelectorAll("input[name=\\"permissions[]\\"]:not([style*=\\"display: none\\"])").forEach(cb => cb.checked = true);
}

function clearAllPermissions() {
    document.querySelectorAll("input[name=\\"permissions[]\\"]:not([style*=\\"display: none\\"])").forEach(cb => cb.checked = false);
}

function showAlert(message, type) {
    const alertContainer = document.getElementById("alertContainer");
    const alertId = "alert_" + Date.now();
    
    const alertHTML = `
        <div id="${alertId}" class="alert alert-${type} alert-dismissible fade show" role="alert">
            <i class="fas fa-${type === "success" ? "check-circle" : "exclamation-triangle"} mr-2"></i>
            ${message}
            <button type="button" class="close" data-dismiss="alert">
                <span>&times;</span>
            </button>
        </div>
    `;
    
    alertContainer.innerHTML = alertHTML;
    
    setTimeout(() => {
        const alert = document.getElementById(alertId);
        if (alert) alert.remove();
    }, 5000);
}
</script>
';

// Include layout with proper variables
require __DIR__ . '/../includes/layout.php';
?>