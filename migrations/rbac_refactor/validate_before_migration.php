<?php
/**
 * Pre-Migration Validation Script
 * Checks database state before running RBAC migrations
 */

require_once __DIR__ . '/../../config/config.php';

echo "=== RBAC Migration Pre-Flight Check ===\n\n";

$checks = [];
$warnings = [];
$errors = [];

// Check 1: Database connection
try {
    if ($conn->ping()) {
        $checks[] = "✓ Database connection successful";
    }
} catch (Exception $e) {
    $errors[] = "✗ Database connection failed: " . $e->getMessage();
}

// Check 2: Required tables exist
$requiredTables = ['roles', 'permissions', 'role_permissions', 'user_roles'];
foreach ($requiredTables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result->num_rows > 0) {
        $checks[] = "✓ Table '$table' exists";
    } else {
        $errors[] = "✗ Required table '$table' not found";
    }
}

// Check 3: Count existing data
$stmt = $conn->query("SELECT COUNT(*) as count FROM roles");
$roleCount = $stmt->fetch_assoc()['count'];
$checks[] = "✓ Found $roleCount roles";

$stmt = $conn->query("SELECT COUNT(*) as count FROM permissions");
$permCount = $stmt->fetch_assoc()['count'];
$checks[] = "✓ Found $permCount permissions";

$stmt = $conn->query("SELECT COUNT(*) as count FROM user_roles");
$userRoleCount = $stmt->fetch_assoc()['count'];
$checks[] = "✓ Found $userRoleCount user-role assignments";

$stmt = $conn->query("SELECT COUNT(*) as count FROM role_permissions");
$rolePermCount = $stmt->fetch_assoc()['count'];
$checks[] = "✓ Found $rolePermCount role-permission assignments";

// Check 4: Check for permission groups
$stmt = $conn->query("SELECT DISTINCT `group` FROM permissions WHERE `group` IS NOT NULL");
$groups = [];
while ($row = $stmt->fetch_assoc()) {
    $groups[] = $row['group'];
}
$checks[] = "✓ Found " . count($groups) . " permission groups";

// Check 5: Check if migration tracker exists
$result = $conn->query("SHOW TABLES LIKE 'rbac_migrations'");
if ($result->num_rows > 0) {
    $stmt = $conn->query("SELECT COUNT(*) as count FROM rbac_migrations WHERE status = 'completed'");
    $completedCount = $stmt->fetch_assoc()['count'];
    $warnings[] = "⚠ Migration tracker already exists ($completedCount completed migrations)";
} else {
    $checks[] = "✓ No previous migrations detected (clean state)";
}

// Check 6: Check for foreign key constraints
$result = $conn->query("
    SELECT COUNT(*) as count 
    FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'permissions' 
    AND CONSTRAINT_NAME = 'fk_permission_category'
");
$fkExists = $result->fetch_assoc()['count'];
if ($fkExists > 0) {
    $warnings[] = "⚠ Foreign key 'fk_permission_category' already exists";
}

// Check 7: Check if category_id column exists
$result = $conn->query("
    SELECT COUNT(*) as count 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'permissions' 
    AND COLUMN_NAME = 'category_id'
");
$categoryIdExists = $result->fetch_assoc()['count'];
if ($categoryIdExists > 0) {
    $warnings[] = "⚠ Column 'category_id' already exists in permissions table";
}

// Check 8: Database backup recommendation
$backupFile = "backup_before_rbac_" . date('Ymd') . ".sql";
$warnings[] = "⚠ IMPORTANT: Create database backup before proceeding";
$warnings[] = "  Command: mysqldump -u root -p [database_name] > $backupFile";

// Display results
echo "CHECKS PASSED:\n";
foreach ($checks as $check) {
    echo "  $check\n";
}

if (!empty($warnings)) {
    echo "\nWARNINGS:\n";
    foreach ($warnings as $warning) {
        echo "  $warning\n";
    }
}

if (!empty($errors)) {
    echo "\nERRORS:\n";
    foreach ($errors as $error) {
        echo "  $error\n";
    }
    echo "\n❌ Pre-flight check FAILED. Fix errors before proceeding.\n";
    exit(1);
}

echo "\n✅ Pre-flight check PASSED. Ready to run migrations.\n";
echo "\nNext steps:\n";
echo "  1. Create database backup\n";
echo "  2. Review migration files in migrations/rbac_refactor/\n";
echo "  3. Run: php run_migrations.php run --dry-run\n";
echo "  4. Run: php run_migrations.php run\n";
echo "\n";
