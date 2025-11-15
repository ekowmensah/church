<?php
/**
 * Find pages that are missing permission checks
 * More thorough scan
 */

$rootDir = dirname(__DIR__);
$viewsDir = $rootDir . '/views';

$missingPermissions = [];

function checkFile($filePath) {
    global $missingPermissions;
    
    $content = file_get_contents($filePath);
    $fileName = basename($filePath);
    $relativePath = str_replace($GLOBALS['viewsDir'], '/views', $filePath);
    
    // Skip certain files
    $skipPatterns = [
        '/partials/',
        '/errors/',
        '_modal',
        '_scripts',
        '_nav',
        'callback',
        'complete_registration',
        'member_dashboard',
        'member_profile',
        'profile.php',
        'profile_edit.php'
    ];
    
    foreach ($skipPatterns as $pattern) {
        if (strpos($relativePath, $pattern) !== false) {
            return;
        }
    }
    
    // Check if it's a page that should have permissions
    $needsPermissions = preg_match('/(list|form|delete|edit|create|manage|settings|view)\.php$/', $fileName);
    
    if (!$needsPermissions) {
        return;
    }
    
    // Check for permission checks
    $hasAuth = preg_match('/is_logged_in\(\)/', $content);
    $hasPermission = preg_match('/has_permission\(|require_permission\(/', $content);
    $hasSuperAdmin = preg_match('/is_super_admin|role_id.*==.*1|user_id.*==.*3/', $content);
    
    if (!$hasAuth || (!$hasPermission && !$hasSuperAdmin)) {
        $missingPermissions[] = [
            'file' => $relativePath,
            'has_auth' => $hasAuth,
            'has_permission' => $hasPermission,
            'has_super_admin' => $hasSuperAdmin
        ];
    }
}

function scanDirectory($dir) {
    if (!is_dir($dir)) {
        return;
    }
    
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            checkFile($file->getPathname());
        }
    }
}

echo "Scanning for pages missing permission checks...\n\n";

scanDirectory($viewsDir);

if (empty($missingPermissions)) {
    echo "✅ All pages have proper permission checks!\n";
} else {
    echo "Found " . count($missingPermissions) . " pages missing permission checks:\n\n";
    
    foreach ($missingPermissions as $item) {
        echo "File: {$item['file']}\n";
        echo "  Auth: " . ($item['has_auth'] ? 'Yes' : 'No') . "\n";
        echo "  Permission: " . ($item['has_permission'] ? 'Yes' : 'No') . "\n";
        echo "  Super Admin: " . ($item['has_super_admin'] ? 'Yes' : 'No') . "\n\n";
    }
}
?>
