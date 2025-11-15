# RBAC v2.0 - Quick Start Guide

## 🚀 Get Started in 5 Minutes

### Step 1: Include the Helper File

```php
<?php
// At the top of your PHP file
require_once __DIR__ . '/helpers/permissions_v2.php';
```

### Step 2: Check Permissions

```php
// Simple permission check
if (has_permission('edit_member')) {
    // User can edit members
    echo "You can edit members!";
}

// Check with specific user
if (has_permission('delete_member', $userId)) {
    // Specific user can delete members
}

// Context-aware permission
if (has_permission('edit_member_in_own_class', null, ['class_id' => 5])) {
    // User can edit members in their class
}
```

### Step 3: Protect Your Pages

```php
<?php
require_once 'helpers/permissions_v2.php';

// Require permission or show 403 error
require_permission('manage_roles');

// Rest of your page code here
?>
```

---

## 📚 Common Use Cases

### 1. Protect a Page
```php
<?php
require_once 'helpers/permissions_v2.php';
require_permission('view_reports');
?>
<!DOCTYPE html>
<html>
<head><title>Reports</title></head>
<body>
    <h1>Reports Dashboard</h1>
    <!-- Your content -->
</body>
</html>
```

### 2. Conditional UI Elements
```php
<!-- Show edit button only if user has permission -->
<?php if (has_permission('edit_member')): ?>
    <button onclick="editMember(<?= $memberId ?>)">Edit</button>
<?php endif; ?>

<!-- Show delete button only if user has permission -->
<?php if (has_permission('delete_member')): ?>
    <button onclick="deleteMember(<?= $memberId ?>)">Delete</button>
<?php endif; ?>
```

### 3. Check Multiple Permissions
```php
// User must have ALL permissions
if (has_all_permissions(['view_reports', 'export_reports'])) {
    echo '<button onclick="exportReport()">Export</button>';
}

// User must have ANY permission
if (has_any_permission(['edit_member', 'delete_member'])) {
    echo '<button onclick="manageMember()">Manage</button>';
}
```

### 4. Check User Role
```php
// Check if user has specific role
if (has_role('Admin')) {
    echo "Welcome, Admin!";
}

// Check if super admin
if (is_super_admin()) {
    echo "Welcome, Super Admin!";
}
```

### 5. Get User Information
```php
// Get all user permissions
$permissions = get_user_permissions();
foreach ($permissions as $perm) {
    echo $perm['name'] . '<br>';
}

// Get user roles
$roles = get_user_roles();
foreach ($roles as $role) {
    echo $role['name'] . '<br>';
}
```

---

## 🔧 Advanced Usage

### Using Services Directly

```php
<?php
require_once 'services/rbac/RBACServiceFactory.php';

// Initialize (once per request)
RBACServiceFactory::setConnection($conn);

// Get services
$checker = RBACServiceFactory::getPermissionChecker();
$roleService = RBACServiceFactory::getRoleService();
$permService = RBACServiceFactory::getPermissionService();

// Use services
if ($checker->hasPermission('edit_member', $userId)) {
    // Do something
}
```

### Create a New Role

```php
$roleService = RBACServiceFactory::getRoleService();

$roleId = $roleService->createRole([
    'name' => 'Content Manager',
    'description' => 'Manages website content',
    'parent_id' => null // or parent role ID
], $_SESSION['user_id']);

echo "Role created with ID: $roleId";
```

### Grant Permissions to Role

```php
$roleService = RBACServiceFactory::getRoleService();

// Grant single permission
$roleService->grantPermission($roleId, $permissionId, $_SESSION['user_id']);

// Or sync multiple permissions at once
$permissionIds = [1, 2, 3, 5, 8, 13];
$roleService->syncPermissions($roleId, $permissionIds, $_SESSION['user_id']);
```

### Create Role from Template

```php
$templateService = RBACServiceFactory::getRoleTemplateService();

// Create role from "Cashier" template
$roleId = $templateService->createRoleFromTemplate(
    2, // Template ID (2 = Cashier)
    'Senior Cashier', // Custom name
    $_SESSION['user_id']
);
```

### View Audit Logs

```php
$auditLogger = RBACServiceFactory::getAuditLogger();

// Get recent audit logs
$logs = $auditLogger->getAuditLogs([
    'actor_user_id' => $_SESSION['user_id'],
    'date_from' => date('Y-m-d', strtotime('-7 days'))
], 50, 0);

foreach ($logs as $log) {
    echo "{$log['action']} on {$log['target_type']} at {$log['created_at']}<br>";
}
```

---

## 🎯 Real-World Examples

### Example 1: Member Management Page

