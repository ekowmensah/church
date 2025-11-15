<?php
/**
 * Fix API Files
 * Add authentication to API files that need it
 */

$rootDir = dirname(__DIR__);
$apiDir = $rootDir . '/api';

$filesToFix = [
    'payment_periods.php',
    'payment_history.php',
    'initiate_payment.php',
    'process_payment.php',
    'validate_member.php'
];

$fixed = 0;

foreach ($filesToFix as $fileName) {
    $filePath = $apiDir . '/' . $fileName;
    
    if (!file_exists($filePath)) {
        echo "⚠️  Not found: $fileName\n";
        continue;
    }
    
    $content = file_get_contents($filePath);
    
    // Check if already has auth
    if (preg_match('/is_logged_in\(\)/', $content)) {
        echo "✓ Already secured: $fileName\n";
        continue;
    }
    
    // Add auth block after first <?php
    $authBlock = "\nsession_start();\nrequire_once __DIR__.'/../config/config.php';\nrequire_once __DIR__.'/../helpers/auth.php';\n\nheader('Content-Type: application/json');\n\n// Authentication check\nif (!is_logged_in()) {\n    http_response_code(401);\n    echo json_encode(['success' => false, 'error' => 'Unauthorized']);\n    exit;\n}\n";
    
    // Replace first occurrence of config include
    $content = preg_replace(
        "/(require_once __DIR__\.'\/\.\.\/config\/config\.php';)/",
        $authBlock,
        $content,
        1
    );
    
    // Remove any existing header() call since we added it
    $content = preg_replace("/header\('Content-Type: application\/json'\);\n/", "", $content);
    
    if (file_put_contents($filePath, $content)) {
        echo "✅ Fixed: $fileName\n";
        $fixed++;
    } else {
        echo "❌ Failed: $fileName\n";
    }
}

echo "\n";
echo "Fixed: $fixed files\n";
?>
