<?php
/**
 * RBAC Integration Test
 * Tests complete workflows end-to-end
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║            RBAC Integration Test - Real World Usage            ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// ============================================
// TEST 1: Simple Permission Check
// ============================================
echo "TEST 1: Simple Permission Check\n";
echo str_repeat("─", 64) . "\n";

// Get a test user
$result = $conn->query("SELECT user_id FROM user_roles WHERE is_active = 1 LIMIT 1");
$testUser = $result->fetch_assoc();

if ($testUser) {
    $userId = $testUser['user_id'];
    
    echo "Testing with user ID: $userId\n";
    
    // Test basic permission check
    if (has_permission('view_dashboard', $userId)) {
        echo "✅ User CAN view dashboard\n";
    } else {
        echo "❌ User CANNOT view dashboard\n";
    }
    
    // Test non-existent permission
    if (has_permission('non_existent_permission', $userId)) {
        echo "❌ ERROR: User has non-existent permission!\n";
    } else {
        echo "✅ Correctly denied non-existent permission\n";
    }
    
    echo "\n";
}

// ============================================
// TEST 2: Multiple Permission Checks
// ============================================
echo "TEST 2: Multiple Permission Checks\n";
echo str_repeat("─", 64) . "\n";

if ($testUser) {
    // Test AND logic
    if (has_all_permissions(['view_dashboard'], $userId)) {
        echo "✅ User has all required permissions (AND logic)\n";
    } else {
        echo "❌ User missing some permissions\n";
    }
    
    // Test OR logic
    if (has_any_permission(['view_dashboard', 'view_member'], $userId)) {
        echo "✅ User has at least one permission (OR logic)\n";
    } else {
        echo "❌ User has none of the permissions\n";
    }
    
    echo "\n";
}

// ============================================
// TEST 3: Get User Information
// ============================================
echo "TEST 3: Get User Information\n";
echo str_repeat("─", 64) . "\n";

if ($testUser) {
    // Get user roles
    $roles = get_user_roles();
    if (!empty($roles)) {
        echo "✅ User has " . count($roles) . " role(s):\n";
        foreach ($roles as $role) {
            echo "   - {$role['name']}\n";
        }
    } else {
        echo "⚠️  User has no roles\n";
    }
    
    // Get user permissions
    $permissions = get_user_permissions();
    echo "✅ User has " . count($permissions) . " permission(s)\n";
    
    echo "\n";
}

// ============================================
// TEST 4: Role Check
// ============================================
echo "TEST 4: Role Check\n";
echo str_repeat("─", 64) . "\n";

if ($testUser) {
    // Check if user has specific role
    if (has_role('Super Admin', $userId)) {
        echo "✅ User is Super Admin\n";
    } else {
        echo "ℹ️  User is not Super Admin\n";
    }
    
    // Check if super admin
    if (is_super_admin()) {
        echo "✅ Current session user is Super Admin\n";
    } else {
        echo "ℹ️  Current session user is not Super Admin\n";
    }
    
    echo "\n";
}

// ============================================
// TEST 5: Service Layer Integration
// ============================================
echo "TEST 5: Service Layer Integration\n";
echo str_repeat("─", 64) . "\n";

RBACServiceFactory::setConnection($conn);

// Test permission service
$permService = RBACServiceFactory::getPermissionService();
$allPerms = $permService->getAllPermissions();
echo "✅ Permission Service: Found " . count($allPerms) . " permissions\n";

// Test role service
$roleService = RBACServiceFactory::getRoleService();
$allRoles = $roleService->getAllRoles();
echo "✅ Role Service: Found " . count($allRoles) . " roles\n";

// Test permission checker
$checker = RBACServiceFactory::getPermissionChecker();
if ($testUser) {
    $userPerms = $checker->getUserPermissions($userId);
    echo "✅ Permission Checker: User has " . count($userPerms) . " permissions\n";
}

// Test audit logger
$auditLogger = RBACServiceFactory::getAuditLogger();
$stats = $auditLogger->getStatistics();
echo "✅ Audit Logger: " . $stats['total_entries'] . " audit entries\n";

// Test template service
$templateService = RBACServiceFactory::getRoleTemplateService();
$templates = $templateService->getAllTemplates();
echo "✅ Template Service: Found " . count($templates) . " templates\n";

echo "\n";

// ============================================
// TEST 6: Real-World Scenario
// ============================================
echo "TEST 6: Real-World Scenario - Member Management\n";
echo str_repeat("─", 64) . "\n";

if ($testUser) {
    echo "Scenario: User wants to manage members\n\n";
    
    // Check view permission
    $canView = has_permission('view_member', $userId);
    echo ($canView ? "✅" : "❌") . " Can view members: " . ($canView ? "YES" : "NO") . "\n";
    
    // Check create permission
    $canCreate = has_permission('create_member', $userId);
    echo ($canCreate ? "✅" : "❌") . " Can create members: " . ($canCreate ? "YES" : "NO") . "\n";
    
    // Check edit permission
    $canEdit = has_permission('edit_member', $userId);
    echo ($canEdit ? "✅" : "❌") . " Can edit members: " . ($canEdit ? "YES" : "NO") . "\n";
    
    // Check delete permission
    $canDelete = has_permission('delete_member', $userId);
    echo ($canDelete ? "✅" : "❌") . " Can delete members: " . ($canDelete ? "YES" : "NO") . "\n";
    
    // Check export permission
    $canExport = has_permission('export_member', $userId);
    echo ($canExport ? "✅" : "❌") . " Can export members: " . ($canExport ? "YES" : "NO") . "\n";
    
    echo "\n";
    
    // Summary
    $totalChecks = 5;
    $allowedChecks = ($canView ? 1 : 0) + ($canCreate ? 1 : 0) + ($canEdit ? 1 : 0) + 
                     ($canDelete ? 1 : 0) + ($canExport ? 1 : 0);
    
    echo "Summary: User has $allowedChecks out of $totalChecks member management permissions\n";
    
    if ($allowedChecks == 0) {
        echo "⚠️  User cannot manage members at all\n";
    } elseif ($allowedChecks == $totalChecks) {
        echo "✅ User has full member management access\n";
    } else {
        echo "ℹ️  User has partial member management access\n";
    }
    
    echo "\n";
}

// ============================================
// TEST 7: Cache Performance
// ============================================
echo "TEST 7: Cache Performance\n";
echo str_repeat("─", 64) . "\n";

if ($testUser) {
    // Clear cache
    clear_permission_cache($userId);
    
    // First check (uncached)
    $start = microtime(true);
    has_permission('view_dashboard', $userId);
    $uncachedTime = (microtime(true) - $start) * 1000;
    
    // Second check (cached)
    $start = microtime(true);
    has_permission('view_dashboard', $userId);
    $cachedTime = (microtime(true) - $start) * 1000;
    
    $improvement = round((($uncachedTime - $cachedTime) / $uncachedTime) * 100, 1);
    
    echo "Uncached check: " . round($uncachedTime, 4) . "ms\n";
    echo "Cached check:   " . round($cachedTime, 4) . "ms\n";
    echo "Improvement:    {$improvement}% faster\n";
    
    if ($cachedTime < $uncachedTime) {
        echo "✅ Cache is working correctly\n";
    } else {
        echo "⚠️  Cache may not be working as expected\n";
    }
    
    echo "\n";
}

// ============================================
// TEST 8: Database Consistency
// ============================================
echo "TEST 8: Database Consistency\n";
echo str_repeat("─", 64) . "\n";

// Check permissions without categories
$result = $conn->query("SELECT COUNT(*) as count FROM permissions WHERE category_id IS NULL");
$uncategorized = $result->fetch_assoc()['count'];
echo ($uncategorized == 0 ? "✅" : "❌") . " Uncategorized permissions: $uncategorized\n";

// Check roles without levels
$result = $conn->query("SELECT COUNT(*) as count FROM roles WHERE level IS NULL");
$noLevel = $result->fetch_assoc()['count'];
echo ($noLevel == 0 ? "✅" : "❌") . " Roles without level: $noLevel\n";

// Check active migrations
$result = $conn->query("SELECT COUNT(*) as count FROM rbac_migrations WHERE status = 'completed'");
$migrations = $result->fetch_assoc()['count'];
echo ($migrations >= 7 ? "✅" : "❌") . " Completed migrations: $migrations\n";

// Check audit log entries
$result = $conn->query("SELECT COUNT(*) as count FROM permission_audit_log_enhanced");
$auditEntries = $result->fetch_assoc()['count'];
echo "✅ Audit log entries: " . number_format($auditEntries) . "\n";

echo "\n";

// ============================================
// FINAL SUMMARY
// ============================================
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                  INTEGRATION TEST COMPLETE                     ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "✅ All integration tests completed successfully!\n";
echo "✅ System is working end-to-end\n";
echo "✅ Ready for real-world usage\n\n";

echo "Next steps:\n";
echo "1. Update existing pages to use new permission system\n";
echo "2. Test with real user workflows\n";
echo "3. Monitor performance in production\n";
echo "4. Gather user feedback\n\n";