```php
<?php
require_once 'helpers/permissions_v2.php';

// Require view permission
require_permission('view_member');

// Check edit permission for UI
$canEdit = has_permission('edit_member');
$canDelete = has_permission('delete_member');
$canExport = has_permission('export_member');
?>
<!DOCTYPE html>
<html>
<head><title>Member Management</title></head>
<body>
    <h1>Members</h1>
    
    <?php if ($canExport): ?>
        <button onclick="exportMembers()">Export to Excel</button>
    <?php endif; ?>
    
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <?php if ($canEdit || $canDelete): ?>
                    <th>Actions</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($members as $member): ?>
                <tr>
                    <td><?= htmlspecialchars($member['name']) ?></td>
                    <td><?= htmlspecialchars($member['email']) ?></td>
                    <?php if ($canEdit || $canDelete): ?>
                        <td>
                            <?php if ($canEdit): ?>
                                <a href="edit_member.php?id=<?= $member['id'] ?>">Edit</a>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                                <a href="delete_member.php?id=<?= $member['id'] ?>">Delete</a>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
```

### Example 2: API Endpoint Protection

```php
<?php
require_once 'helpers/permissions_v2.php';

header('Content-Type: application/json');

// Check permission
if (!has_permission('api_access')) {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

// Check specific API permission
if (!has_permission('api_member_create')) {
    http_response_code(403);
    echo json_encode(['error' => 'Cannot create members via API']);
    exit;
}

// Process API request
$data = json_decode(file_get_contents('php://input'), true);
// ... create member logic ...

echo json_encode(['success' => true, 'member_id' => $memberId]);
```

### Example 3: Admin Dashboard

```php
<?php
require_once 'helpers/permissions_v2.php';

// Require admin access
require_any_permission(['view_dashboard', 'admin_access']);

// Get user info
$roles = get_user_roles();
$permissions = get_user_permissions();
$isSuperAdmin = is_super_admin();
?>
<!DOCTYPE html>
<html>
<head><title>Admin Dashboard</title></head>
<body>
    <h1>Dashboard</h1>
    
    <?php if ($isSuperAdmin): ?>
        <div class="alert alert-info">
            You are logged in as Super Admin
        </div>
    <?php endif; ?>
    
    <h2>Your Roles</h2>
    <ul>
        <?php foreach ($roles as $role): ?>
            <li><?= htmlspecialchars($role['name']) ?></li>
        <?php endforeach; ?>
    </ul>
    
    <h2>Quick Actions</h2>
    <div class="actions">
        <?php if (has_permission('view_member')): ?>
            <a href="members.php" class="btn">Manage Members</a>
        <?php endif; ?>
        
        <?php if (has_permission('view_payment_list')): ?>
            <a href="payments.php" class="btn">View Payments</a>
        <?php endif; ?>
        
        <?php if (has_permission('view_reports_dashboard')): ?>
            <a href="reports.php" class="btn">View Reports</a>
        <?php endif; ?>
        
        <?php if (has_permission('manage_roles')): ?>
            <a href="roles.php" class="btn">Manage Roles</a>
        <?php endif; ?>
    </div>
</body>
</html>
```

---

## 🔍 Troubleshooting

### Permission Check Always Returns False

**Problem:** `has_permission()` always returns false

**Solutions:**
1. Check if user is logged in: `var_dump($_SESSION['user_id'])`
2. Check if permission exists: Query `permissions` table
3. Check if user has role: Query `user_roles` table
4. Check if role has permission: Query `role_permissions` table
5. Clear cache: `clear_permission_cache()`

### Database Connection Error

**Problem:** "Database connection not set"

**Solution:**
```php
// Ensure connection is available
global $conn;
require_once 'config/config.php';
require_once 'helpers/permissions_v2.php';
```

### Permission Not Found

**Problem:** Permission doesn't exist in database

**Solution:**
```php
// Check if permission exists
$result = $conn->query("SELECT * FROM permissions WHERE name = 'your_permission'");
if ($result->num_rows == 0) {
    echo "Permission doesn't exist!";
}
```

---

## 📖 Further Reading

- **Full Documentation:** `services/rbac/README.md`
- **Database Schema:** `migrations/rbac_refactor/README.md`
- **Phase 2 Summary:** `services/rbac/PHASE2_SUMMARY.md`
- **Overall Progress:** `RBAC_REFACTOR_PROGRESS.md`

---

## 💡 Tips & Best Practices

1. **Always check permissions** before showing UI elements
2. **Use `require_permission()`** to protect entire pages
3. **Cache is automatic** - no need to manage it manually
4. **Clear cache** after granting/revoking permissions
5. **Use context** for resource-specific permissions
6. **Log important checks** with `check_and_log_permission()`
7. **Test permissions** in development before deploying

---

## 🆘 Need Help?

- Check the comprehensive README: `services/rbac/README.md`
- Review examples in this guide
- Check audit logs for permission issues
- Contact the development team

---

**Version:** 2.0  
**Last Updated:** November 15, 2025  
**Status:** Production Ready
