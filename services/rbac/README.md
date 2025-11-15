# RBAC Service Layer v2.0

Complete service layer for Role-Based Access Control system with permission checking, role management, and audit logging.

## 📁 Service Classes

### 1. **PermissionService**
Manages all permission-related operations.

**Features:**
- CRUD operations for permissions
- Permission categorization
- Permission hierarchy (parent-child)
- Permission types (action, resource, feature, system)
- System permission protection
- Soft delete support

**Usage:**
```php
$permService = RBACServiceFactory::getPermissionService();

// Get all permissions
$permissions = $permService->getAllPermissions();

// Get permissions by category
$payments = $permService->getPermissionsByCategory(4); // category_id = 4

// Create permission
$permId = $permService->createPermission([
    'name' => 'export_reports',
    'description' => 'Export reports to PDF/Excel',
    'category_id' => 5,
    'permission_type' => 'action',
    'is_system' => false
], $userId);

// Update permission
$permService->updatePermission($permId, [
    'description' => 'Updated description'
], $userId);

// Get permission tree (hierarchy)
$tree = $permService->getPermissionTree();
```

---

### 2. **RoleService**
Manages roles and role-permission assignments.

**Features:**
- CRUD operations for roles
- Role hierarchy (parent-child inheritance)
- Permission assignment to roles
- Bulk permission sync
- System role protection
- Role statistics

**Usage:**
```php
$roleService = RBACServiceFactory::getRoleService();

// Get all roles
$roles = $roleService->getAllRoles();

// Create role
$roleId = $roleService->createRole([
    'name' => 'Content Manager',
    'description' => 'Manages website content',
    'parent_id' => 2 // inherits from Admin role
], $userId);

// Grant permission to role
$roleService->grantPermission($roleId, $permissionId, $userId);

// Sync all permissions (replace existing)
$roleService->syncPermissions($roleId, [1, 2, 3, 5, 8], $userId);

// Get role permissions (including inherited)
$permissions = $roleService->getRolePermissions($roleId, true);

// Get role hierarchy tree
$tree = $roleService->getRoleTree();
```

---

### 3. **PermissionChecker**
Core service for checking user permissions with caching.

**Features:**
- Super admin bypass
- User-level permission overrides
- Role-based permission checking
- Permission inheritance from parent roles
- Context-aware permissions
- In-memory caching (5-minute TTL)
- Audit logging support

**Usage:**
```php
$checker = RBACServiceFactory::getPermissionChecker();

// Check single permission
if ($checker->hasPermission('edit_member', $userId)) {
    // Allow action
}

// Check multiple permissions (AND logic)
if ($checker->hasAllPermissions(['view_reports', 'export_reports'], $userId)) {
    // User has both permissions
}

// Check multiple permissions (OR logic)
if ($checker->hasAnyPermission(['edit_member', 'delete_member'], $userId)) {
    // User has at least one permission
}

// Context-aware permission check
if ($checker->hasPermission('edit_member_in_own_class', $userId, ['class_id' => 5])) {
    // User can edit members in their own class
}

// Get all user permissions
$permissions = $checker->getUserPermissions($userId);

// Get user roles
$roles = $checker->getUserRoles($userId);

// Clear cache
$checker->clearCache($userId);
```

---

### 4. **AuditLogger**
Comprehensive audit logging for all RBAC activities.

**Features:**
- Logs all permission grants/revokes
- Logs permission checks (optional)
- Tracks user activity
- Context capture (IP, user agent, etc.)
- Statistics and reporting
- Automatic cleanup

**Usage:**
```php
$auditLogger = RBACServiceFactory::getAuditLogger();

// Log manual entry
$auditLogger->log(
    $userId,           // Actor
    'grant',           // Action
    'role',            // Target type
    $roleId,           // Target ID
    $permissionId,     // Permission ID
    $roleId,           // Role ID
    null,              // Old value
    json_encode($data), // New value
    'success',         // Result
    'Permission granted to role'
);

// Get audit logs with filters
$logs = $auditLogger->getAuditLogs([
    'actor_user_id' => $userId,
    'action' => 'grant',
    'date_from' => '2025-11-01',
    'date_to' => '2025-11-30'
], 50, 0);

// Get statistics
$stats = $auditLogger->getStatistics([
    'date_from' => '2025-11-01'
]);

// Get user activity
$activity = $auditLogger->getUserActivity($userId, 30); // Last 30 days

// Get most active users
$activeUsers = $auditLogger->getMostActiveUsers(7, 10); // Last 7 days, top 10

// Get permission usage
$usage = $auditLogger->getPermissionUsage(7, 20); // Last 7 days, top 20

// Get failed checks
$failed = $auditLogger->getFailedChecks(50);

// Cleanup old logs
$deleted = $auditLogger->cleanup(90, true); // Delete checks older than 90 days
```

---

### 5. **RoleTemplateService**
Manages role templates for quick role creation.

**Features:**
- Pre-built role templates
- Custom template creation
- Role creation from templates
- Template usage tracking
- Template categories

**Usage:**
```php
$templateService = RBACServiceFactory::getRoleTemplateService();

// Get all templates
$templates = $templateService->getAllTemplates();

// Get templates by category
$churchTemplates = $templateService->getAllTemplates('church');

// Create role from template
$roleId = $templateService->createRoleFromTemplate(
    $templateId,
    'Custom Role Name', // Optional
    $userId
);

// Create custom template
$templateId = $templateService->createTemplate([
    'name' => 'Custom Template',
    'description' => 'Template description',
    'category' => 'custom',
    'template_data' => [
        'permissions' => ['view_member', 'edit_member', 'create_member'],
        'description' => 'Member management role'
    ]
], $userId);

// Get template usage
$usage = $templateService->getTemplateUsage($templateId);

// Get most used templates
$popular = $templateService->getMostUsedTemplates(10);
```

