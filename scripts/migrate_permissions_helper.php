<?php
/**
 * Migration Script: Update all files to use permissions_v2.php
 * This script replaces all references to helpers/permissions.php with helpers/permissions_v2.php
 */

$rootDir = dirname(__DIR__);
$viewsDir = $rootDir . '/views';
$controllersDir = $rootDir . '/controllers';
$apiDir = $rootDir . '/api';

$stats = [
    'total_files' => 0,
    'updated_files' => 0,
    'errors' => []
];

function updateFile($filePath, &$stats) {
    $content = file_get_contents($filePath);
    $originalContent = $content;
    
    // Replace permissions.php with permissions_v2.php
    $patterns = [
        "/require_once\s+__DIR__\s*\.\s*'\/\.\.\/helpers\/permissions\.php';/",
        "/require_once\s+__DIR__\s*\.\s*\"\/\.\.\/helpers\/permissions\.php\";/",
        "/require_once\s+__DIR__\s*\.\s*'\/\.\.\/\.\.\/helpers\/permissions\.php';/",
        "/require_once\s+__DIR__\s*\.\s*\"\/\.\.\/\.\.\/helpers\/permissions\.php\";/",
    ];
    
    $replacements = [
        "require_once __DIR__.'/../helpers/permissions_v2.php';",
        "require_once __DIR__.'/../helpers/permissions_v2.php';",
        "require_once __DIR__.'/../../helpers/permissions_v2.php';",
        "require_once __DIR__.'/../../helpers/permissions_v2.php';",
    ];
    
    foreach ($patterns as $index => $pattern) {
        $content = preg_replace($pattern, $replacements[$index], $content);
    }
    
    if ($content !== $originalContent) {
        if (file_put_contents($filePath, $content)) {
            $stats['updated_files']++;
            echo "✅ Updated: " . basename($filePath) . "\n";
            return true;
        } else {
            $stats['errors'][] = "Failed to write: $filePath";
            echo "❌ Failed: " . basename($filePath) . "\n";
            return false;
        }
    }
    
    return false;
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
            $stats['total_files']++;
            updateFile($file->getPathname(), $stats);
        }
    }
}

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║         Permissions Helper Migration Script                   ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "Scanning directories...\n\n";

// Scan views directory
echo "📁 Views Directory:\n";
scanDirectory($viewsDir, $stats);

echo "\n📁 Controllers Directory:\n";
scanDirectory($controllersDir, $stats);

echo "\n📁 API Directory:\n";
scanDirectory($apiDir, $stats);

echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║                      Migration Summary                         ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "Total files scanned:  {$stats['total_files']}\n";
echo "Files updated:        {$stats['updated_files']}\n";
echo "Errors:               " . count($stats['errors']) . "\n\n";

if (!empty($stats['errors'])) {
    echo "❌ Errors encountered:\n";
    foreach ($stats['errors'] as $error) {
        echo "  - $error\n";
    }
}

if ($stats['updated_files'] > 0) {
    echo "\n✅ Migration completed successfully!\n";
    echo "\nNext steps:\n";
    echo "1. Test the updated pages\n";
    echo "2. Verify permission checks work correctly\n";
    echo "3. Check for any errors in the application\n";
} else {
    echo "\nℹ️  No files needed updating.\n";
}

echo "\n";
?>
