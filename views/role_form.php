<?php
/**
 * Role Management Form - Create/Edit Roles with Permissions
 * Modern, responsive role management interface
 */

//session_start();
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
$errors = [];

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
            }
        } else {
            $errors[] = 'Role not found.';
        }
    } catch (Exception $e) {
        $errors[] = 'Failed to load role data.';
        error_log('Load Role Error: ' . $e->getMessage());
    }
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
                                <?= $editing ? 'Modify role settings' : 'Create a new role' ?>
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
                        <form id="roleForm" novalidate action="#" method="post">
                            <!-- Role Name -->
                            <div class="form-group mb-4">
                                <label for="roleName" class="form-label font-weight-medium">
                                    Role Name <span class="text-danger">*</span>
                                </label>
                                <input type="text" id="roleName" name="name" class="form-control form-control-lg" maxlength="50" required value="<?= htmlspecialchars($role['name']) ?>">
                                <div class="invalid-feedback"></div>
                                <small class="form-text text-muted">Choose a descriptive name that clearly identifies the role's purpose.</small>
                            </div>

                            <!-- Role Description -->
                            <div class="form-group mb-4">
                                <label for="roleDescription" class="form-label font-weight-medium">
                                    Description <span class="text-muted">(Optional)</span>
                                </label>
                                <textarea id="roleDescription" name="description" class="form-control form-control-lg" maxlength="255" rows="2"><?= htmlspecialchars($role['description']) ?></textarea>
                                <div class="invalid-feedback"></div>
                                <small class="form-text text-muted">Provide additional context about this role's responsibilities.</small>
                            </div>

                            <div class="form-actions d-flex justify-content-end mt-4">
                                <button type="button" class="btn btn-primary btn-lg" id="saveRoleBtn">
                                    <i class="fas fa-save mr-2"></i>Save Role
                                </button>
                                <a href="role_list.php" class="btn btn-secondary btn-lg ml-2">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const EDITING = <?= json_encode($editing) ?>;
const ROLE_ID = <?= json_encode($role_id) ?>;
const BASE_URL = <?= json_encode(rtrim(BASE_URL, '/')) ?>;

document.addEventListener("DOMContentLoaded", function() {
    const form = document.getElementById("roleForm");
    const saveBtn = document.getElementById("saveRoleBtn");
    const roleNameInput = document.getElementById("roleName");
    const roleDescriptionInput = document.getElementById("roleDescription");
    let lastDuplicateCheck = { value: roleNameInput.value.trim(), exists: false };
    let checkingDuplicate = false;
    let submitting = false;

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

    // Real-time duplicate check
    roleNameInput.addEventListener("input", function() {
        const name = roleNameInput.value.trim();
        if (!name) {
            setInvalid("Role name is required.");
            lastDuplicateCheck = { value: name, exists: false };
            return;
        }
        checkingDuplicate = true;
        fetch("ajax_validate_role.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: `name=${encodeURIComponent(name)}&id=${encodeURIComponent(ROLE_ID)}`
        })
        .then(res => res.json())
        .then(data => {
            lastDuplicateCheck = { value: name, exists: data.exists };
            if (data.exists) setInvalid("Role name already exists.");
            else setValid();
        })
        .finally(() => { checkingDuplicate = false; });
    });

    saveBtn.addEventListener("click", function() {
        if (submitting) return;
        const name = roleNameInput.value.trim();
        const desc = roleDescriptionInput.value.trim();
        // Validate name
        if (!name) {
            setInvalid("Role name is required.");
            roleNameInput.focus();
            return;
        }
        if (name.length > 50) {
            setInvalid("Role name must be 50 characters or less.");
            roleNameInput.focus();
            return;
        }
        // If duplicate check not run or value changed, force check
        if (lastDuplicateCheck.value !== name) {
            checkingDuplicate = true;
            fetch("ajax_validate_role.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: `name=${encodeURIComponent(name)}&id=${encodeURIComponent(ROLE_ID)}`
            })
            .then(res => res.json())
            .then(data => {
                lastDuplicateCheck = { value: name, exists: data.exists };
                if (data.exists) {
                    setInvalid("Role name already exists.");
                    roleNameInput.focus();
                    return;
                } else {
                    setValid();
                    doSubmit(name, desc);
                }
            })
            .finally(() => { checkingDuplicate = false; });
            return;
        }
        if (lastDuplicateCheck.exists) {
            setInvalid("Role name already exists.");
            roleNameInput.focus();
            return;
        }
        doSubmit(name, desc);
    });

    function doSubmit(name, desc) {
        submitting = true;
        saveBtn.disabled = true;
        const originalText = saveBtn.innerHTML;
        saveBtn.innerHTML = `<i class=\"fas fa-spinner fa-spin mr-1\"></i>Saving...`;
        const formData = {
            name: name,
            description: desc
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
            submitting = false;
        });
    }

    function setInvalid(msg) {
        roleNameInput.classList.add("is-invalid");
        roleNameInput.classList.remove("is-valid");
        roleNameInput.nextElementSibling.textContent = msg;
    }
    function setValid() {
        roleNameInput.classList.remove("is-invalid");
        roleNameInput.classList.add("is-valid");
        roleNameInput.nextElementSibling.textContent = "";
    }

    // Prevent form submit fallback
    form.addEventListener("submit", function(e) { e.preventDefault(); });
});
</script>

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
}
</style>
';

// Additional JavaScript for enhanced functionality
$additional_js = '
<script>
</script>
';

// Include layout with proper variables
require __DIR__ . '/../includes/layout.php';
?>