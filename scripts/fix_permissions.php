<?php
/**
 * Permission Fix Script
 * Automatically adds proper permission checks to files that are missing them
 */

$rootDir = dirname(__DIR__);
$viewsDir = $rootDir . '/views';

$stats = [
    'total_fixed' => 0,
    'skipped' => 0,
    'errors' => []
];

// Files that should not be modified (callbacks, partials, modals, etc.)
$skipFiles = [
    'hubtel_callback',
    'paystack_callback',
    'partials/',
    '_modal',
    '_nav',
    'status_scripts',
    'status_modals',
    'send_message_modal',
    'visitor_sms_modal',
    'event_calendar',
    'health_print',
    'member_dashboard',
    'member_profile',
    'profile.php',
    'profile_edit.php',
    'complete_registration'
];

// Permission mappings based on file patterns
$permissionMappings = [
    'member_' => 'view_member',
    'payment_' => 'view_payment_list',
    'user_' => 'manage_users',
    'role_' => 'manage_roles',
    'permission_' => 'manage_permissions',
    'organization_' => 'view_organization_list',
    'event_' => 'view_event_list',
    'sundayschool_' => 'view_sundayschool_list',
    'visitor_' => 'view_visitor_list',
    'memberfeedback_' => 'view_feedback_report',
    'memberorganization_' => 'view_organization_list',
    'paymenttype_' => 'view_payment_list',
    'transfer_' => 'view_transfer_list',
    'resend_sms' => 'send_sms',
    'menu_management' => 'manage_menu_items',
    'setup_menu' => 'manage_menu_items'
];

function shouldSkip($fileName) {
    global $skipFiles;
    foreach ($skipFiles as $skip) {
        if (strpos($fileName, $skip) !== false) {
            return true;
        }
    }
    return false;
}

function getPermissionForFile($fileName) {
    global $permissionMappings;
    
    foreach ($permissionMappings as $pattern => $permission) {
        if (strpos($fileName, $pattern) !== false) {
            return $permission;
        }
    }
    
    // Default permissions based on file type
    if (strpos($fileName, '_list.php') !== false) {
        return 'view_dashboard';
    }
    if (strpos($fileName, '_form.php') !== false) {
        return 'view_dashboard';
    }
    if (strpos($fileName, '_delete.php') !== false) {
        return 'view_dashboard';
    }
    
    return null;
}

function fixFile($filePath, &$stats) {
    $fileName = basename($filePath);
    
    if (shouldSkip($fileName)) {
        $stats['skipped']++;
        return false;
    }
    
    $content = file_get_contents($filePath);
    
    // Check if already has auth/permission checks
    if (preg_match('/is_logged_in\(\)|has_permission\(|require_permission\(/', $content)) {
        return false;
    }
    
    $permission = getPermissionForFile($fileName);
    if (!$permission) {
        return false;
    }
    
    // Determine the correct path depth
    $depth = substr_count(str_replace($GLOBALS['viewsDir'], '', $filePath), DIRECTORY_SEPARATOR) - 1;
    $pathPrefix = str_repeat('../', max(1, $depth));
    
    // Create the security block
    $securityBlock = "<?php\n";
    $securityBlock .= "session_start();\n";
    $securityBlock .= "require_once __DIR__.'/{$pathPrefix}config/config.php';\n";
    $securityBlock .= "require_once __DIR__.'/{$pathPrefix}helpers/auth.php';\n";
    $securityBlock .= "require_once __DIR__.'/{$pathPrefix}helpers/permissions_v2.php';\n\n";
    $securityBlock .= "// Authentication check\n";
    $securityBlock .= "if (!is_logged_in()) {\n";
    $securityBlock .= "    header('Location: ' . BASE_URL . '/login.php');\n";
    $securityBlock .= "    exit;\n";
    $securityBlock .= "}\n\n";
    $securityBlock .= "// Permission check\n";
    $securityBlock .= "if (!has_permission('$permission')) {\n";
    $securityBlock .= "    http_response_code(403);\n";
    $securityBlock .= "    echo '<div class=\"alert alert-danger\"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';\n";
    $securityBlock .= "    exit;\n";
    $securityBlock .= "}\n";
    $securityBlock .= "?>\n";
    
    // Remove existing PHP opening tag if present
    $content = preg_replace('/^<\?php\s*\n?/', '', $content);
    
    // Add security block at the beginning
    $newContent = $securityBlock . $content;
    
    if (file_put_contents($filePath, $newContent)) {
        $stats['total_fixed']++;
        echo "✅ Fixed: " . str_replace($GLOBALS['viewsDir'], '/views', $filePath) . " (Permission: $permission)\n";
        return true;
    } else {
        $stats['errors'][] = $filePath;
        echo "❌ Failed: " . basename($filePath) . "\n";
        return false;
    }
}

function scanAndFix($dir, &$stats) {
    if (!is_dir($dir)) {
        return;
    }
    
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            fixFile($file->getPathname(), $stats);
        }
    }
}

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║              Permission Fix Script                            ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "Scanning and fixing files...\n\n";

scanAndFix($viewsDir, $stats);

echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║                      Fix Summary                               ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "Files fixed:     {$stats['total_fixed']}\n";
echo "Files skipped:   {$stats['skipped']}\n";
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
    echo "3. Adjust permissions as needed\n";
} else {
    echo "\nℹ️  No files needed fixing.\n";
}

echo "\n";
?>
