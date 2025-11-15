<?php
/**
 * Comprehensive Permission Audit
 * Scans ALL PHP files in views and api folders
 */

$rootDir = dirname(__DIR__);
$viewsDir = $rootDir . '/views';
$apiDir = $rootDir . '/api';

$stats = [
    'views_total' => 0,
    'views_secured' => 0,
    'views_missing' => [],
    'api_total' => 0,
    'api_secured' => 0,
    'api_missing' => []
];

// Files that should NOT have auth (public endpoints, callbacks, etc.)
$publicFiles = [
    'complete_registration.php',
    'complete_registration_admin.php',
    'hubtel_callback.php',
    'hubtel_callback_v2.php',
    'paystack_callback.php',
    'errors/403.php',
    'errors/404.php',
    'errors/500.php'
];

// Patterns for files that should NOT have auth
$publicPatterns = [
    '/partials/',
    '/_modal',
    '/_scripts',
    '/_nav',
    '/errors/',
    'callback',
    'member_dashboard.php',
    'member_profile.php',
    'profile.php',
    'profile_edit.php'
];

function shouldBePublic($relativePath) {
    global $publicFiles, $publicPatterns;
    
    $fileName = basename($relativePath);
    
    // Check exact matches
    if (in_array($fileName, $publicFiles)) {
        return true;
    }
    
    // Check patterns
    foreach ($publicPatterns as $pattern) {
        if (strpos($relativePath, $pattern) !== false) {
            return true;
        }
    }
    
    return false;
}

function checkFile($filePath, $baseDir, &$stats, $type = 'views') {
    $content = file_get_contents($filePath);
    $relativePath = str_replace($baseDir, '', $filePath);
    $fileName = basename($filePath);
    
    $key = $type . '_total';
    $securedKey = $type . '_secured';
    $missingKey = $type . '_missing';
    
    $stats[$key]++;
    
    // Check if it should be public
    if (shouldBePublic($relativePath)) {
        $stats[$securedKey]++;
        return;
    }
    
    // Check for authentication
    $hasAuth = preg_match('/is_logged_in\(\)|session_start\(\).*is_logged_in/', $content);
    $hasPermission = preg_match('/has_permission\(|require_permission\(/', $content);
    $hasSuperAdmin = preg_match('/is_super_admin|role_id.*==.*1|user_id.*==.*3/', $content);
    $hasRequireAuth = preg_match('/require_once.*auth\.php/', $content);
    
    // For API files, check for JSON auth response or BaseAPI usage
    $hasApiAuth = false;
    if ($type === 'api') {
        $hasApiAuth = preg_match('/http_response_code\(401\)|Unauthorized|extends\s+BaseAPI|require_once.*BaseAPI/', $content);
    }
    
    // Check for webhooks (should not have auth)
    $isWebhook = preg_match('/webhook|callback|ussd_service/', $fileName);
    $isTestFile = preg_match('/test_|debug_/', $fileName);
    
    // Webhooks and test files are OK without auth
    if ($isWebhook || $isTestFile) {
        $stats[$securedKey]++;
        return;
    }
    
    if ($hasAuth || $hasRequireAuth || ($type === 'api' && $hasApiAuth)) {
        if ($hasPermission || $hasSuperAdmin || ($type === 'api' && $hasApiAuth)) {
            $stats[$securedKey]++;
        } else {
            // Has auth but no permission check
            $stats[$missingKey][] = [
                'file' => $relativePath,
                'issue' => 'Has auth but no permission check',
                'has_auth' => true,
                'has_permission' => false
            ];
        }
    } else {
        // No auth at all
        $stats[$missingKey][] = [
            'file' => $relativePath,
            'issue' => 'No authentication',
            'has_auth' => false,
            'has_permission' => $hasPermission
        ];
    }
}

function scanDirectory($dir, $baseDir, &$stats, $type = 'views') {
    if (!is_dir($dir)) {
        return;
    }
    
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            checkFile($file->getPathname(), $baseDir, $stats, $type);
        }
    }
}

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║         Comprehensive Permission Audit                        ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "Scanning Views folder...\n";
scanDirectory($viewsDir, $viewsDir, $stats, 'views');

echo "Scanning API folder...\n";
scanDirectory($apiDir, $apiDir, $stats, 'api');

echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║                      Audit Results                             ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "VIEWS FOLDER:\n";
echo "  Total files:        {$stats['views_total']}\n";
echo "  Properly secured:   {$stats['views_secured']}\n";
echo "  Missing security:   " . count($stats['views_missing']) . "\n\n";

echo "API FOLDER:\n";
echo "  Total files:        {$stats['api_total']}\n";
echo "  Properly secured:   {$stats['api_secured']}\n";
echo "  Missing security:   " . count($stats['api_missing']) . "\n\n";

if (!empty($stats['views_missing'])) {
    echo "╔════════════════════════════════════════════════════════════════╗\n";
    echo "║              VIEWS - Missing Security                         ║\n";
    echo "╚════════════════════════════════════════════════════════════════╝\n\n";
    
    foreach ($stats['views_missing'] as $item) {
        echo "File: {$item['file']}\n";
        echo "  Issue: {$item['issue']}\n";
        echo "  Auth: " . ($item['has_auth'] ? 'Yes' : 'No') . "\n";
        echo "  Permission: " . ($item['has_permission'] ? 'Yes' : 'No') . "\n\n";
    }
}

if (!empty($stats['api_missing'])) {
    echo "╔════════════════════════════════════════════════════════════════╗\n";
    echo "║              API - Missing Security                           ║\n";
    echo "╚════════════════════════════════════════════════════════════════╝\n\n";
    
    foreach ($stats['api_missing'] as $item) {
        echo "File: {$item['file']}\n";
        echo "  Issue: {$item['issue']}\n";
        echo "  Auth: " . ($item['has_auth'] ? 'Yes' : 'No') . "\n";
        echo "  Permission: " . ($item['has_permission'] ? 'Yes' : 'No') . "\n\n";
    }
}

$totalFiles = $stats['views_total'] + $stats['api_total'];
$totalSecured = $stats['views_secured'] + $stats['api_secured'];
$totalMissing = count($stats['views_missing']) + count($stats['api_missing']);

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                      Summary                                   ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "Total files scanned:    $totalFiles\n";
echo "Properly secured:       $totalSecured\n";
echo "Missing security:       $totalMissing\n";
$percentage = $totalFiles > 0 ? round(($totalSecured / $totalFiles) * 100, 1) : 0;
echo "Security coverage:      $percentage%\n\n";

if ($totalMissing === 0) {
    echo "✅ All files have proper security!\n";
} else {
    echo "⚠️  $totalMissing files need security fixes\n";
}

echo "\n";
?>
