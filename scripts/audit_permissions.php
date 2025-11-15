<?php
/**
 * Permission Audit Script
 * Scans all view files to ensure proper permission checks are in place
 */

$rootDir = dirname(__DIR__);
$viewsDir = $rootDir . '/views';

$stats = [
    'total_files' => 0,
    'with_permission_check' => 0,
    'without_permission_check' => 0,
    'with_auth_only' => 0,
    'issues' => []
];

function analyzeFile($filePath, &$stats) {
    $content = file_get_contents($filePath);
    $fileName = basename($filePath);
    
    // Skip certain files
    $skipFiles = [
        'complete_registration.php',
        'complete_registration_admin.php',
        'member_dashboard.php',
        'member_profile.php',
        'member_profile_edit.php',
        'profile.php',
        'profile_edit.php',
        'errors/',
        'test_',
        'ajax_test_'
    ];
    
    foreach ($skipFiles as $skip) {
        if (strpos($fileName, $skip) !== false || strpos($filePath, $skip) !== false) {
            return;
        }
    }
    
    $stats['total_files']++;
    
    $hasAuthCheck = preg_match('/is_logged_in\(\)/', $content);
    $hasPermissionCheck = preg_match('/has_permission\(/', $content);
    $hasSuperAdminCheck = preg_match('/is_super_admin|role_id.*==.*1|user_id.*==.*3/', $content);
    $hasRequirePermission = preg_match('/require_permission\(/', $content);
    
    $issue = null;
    
    if (!$hasAuthCheck && !$hasPermissionCheck) {
        $issue = "❌ CRITICAL: No authentication or permission check";
        $stats['without_permission_check']++;
    } elseif ($hasAuthCheck && !$hasPermissionCheck && !$hasSuperAdminCheck && !$hasRequirePermission) {
        // Check if it's a form or list page that should have permissions
        if (preg_match('/(list|form|delete|edit|create|manage|settings)\.php$/', $fileName)) {
            $issue = "⚠️  WARNING: Has auth but no permission check";
            $stats['with_auth_only']++;
        } else {
            $stats['with_permission_check']++;
        }
    } else {
        $stats['with_permission_check']++;
    }
    
    if ($issue) {
        $stats['issues'][] = [
            'file' => str_replace($GLOBALS['rootDir'], '', $filePath),
            'issue' => $issue,
            'has_auth' => $hasAuthCheck,
            'has_permission' => $hasPermissionCheck,
            'has_super_admin' => $hasSuperAdminCheck
        ];
    }
}

function scanDirectory($dir, &$stats) {
    if (!is_dir($dir)) {
        return;
    }
    
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            analyzeFile($file->getPathname(), $stats);
        }
    }
}

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║              Permission Audit Script                          ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "Scanning views directory...\n\n";

scanDirectory($viewsDir, $stats);

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                      Audit Summary                             ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "Total files scanned:              {$stats['total_files']}\n";
echo "Files with permission checks:     {$stats['with_permission_check']}\n";
echo "Files with auth only:             {$stats['with_auth_only']}\n";
echo "Files without checks:             {$stats['without_permission_check']}\n\n";

if (!empty($stats['issues'])) {
    echo "╔════════════════════════════════════════════════════════════════╗\n";
    echo "║                      Issues Found                              ║\n";
    echo "╚════════════════════════════════════════════════════════════════╝\n\n";
    
    $critical = array_filter($stats['issues'], function($i) { return strpos($i['issue'], 'CRITICAL') !== false; });
    $warnings = array_filter($stats['issues'], function($i) { return strpos($i['issue'], 'WARNING') !== false; });
    
    if (!empty($critical)) {
        echo "❌ CRITICAL ISSUES (" . count($critical) . "):\n";
        echo str_repeat("─", 64) . "\n";
        foreach ($critical as $issue) {
            echo "File: {$issue['file']}\n";
            echo "  {$issue['issue']}\n";
            echo "  Auth: " . ($issue['has_auth'] ? 'Yes' : 'No') . " | ";
            echo "Permission: " . ($issue['has_permission'] ? 'Yes' : 'No') . "\n\n";
        }
    }
    
    if (!empty($warnings)) {
        echo "\n⚠️  WARNINGS (" . count($warnings) . "):\n";
        echo str_repeat("─", 64) . "\n";
        foreach ($warnings as $issue) {
            echo "File: {$issue['file']}\n";
            echo "  {$issue['issue']}\n\n";
        }
    }
} else {
    echo "✅ No issues found! All files have proper permission checks.\n";
}

echo "\n";
?>
