<?php
/**
 * Fix function_exists('has_permission') Pattern
 * Replaces old defensive permission checks with proper implementation
 */

$rootDir = dirname(__DIR__);
$viewsDir = $rootDir . '/views';

$stats = [
    'fixed' => 0,
    'errors' => []
];

function fixFile($filePath, &$stats) {
    $content = file_get_contents($filePath);
    $fileName = basename($filePath);
    $relativePath = str_replace($GLOBALS['viewsDir'], '', $filePath);
    
    // Check if file has the pattern
    if (!preg_match('/function_exists\([\'"]has_permission[\'"]\)/', $content)) {
        return false;
    }
    
    $originalContent = $content;
    
    // Ensure permissions_v2.php is included
    if (!preg_match('/require_once.*permissions_v2\.php/', $content)) {
        // Add after auth.php include
        $content = preg_replace(
            "/(require_once __DIR__\.'\/\.\.\/helpers\/auth\.php';)/",
            "$1\nrequire_once __DIR__.'/../helpers/permissions_v2.php';",
            $content
        );
    }
    
    // Pattern 1: function_exists('has_permission') && has_permission('x')
    $content = preg_replace(
        "/function_exists\(['\"]has_permission['\"]\)\s*&&\s*has_permission\(/",
        "has_permission(",
        $content
    );
    
    // Pattern 2: function_exists('has_permission') && !has_permission('x')
    $content = preg_replace(
        "/function_exists\(['\"]has_permission['\"]\)\s*&&\s*!has_permission\(/",
        "!has_permission(",
        $content
    );
    
    // Pattern 3: !function_exists('has_permission') || !has_permission('x')
    $content = preg_replace(
        "/!\s*function_exists\(['\"]has_permission['\"]\)\s*\|\|\s*!has_permission\(/",
        "!has_permission(",
        $content
    );
    
    // Pattern 4: (function_exists('has_permission') && has_permission('x'))
    $content = preg_replace(
        "/\(function_exists\(['\"]has_permission['\"]\)\s*&&\s*has_permission\(/",
        "(has_permission(",
        $content
    );
    
    if ($content !== $originalContent) {
        if (file_put_contents($filePath, $content)) {
            $stats['fixed']++;
            echo "✅ Fixed: $relativePath\n";
            return true;
        } else {
            $stats['errors'][] = $relativePath;
            echo "❌ Failed: $relativePath\n";
            return false;
        }
    }
    
    return false;
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
echo "║      Fix function_exists('has_permission') Pattern            ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "Scanning and fixing files...\n\n";

scanAndFix($viewsDir, $stats);

echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║                      Fix Summary                               ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "Files fixed:     {$stats['fixed']}\n";
echo "Errors:          " . count($stats['errors']) . "\n\n";

if (!empty($stats['errors'])) {
    echo "❌ Errors:\n";
    foreach ($stats['errors'] as $error) {
        echo "  - $error\n";
    }
}

if ($stats['fixed'] > 0) {
    echo "\n✅ Pattern fixed successfully!\n";
    echo "\nNext steps:\n";
    echo "1. Test the updated pages\n";
    echo "2. Verify permission checks work correctly\n";
} else {
    echo "\nℹ️  No files needed fixing.\n";
}

echo "\n";
?>
