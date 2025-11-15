<?php
/**
 * Database Verification Script
 * Shows the current state of the RBAC system after migrations
 */

require_once __DIR__ . '/../../config/config.php';

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║         RBAC System - Database Verification Report            ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// 1. Permission Categories
echo "📁 PERMISSION CATEGORIES\n";
echo str_repeat("─", 64) . "\n";
$result = $conn->query("
    SELECT 
        pc.name,
        pc.slug,
        COUNT(p.id) as permission_count
    FROM permission_categories pc
    LEFT JOIN permissions p ON pc.id = p.category_id
    GROUP BY pc.id
    ORDER BY pc.sort_order
");
while ($row = $result->fetch_assoc()) {
    echo sprintf("  %-30s %-20s %3d perms\n", 
        $row['name'], 
        "({$row['slug']})", 
        $row['permission_count']
    );
}

// 2. Permission Statistics
echo "\n📊 PERMISSION STATISTICS\n";
echo str_repeat("─", 64) . "\n";
$stats = [
    "Total Permissions" => "SELECT COUNT(*) FROM permissions",
    "System Permissions" => "SELECT COUNT(*) FROM permissions WHERE is_system = TRUE",
    "Context-Aware" => "SELECT COUNT(*) FROM permissions WHERE requires_context = TRUE",
    "With Parent" => "SELECT COUNT(*) FROM permissions WHERE parent_id IS NOT NULL",
    "Active Permissions" => "SELECT COUNT(*) FROM permissions WHERE is_active = TRUE"
];
foreach ($stats as $label => $query) {
    $result = $conn->query($query);
    $count = $result->fetch_row()[0];
    echo sprintf("  %-30s %3d\n", $label . ":", $count);
}

// 3. Role Hierarchy
echo "\n👥 ROLE HIERARCHY\n";
echo str_repeat("─", 64) . "\n";
$result = $conn->query("
    SELECT 
        r.id,
        r.name,
        r.level,
        COALESCE(p.name, 'None') as parent_name,
        r.is_system
    FROM roles r
    LEFT JOIN roles p ON r.parent_id = p.id
    ORDER BY r.level, r.name
");
while ($row = $result->fetch_assoc()) {
    $indent = str_repeat("  ", $row['level']);
    $system = $row['is_system'] ? " [SYSTEM]" : "";
    $parent = $row['parent_name'] != 'None' ? " ← {$row['parent_name']}" : "";
    echo sprintf("%s%-30s%s%s\n", 
        $indent,
        $row['name'] . $system,
        $parent,
        ""
    );
}

// 4. Role Templates
echo "\n📋 ROLE TEMPLATES\n";
echo str_repeat("─", 64) . "\n";
$result = $conn->query("
    SELECT 
        name,
        category,
        JSON_LENGTH(JSON_EXTRACT(template_data, '$.permissions')) as perm_count
    FROM role_templates
    ORDER BY category, name
");
$current_category = '';
while ($row = $result->fetch_assoc()) {
    if ($current_category != $row['category']) {
        $current_category = $row['category'];
        echo "\n  " . strtoupper($current_category) . ":\n";
    }
    echo sprintf("    %-35s %2d permissions\n", 
        $row['name'], 
        $row['perm_count']
    );
}

// 5. User-Role Assignments
echo "\n👤 USER-ROLE ASSIGNMENTS\n";
echo str_repeat("─", 64) . "\n";
$result = $conn->query("
    SELECT 
        COUNT(DISTINCT user_id) as total_users,
        COUNT(*) as total_assignments,
        COUNT(CASE WHEN is_primary = TRUE THEN 1 END) as primary_assignments,
        COUNT(CASE WHEN expires_at IS NOT NULL THEN 1 END) as temporary_assignments
    FROM user_roles
    WHERE is_active = TRUE
");
$row = $result->fetch_assoc();
echo sprintf("  Total Users with Roles:        %3d\n", $row['total_users']);
echo sprintf("  Total Role Assignments:        %3d\n", $row['total_assignments']);
echo sprintf("  Primary Role Assignments:      %3d\n", $row['primary_assignments']);
echo sprintf("  Temporary Assignments:         %3d\n", $row['temporary_assignments']);

// 6. Role-Permission Assignments
echo "\n🔑 ROLE-PERMISSION ASSIGNMENTS\n";
echo str_repeat("─", 64) . "\n";
$result = $conn->query("
    SELECT 
        r.name as role_name,
        COUNT(rp.id) as permission_count
    FROM roles r
    LEFT JOIN role_permissions rp ON r.id = rp.role_id AND rp.is_active = TRUE
    GROUP BY r.id
    ORDER BY permission_count DESC
    LIMIT 10
");
while ($row = $result->fetch_assoc()) {
    echo sprintf("  %-30s %3d permissions\n", 
        $row['role_name'], 
        $row['permission_count']
    );
}

// 7. Audit Log Statistics
echo "\n📝 AUDIT LOG STATISTICS\n";
echo str_repeat("─", 64) . "\n";
$result = $conn->query("
    SELECT 
        COUNT(*) as total_entries,
        COUNT(DISTINCT actor_user_id) as unique_users,
        COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as last_24h,
        COUNT(CASE WHEN result = 'failure' THEN 1 END) as failures
    FROM permission_audit_log_enhanced
");
$row = $result->fetch_assoc();
echo sprintf("  Total Audit Entries:           %3d\n", $row['total_entries']);
echo sprintf("  Unique Users Logged:           %3d\n", $row['unique_users']);
echo sprintf("  Entries (Last 24h):            %3d\n", $row['last_24h']);
echo sprintf("  Failed Actions:                %3d\n", $row['failures']);

// 8. Migration Status
echo "\n🔄 MIGRATION STATUS\n";
echo str_repeat("─", 64) . "\n";
$result = $conn->query("
    SELECT 
        migration_number,
        migration_name,
        status,
        execution_time_ms,
        executed_at
    FROM rbac_migrations
    ORDER BY migration_number
");
while ($row = $result->fetch_assoc()) {
    $status_icon = $row['status'] == 'completed' ? '✅' : '❌';
    echo sprintf("  %s [%s] %-30s %4dms\n", 
        $status_icon,
        $row['migration_number'],
        substr($row['migration_name'], 0, 28),
        $row['execution_time_ms'] ?? 0
    );
}

echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║                    ✅ VERIFICATION COMPLETE                    ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
