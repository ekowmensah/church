<?php
/**
 * Comprehensive Permission Fix
 * Fixes all views and API files with appropriate security
 */

$rootDir = dirname(__DIR__);
$viewsDir = $rootDir . '/views';

$stats = [
    'fixed' => 0,
    'skipped' => 0,
    'errors' => []
];

// Files that should remain without auth
$skipFiles = [
    'partials/',
    'errors/',
    '_modal',
    '_scripts',
    '_nav',
    'callback',
    'complete_registration',
    'member_dashboard',
    'member_profile',
    'profile.php',
    'profile_edit.php',
    'adherent_modals',
    'adherent_scripts',
    'status_modals',
    'status_scripts',
    'send_message_modal',
    'visitor_sms_modal',
    'organization_assign_leader_modal',
    'event_calendar',
    'health_print',
    'health_bp_graph',
    'upcoming_events',
    '_membership_report_',
    'debug_',
    'test_'
];

// Permission mappings for different file types
$permissionMappings = [
    'ajax_' => 'view_dashboard',
    'make_payment' => 'make_payment',
    'member_' => 'view_member',
    'payment_' => 'view_payment_list',
    'attendance_' => 'view_attendance_list',
    'bibleclass_' => 'view_bibleclass_list',
    'export_sms' => 'send_sms',
    'respond_member_feedback' => 'view_feedback_report',
    'send_member_message' => 'send_sms',
    'memberfeedback_thread' => 'view_feedback_report',
    'member_registered_events' => 'view_event_list',
    'paymenttype_add' => 'view_payment_list',
    'get_class_members' => 'view_bibleclass_list',
    'get_next_crn' => 'view_member',
    'health_form_prefill' => 'view_health_list',
    'ajax_validate_role' => 'manage_roles'
];

function shouldSkip($relativePath) {
    global $skipFiles;
    foreach ($skipFiles as $pattern) {
        if (strpos($relativePath, $pattern) !== false) {
            return true;
        }
    }
    return false;
}

function getPermission($fileName) {
    global $permissionMappings;
    
    foreach ($permissionMappings as $pattern => $permission) {
        if (strpos($fileName, $pattern) !== false) {
            return $permission;
        }
    }
    
    return 'view_dashboard';
}

function fixViewFile($filePath, &$stats) {
    $relativePath = str_replace($GLOBALS['viewsDir'], '', $filePath);
    $fileName = basename($filePath);
    
    if (shouldSkip($relativePath)) {
        $stats['skipped']++;
        return false;
    }
    
    $content = file_get_contents($filePath);
    
    // Check if already has proper auth
    if (preg_match('/is_logged_in\(\)/', $content) && preg_match('/has_permission\(/', $content)) {
        return false;
    }
    
    // Check if has auth but no permission
    $hasAuth = preg_match('/is_logged_in\(\)/', $content);
    $hasPermission = preg_match('/has_permission\(/', $content);
    
    if (!$hasAuth) {
        // No auth at all - add full security block
        $permission = getPermission($fileName);
        
        $securityBlock = "<?php\n";
        $securityBlock .= "session_start();\n";
        $securityBlock .= "require_once __DIR__.'/../config/config.php';\n";
        $securityBlock .= "require_once __DIR__.'/../helpers/auth.php';\n";
        $securityBlock .= "require_once __DIR__.'/../helpers/permissions_v2.php';\n\n";
        $securityBlock .= "// Authentication check\n";
        $securityBlock .= "if (!is_logged_in()) {\n";
        $securityBlock .= "    http_response_code(401);\n";
        $securityBlock .= "    echo json_encode(['success' => false, 'error' => 'Unauthorized']);\n";
        $securityBlock .= "    exit;\n";
        $securityBlock .= "}\n\n";
        $securityBlock .= "// Permission check\n";
        $securityBlock .= "if (!has_permission('$permission')) {\n";
        $securityBlock .= "    http_response_code(403);\n";
        $securityBlock .= "    echo json_encode(['success' => false, 'error' => 'Forbidden']);\n";
        $securityBlock .= "    exit;\n";
        $securityBlock .= "}\n";
        $securityBlock .= "?>\n";
        
        $content = preg_replace('/^<\?php\s*\n?/', '', $content);
        $newContent = $securityBlock . $content;
        
    } elseif ($hasAuth && !$hasPermission) {
        // Has auth but no permission - add permission check only
        $permission = getPermission($fileName);
        
        $permissionBlock = "\n// Permission check\n";
        $permissionBlock .= "if (!has_permission('$permission')) {\n";
        $permissionBlock .= "    http_response_code(403);\n";
        $permissionBlock .= "    echo json_encode(['success' => false, 'error' => 'Forbidden']);\n";
        $permissionBlock .= "    exit;\n";
        $permissionBlock .= "}\n";
        
        // Find position after is_logged_in() check
        if (preg_match('/(if\s*\(\s*!is_logged_in\(\)\s*\)\s*\{[^}]+\})/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $insertPos = $matches[0][1] + strlen($matches[0][0]);
            $newContent = substr($content, 0, $insertPos) . $permissionBlock . substr($content, $insertPos);
        } else {
            return false;
        }
    } else {
        return false;
    }
    
    if (file_put_contents($filePath, $newContent)) {
        $stats['fixed']++;
        echo "✅ Fixed: $relativePath\n";
        return true;
    } else {
        $stats['errors'][] = $relativePath;
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
            fixViewFile($file->getPathname(), $stats);
        }
    }
}

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║         Comprehensive Permission Fix                          ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "Fixing views folder...\n\n";
scanAndFix($viewsDir, $stats);

echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║                      Fix Summary                               ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "Files fixed:     {$stats['fixed']}\n";
echo "Files skipped:   {$stats['skipped']}\n";
echo "Errors:          " . count($stats['errors']) . "\n\n";

if (!empty($stats['errors'])) {
    echo "❌ Errors:\n";
    foreach ($stats['errors'] as $error) {
        echo "  - $error\n";
    }
}

if ($stats['fixed'] > 0) {
    echo "\n✅ Permission checks added successfully!\n";
    echo "\nNext steps:\n";
    echo "1. Run comprehensive_audit.php again to verify\n";
    echo "2. Test the updated pages\n";
} else {
    echo "\nℹ️  No files needed fixing.\n";
}

echo "\n";
?>