---

### 6. **RBACServiceFactory**
Factory class for creating and managing service instances.

**Features:**
- Singleton pattern for services
- Automatic dependency injection
- Connection management
- Service instance caching

**Usage:**
```php
// Set database connection (once at app initialization)
RBACServiceFactory::setConnection($conn);

// Get services (automatically wired with dependencies)
$permService = RBACServiceFactory::getPermissionService();
$roleService = RBACServiceFactory::getRoleService();
$checker = RBACServiceFactory::getPermissionChecker();
$auditLogger = RBACServiceFactory::getAuditLogger();
$templateService = RBACServiceFactory::getRoleTemplateService();

// Clear all instances (useful for testing)
RBACServiceFactory::clearInstances();
```

---

## 🔄 Backward Compatibility

The `helpers/permissions_v2.php` file provides backward-compatible wrappers:

```php
// Include the v2 helpers
require_once 'helpers/permissions_v2.php';

// Use old function signatures
if (has_permission('edit_member')) {
    // Works exactly like before
}

// New helper functions
if (has_all_permissions(['view_reports', 'export_reports'])) {
    // User has all permissions
}

if (has_any_permission(['edit_member', 'delete_member'])) {
    // User has at least one
}

// Require permission or die with 403
require_permission('manage_roles');

// Check if super admin
if (is_super_admin()) {
    // Super admin access
}

// Check if user has role
if (has_role('Admin')) {
    // User has Admin role
}

// Get user permissions
$permissions = get_user_permissions();

// Get user roles
$roles = get_user_roles();

// Clear cache
clear_permission_cache();
```

---

## 🚀 Quick Start

### 1. Initialize in your application bootstrap:

```php
// config/bootstrap.php or similar
require_once __DIR__ . '/../services/rbac/RBACServiceFactory.php';

// Set database connection
RBACServiceFactory::setConnection($conn);
```

### 2. Use in your code:

```php
// In a controller or view
$checker = RBACServiceFactory::getPermissionChecker();

if (!$checker->hasPermission('view_member')) {
    http_response_code(403);
    die('Access denied');
}

// Your protected code here
```

### 3. Or use helper functions:

```php
// Include helpers
require_once 'helpers/permissions_v2.php';

// Use anywhere
if (has_permission('edit_member')) {
    // Protected code
}
```

---

## 📊 Permission Check Flow

```
1. Check if user is Super Admin
   └─> YES: Grant access
   └─> NO: Continue

2. Check user-level overrides
   └─> FOUND: Use override (allow/deny)
   └─> NOT FOUND: Continue

3. Check context-aware permissions
   └─> APPLICABLE: Validate context
   └─> NOT APPLICABLE: Continue

4. Check role-based permissions
   └─> Direct role permission: Grant access
   └─> Inherited from parent role: Grant access
   └─> Not found: Deny access

5. Cache result (5-minute TTL)

6. Log check (if enabled)
```

---

## 🔐 Security Features

1. **System Protection**
   - System permissions cannot be deleted
   - System roles cannot be deleted
   - System templates cannot be deleted

2. **Audit Trail**
   - All permission grants/revokes logged
   - All role changes logged
   - Failed permission checks logged
   - Context captured (IP, user agent, etc.)

3. **Permission Hierarchy**
   - Permissions can inherit from parents
   - Roles can inherit from parents
   - Prevents circular dependencies

4. **Context Validation**
   - Context-aware permissions require validation
   - Prevents unauthorized access to resources
   - Supports custom context logic

5. **Caching**
   - In-memory caching for performance
   - Automatic cache invalidation
   - Manual cache clearing available

---

## 🧪 Testing

```php
// Test permission check
$checker = RBACServiceFactory::getPermissionChecker();
$result = $checker->hasPermission('test_permission', $testUserId);
assert($result === true, 'Permission check failed');

// Test role creation
$roleService = RBACServiceFactory::getRoleService();
$roleId = $roleService->createRole([
    'name' => 'Test Role',
    'description' => 'Test role description'
], $testUserId);
assert($roleId > 0, 'Role creation failed');

// Test audit logging
$auditLogger = RBACServiceFactory::getAuditLogger();
$logs = $auditLogger->getAuditLogs(['actor_user_id' => $testUserId]);
assert(count($logs) > 0, 'Audit logs not found');

// Clear test data
RBACServiceFactory::clearInstances();
```

---

## 📝 Best Practices

1. **Always use the factory** to get service instances
2. **Cache permission checks** when checking the same permission multiple times
3. **Use context-aware permissions** for resource-specific access control
4. **Log important actions** for audit trail
5. **Use soft delete** for permissions and roles (don't hard delete)
6. **Validate input** before creating/updating permissions and roles
7. **Use transactions** for operations that modify multiple tables
8. **Clear cache** after granting/revoking permissions
9. **Use role templates** for consistent role creation
10. **Monitor audit logs** for security issues

---

## 🔧 Configuration

### Cache Settings
```php
$checker = RBACServiceFactory::getPermissionChecker();
$checker->setCacheEnabled(true);  // Enable/disable caching
$checker->clearCache();            // Clear all cache
```

### Audit Logging
```php
// Enable audit logging for permission checks
$result = $checker->hasPermission('edit_member', $userId, [], true);
```

---

## 📚 Additional Resources

- Database schema: `migrations/rbac_refactor/`
- Migration guide: `migrations/rbac_refactor/README.md`
- Week 1 summary: `migrations/rbac_refactor/WEEK1_SUMMARY.md`
- Progress tracking: `migrations/rbac_refactor/PROGRESS.md`

---

**Version:** 2.0  
**Last Updated:** 2025-11-15  
**Status:** Production Ready
