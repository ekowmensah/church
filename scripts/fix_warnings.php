<?php
/**
 * Warning Fix Script
 * Adds permission checks to files that have auth but no permission checks
 */

$rootDir = dirname(__DIR__);
$viewsDir = $rootDir . '/views';

$stats = [
    'total_fixed' => 0,
    'errors' => []
];

// Files to fix with their appropriate permissions
$filesToFix = [
    'eventregistration_form.php' => 'view_event_list',
    'eventregistration_list.php' => 'view_event_list',
    'eventtype_form.php' => 'view_event_list',
    'event_form.php' => 'view_event_list',
    'event_registration_list.php' => 'view_event_list',
    'memberfeedback_form.php' => 'view_feedback_report',
    'memberfeedback_list.php' => 'view_feedback_report',
    'memberorganization_list.php' => 'view_organization_list',
    'permission_list.php' => 'manage_permissions',
    'roles_of_serving_delete.php' => 'manage_roles',
    'roles_of_serving_form.php' => 'manage_roles',
    'roles_of_serving_list.php' => 'manage_roles',
    'transfer_form.php' => 'view_transfer_list'
];

function addPermissionCheck($filePath, $permission, &$stats) {
    $content = file_get_contents($filePath);
    $fileName = basename($filePath);
    
    // Check if already has permission check
    if (preg_match('/has_permission\(|require_permission\(/', $content)) {
        return false;
    }
    
    // Find the position after is_logged_in() check
    if (preg_match('/(if\s*\(\s*!is_logged_in\(\)\s*\)\s*\{[^}]+\})/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
        $insertPos = $matches[0][1] + strlen($matches[0][0]);
        
        // Create permission check block
        $permissionBlock = "\n\n// Permission check\n";
        $permissionBlock .= "if (!has_permission('$permission')) {\n";
        $permissionBlock .= "    http_response_code(403);\n";
        $permissionBlock .= "    echo '<div class=\"alert alert-danger\"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';\n";
        $permissionBlock .= "    exit;\n";
        $permissionBlock .= "}\n";
        
        // Insert permission check
        $newContent = substr($content, 0, $insertPos) . $permissionBlock . substr($content, $insertPos);
        
        if (file_put_contents($filePath, $newContent)) {
            $stats['total_fixed']++;
            echo "✅ Fixed: $fileName (Permission: $permission)\n";
            return true;
        } else {
            $stats['errors'][] = $filePath;
            echo "❌ Failed: $fileName\n";
            return false;
        }
    } else {
        echo "⚠️  Skipped: $fileName (Could not find auth check pattern)\n";
        return false;
    }
}

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║              Warning Fix Script                               ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "Adding permission checks to files with warnings...\n\n";

foreach ($filesToFix as $fileName => $permission) {
    $filePath = $viewsDir . DIRECTORY_SEPARATOR . $fileName;
    
    if (file_exists($filePath)) {
        addPermissionCheck($filePath, $permission, $stats);
    } else {
        echo "⚠️  Not found: $fileName\n";
    }
}

echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║                      Fix Summary                               ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "Files fixed:     {$stats['total_fixed']}\n";
echo "Errors:          " . count($stats['errors']) . "\n\n";

if (!empty($stats['errors'])) {
    echo "❌ Errors:\n";
    foreach ($stats['errors'] as $error) {
        echo "  - $error\n";
    }
}

if ($stats['total_fixed'] > 0) {
    echo "\n✅ Permission checks added successfully!\n";
    echo "\nNext steps:\n";
    echo "1. Run audit_permissions.php again to verify\n";
    echo "2. Test the updated pages\n";
} else {
    echo "\nℹ️  No files needed fixing.\n";
}

echo "\n";
?>
