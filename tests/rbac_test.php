<?php
/**
 * RBAC System Test Suite
 * Tests all core functionality of the new RBAC system
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../services/rbac/RBACServiceFactory.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';

// Initialize
RBACServiceFactory::setConnection($conn);

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║              RBAC System Test Suite v2.0                      ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function test($description, $callback) {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    
    try {
        $result = $callback();
        if ($result) {
            $passedTests++;
            echo "✅ PASS: $description\n";
            return true;
        } else {
            $failedTests++;
            echo "❌ FAIL: $description\n";
            return false;
        }
    } catch (Exception $e) {
        $failedTests++;
        echo "❌ ERROR: $description - {$e->getMessage()}\n";
        return false;
    }
}

// ============================================
// TEST SUITE 1: SERVICE FACTORY
// ============================================
echo "\n📦 TEST SUITE 1: Service Factory\n";
echo str_repeat("─", 64) . "\n";

test("Factory can create PermissionService", function() {
    $service = RBACServiceFactory::getPermissionService();
    return $service instanceof PermissionService;
});

test("Factory can create RoleService", function() {
    $service = RBACServiceFactory::getRoleService();
    return $service instanceof RoleService;
});

test("Factory can create PermissionChecker", function() {
    $service = RBACServiceFactory::getPermissionChecker();
    return $service instanceof PermissionChecker;
});

test("Factory can create AuditLogger", function() {
    $service = RBACServiceFactory::getAuditLogger();
    return $service instanceof AuditLogger;
});

test("Factory can create RoleTemplateService", function() {
    $service = RBACServiceFactory::getRoleTemplateService();
    return $service instanceof RoleTemplateService;
});

test("Factory returns same instance (singleton)", function() {
    $service1 = RBACServiceFactory::getPermissionService();
    $service2 = RBACServiceFactory::getPermissionService();
    return $service1 === $service2;
});

// ============================================
// TEST SUITE 2: PERMISSION SERVICE
// ============================================
echo "\n🔑 TEST SUITE 2: Permission Service\n";
echo str_repeat("─", 64) . "\n";

$permService = RBACServiceFactory::getPermissionService();

test("Can get all permissions", function() use ($permService) {
    $permissions = $permService->getAllPermissions();
    return is_array($permissions) && count($permissions) > 0;
});

test("Can get permissions by category", function() use ($permService) {
    $permissions = $permService->getPermissionsByCategory(1); // Dashboard category
    return is_array($permissions);
});

test("Can get all categories", function() use ($permService) {
    $categories = $permService->getAllCategories();
    return is_array($categories) && count($categories) == 22;
});

test("Can get permission by name", function() use ($permService) {
    $permission = $permService->getPermissionByName('view_dashboard');
    return $permission && $permission['name'] == 'view_dashboard';
});

test("Can get permissions grouped by category", function() use ($permService) {
    $grouped = $permService->getPermissionsGroupedByCategory();
    return is_array($grouped) && count($grouped) > 0;
});

test("Permission exists check works", function() use ($permService) {
    return $permService->permissionExists('view_dashboard') === true;
});

test("Permission exists check for non-existent permission", function() use ($permService) {
    return $permService->permissionExists('non_existent_permission_xyz') === false;
});

// ============================================
// TEST SUITE 3: ROLE SERVICE
// ============================================
echo "\n👥 TEST SUITE 3: Role Service\n";
echo str_repeat("─", 64) . "\n";

$roleService = RBACServiceFactory::getRoleService();

test("Can get all roles", function() use ($roleService) {
    $roles = $roleService->getAllRoles();
    return is_array($roles) && count($roles) > 0;
});

test("Can get role by name", function() use ($roleService) {
    $role = $roleService->getRoleByName('Super Admin');
    return $role && $role['name'] == 'Super Admin';
});

test("Can get role hierarchy tree", function() use ($roleService) {
    $tree = $roleService->getRoleTree();
    return is_array($tree);
});

test("Can get role permissions", function() use ($roleService) {
    $role = $roleService->getRoleByName('Super Admin');
    if ($role) {
        $permissions = $roleService->getRolePermissions($role['id'], false);
        return is_array($permissions) && count($permissions) > 0;
    }
    return false;
});

test("Role exists check works", function() use ($roleService) {
    return $roleService->roleExists('Super Admin') === true;
});

test("Role exists check for non-existent role", function() use ($roleService) {
    return $roleService->roleExists('Non Existent Role XYZ') === false;
});

// ============================================
// TEST SUITE 4: PERMISSION CHECKER
// ============================================
echo "\n🔍 TEST SUITE 4: Permission Checker\n";
echo str_repeat("─", 64) . "\n";

$checker = RBACServiceFactory::getPermissionChecker();

// Find a test user with permissions
$testUserId = null;
$result = $conn->query("SELECT user_id FROM user_roles WHERE is_active = 1 LIMIT 1");
if ($result && $row = $result->fetch_assoc()) {
    $testUserId = $row['user_id'];
}

if ($testUserId) {
    test("Can check permission for user", function() use ($checker, $testUserId) {
        // Just test that it returns a boolean
        $result = $checker->hasPermission('view_dashboard', $testUserId);
        return is_bool($result);
    });
    
    test("Can get user permissions", function() use ($checker, $testUserId) {
        $permissions = $checker->getUserPermissions($testUserId);
        return is_array($permissions);
    });
    
    test("Can get user roles", function() use ($checker, $testUserId) {
        $roles = $checker->getUserRoles($testUserId);
        return is_array($roles) && count($roles) > 0;
    });
    
    test("Cache can be cleared", function() use ($checker, $testUserId) {
        $checker->clearCache($testUserId);
        return true;
    });
} else {
    echo "⚠️  SKIP: No test user found for permission checker tests\n";
}

test("hasAllPermissions works with array", function() use ($checker, $testUserId) {
    if (!$testUserId) return true; // Skip if no test user
    $result = $checker->hasAllPermissions(['view_dashboard'], $testUserId);
    return is_bool($result);
});

test("hasAnyPermission works with array", function() use ($checker, $testUserId) {
    if (!$testUserId) return true; // Skip if no test user
    $result = $checker->hasAnyPermission(['view_dashboard', 'non_existent'], $testUserId);
    return is_bool($result);
});

// ============================================
// TEST SUITE 5: AUDIT LOGGER
// ============================================
echo "\n📝 TEST SUITE 5: Audit Logger\n";
echo str_repeat("─", 64) . "\n";

$auditLogger = RBACServiceFactory::getAuditLogger();

test("Can get audit logs", function() use ($auditLogger) {
    $logs = $auditLogger->getAuditLogs([], 10, 0);
    return is_array($logs);
});

test("Can get audit statistics", function() use ($auditLogger) {
    $stats = $auditLogger->getStatistics();
    return is_array($stats) && isset($stats['total_entries']);
});

test("Can get most active users", function() use ($auditLogger) {
    $users = $auditLogger->getMostActiveUsers(7, 5);
    return is_array($users);
});

test("Can get permission usage", function() use ($auditLogger) {
    $usage = $auditLogger->getPermissionUsage(7, 10);
    return is_array($usage);
});

test("Can get failed checks", function() use ($auditLogger) {
    $failed = $auditLogger->getFailedChecks(10);
    return is_array($failed);
});

if ($testUserId) {
    test("Can get user activity", function() use ($auditLogger, $testUserId) {
        $activity = $auditLogger->getUserActivity($testUserId, 30);
        return is_array($activity);
    });
}

// ============================================
// TEST SUITE 6: ROLE TEMPLATE SERVICE
// ============================================
echo "\n📋 TEST SUITE 6: Role Template Service\n";
echo str_repeat("─", 64) . "\n";

$templateService = RBACServiceFactory::getRoleTemplateService();

test("Can get all templates", function() use ($templateService) {
    $templates = $templateService->getAllTemplates();
    return is_array($templates) && count($templates) == 10;
});

test("Can get templates by category", function() use ($templateService) {
    $templates = $templateService->getAllTemplates('church');
    return is_array($templates) && count($templates) > 0;
});

test("Can get template by name", function() use ($templateService) {
    $template = $templateService->getTemplateByName('Cashier');
    return $template && $template['name'] == 'Cashier';
});

test("Can get most used templates", function() use ($templateService) {
    $templates = $templateService->getMostUsedTemplates(5);
    return is_array($templates);
});

test("Template has correct structure", function() use ($templateService) {
    $template = $templateService->getTemplateByName('Cashier');
    return $template && 
           isset($template['template_data']) && 
           isset($template['template_data']['permissions']) &&
           is_array($template['template_data']['permissions']);
});

// ============================================
// TEST SUITE 7: HELPER FUNCTIONS
// ============================================
echo "\n🛠️  TEST SUITE 7: Helper Functions\n";
echo str_repeat("─", 64) . "\n";

test("has_permission function exists", function() {
    return function_exists('has_permission');
});

test("has_all_permissions function exists", function() {
    return function_exists('has_all_permissions');
});

test("has_any_permission function exists", function() {
    return function_exists('has_any_permission');
});

test("get_user_permissions function exists", function() {
    return function_exists('get_user_permissions');
});

test("get_user_roles function exists", function() {
    return function_exists('get_user_roles');
});

test("clear_permission_cache function exists", function() {
    return function_exists('clear_permission_cache');
});

test("require_permission function exists", function() {
    return function_exists('require_permission');
});

test("is_super_admin function exists", function() {
    return function_exists('is_super_admin');
});

test("has_role function exists", function() {
    return function_exists('has_role');
});

// ============================================
// TEST SUITE 8: DATABASE INTEGRITY
// ============================================
echo "\n🗄️  TEST SUITE 8: Database Integrity\n";
echo str_repeat("─", 64) . "\n";

test("permission_categories table exists", function() use ($conn) {
    $result = $conn->query("SHOW TABLES LIKE 'permission_categories'");
    return $result->num_rows > 0;
});

test("All permissions have categories", function() use ($conn) {
    $result = $conn->query("SELECT COUNT(*) as count FROM permissions WHERE category_id IS NULL");
    $row = $result->fetch_assoc();
    return $row['count'] == 0;
});

test("role_templates table exists", function() use ($conn) {
    $result = $conn->query("SHOW TABLES LIKE 'role_templates'");
    return $result->num_rows > 0;
});

test("permission_audit_log_enhanced table exists", function() use ($conn) {
    $result = $conn->query("SHOW TABLES LIKE 'permission_audit_log_enhanced'");
    return $result->num_rows > 0;
});

test("Roles have hierarchy columns", function() use ($conn) {
    $result = $conn->query("SHOW COLUMNS FROM roles LIKE 'level'");
    return $result->num_rows > 0;
});

test("Permissions have enhanced columns", function() use ($conn) {
    $result = $conn->query("SHOW COLUMNS FROM permissions LIKE 'permission_type'");
    return $result->num_rows > 0;
});

test("role_permissions has metadata columns", function() use ($conn) {
    $result = $conn->query("SHOW COLUMNS FROM role_permissions LIKE 'granted_by'");
    return $result->num_rows > 0;
});

test("user_roles has metadata columns", function() use ($conn) {
    $result = $conn->query("SHOW COLUMNS FROM user_roles LIKE 'assigned_by'");
    return $result->num_rows > 0;
});

test("Audit views exist", function() use ($conn) {
    $result = $conn->query("SHOW FULL TABLES WHERE Table_type = 'VIEW' AND Tables_in_myfreemangit LIKE 'v_audit%'");
    return $result->num_rows >= 3;
});

test("Migration tracker has all migrations", function() use ($conn) {
    $result = $conn->query("SELECT COUNT(*) as count FROM rbac_migrations WHERE status = 'completed'");
    $row = $result->fetch_assoc();
    return $row['count'] >= 7;
});

// ============================================
// TEST SUITE 9: PERFORMANCE
// ============================================
echo "\n⚡ TEST SUITE 9: Performance\n";
echo str_repeat("─", 64) . "\n";

if ($testUserId) {
    test("Permission check completes in <10ms (uncached)", function() use ($checker, $testUserId) {
        $checker->clearCache($testUserId);
        $start = microtime(true);
        $checker->hasPermission('view_dashboard', $testUserId);
        $duration = (microtime(true) - $start) * 1000;
        echo " ({$duration}ms)";
        return $duration < 10;
    });
    
    test("Permission check completes in <1ms (cached)", function() use ($checker, $testUserId) {
        // First call to cache
        $checker->hasPermission('view_dashboard', $testUserId);
        
        // Second call should be cached
        $start = microtime(true);
        $checker->hasPermission('view_dashboard', $testUserId);
        $duration = (microtime(true) - $start) * 1000;
        echo " ({$duration}ms)";
        return $duration < 1;
    });
}

test("Get all permissions completes in <100ms", function() use ($permService) {
    $start = microtime(true);
    $permService->getAllPermissions();
    $duration = (microtime(true) - $start) * 1000;
    echo " ({$duration}ms)";
    return $duration < 100;
});

test("Get all roles completes in <50ms", function() use ($roleService) {
    $start = microtime(true);
    $roleService->getAllRoles();
    $duration = (microtime(true) - $start) * 1000;
    echo " ({$duration}ms)";
    return $duration < 50;
});

// ============================================
// FINAL RESULTS
// ============================================
echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║                        TEST RESULTS                            ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

$passRate = $totalTests > 0 ? round(($passedTests / $totalTests) * 100, 1) : 0;

echo "Total Tests:   $totalTests\n";
echo "Passed:        $passedTests ✅\n";
echo "Failed:        $failedTests ❌\n";
echo "Pass Rate:     $passRate%\n\n";

if ($failedTests == 0) {
    echo "╔════════════════════════════════════════════════════════════════╗\n";
    echo "║          🎉 ALL TESTS PASSED! SYSTEM IS READY! 🎉            ║\n";
    echo "╚════════════════════════════════════════════════════════════════╝\n";
} else {
    echo "╔════════════════════════════════════════════════════════════════╗\n";
    echo "║     ⚠️  SOME TESTS FAILED - REVIEW ERRORS ABOVE ⚠️           ║\n";
    echo "╚════════════════════════════════════════════════════════════════╝\n";
}

echo "\n";
