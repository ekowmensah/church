<?php
/**
 * API Test Script
 * Tests all RBAC API endpoints
 */

// Start session to simulate logged-in user
session_start();

// Set a test user (Super Admin)
$_SESSION['user_id'] = 3;
$_SESSION['is_super_admin'] = true;

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                  RBAC API Test Suite                           ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

$baseUrl = 'http://localhost/church/api/rbac/';
$passed = 0;
$failed = 0;

function testAPI($name, $endpoint, $method = 'GET', $data = null) {
    global $passed, $failed;
    
    echo "Testing: $name\n";
    
    $url = $GLOBALS['baseUrl'] . $endpoint;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
    
    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($httpCode >= 200 && $httpCode < 300 && isset($result['success']) && $result['success']) {
        echo "  ✅ PASS - HTTP $httpCode\n";
        if (isset($result['data'])) {
            if (is_array($result['data']) && isset($result['data']['total'])) {
                echo "     Total: {$result['data']['total']}\n";
            }
        }
        $passed++;
    } else {
        echo "  ❌ FAIL - HTTP $httpCode\n";
        if (isset($result['error'])) {
            echo "     Error: {$result['error']}\n";
        }
        $failed++;
    }
    echo "\n";
}

// ============================================
// TEST PERMISSIONS API
// ============================================
echo "📋 PERMISSIONS API\n";
echo str_repeat("─", 64) . "\n";

testAPI("List all permissions", "permissions.php");
testAPI("Get permission by ID", "permissions.php?id=1");
testAPI("Get grouped permissions", "permissions.php?grouped=true");
testAPI("Search permissions", "permissions.php?search=member");
testAPI("Filter by category", "permissions.php?category_id=1");

// ============================================
// TEST ROLES API
// ============================================
echo "👥 ROLES API\n";
echo str_repeat("─", 64) . "\n";

testAPI("List all roles", "roles.php");
testAPI("Get role by ID", "roles.php?id=1");
testAPI("Get role hierarchy", "roles.php?hierarchy=true");
testAPI("Get role permissions", "roles.php?id=1&permissions");
testAPI("Get role permissions (no inheritance)", "roles.php?id=1&permissions&include_inherited=false");

// ============================================
// TEST AUDIT API
// ============================================
echo "📝 AUDIT LOGS API\n";
echo str_repeat("─", 64) . "\n";

testAPI("List audit logs", "audit.php?limit=10");
testAPI("Get audit statistics", "audit.php?stats");
testAPI("Get active users", "audit.php?active_users&days=7&limit=5");
testAPI("Get permission usage", "audit.php?permission_usage&days=7&limit=10");
testAPI("Get failed checks", "audit.php?failed_checks&limit=10");

// ============================================
// TEST TEMPLATES API
// ============================================
echo "📋 TEMPLATES API\n";
echo str_repeat("─", 64) . "\n";

testAPI("List all templates", "templates.php");
testAPI("Get template by ID", "templates.php?id=2");
testAPI("Get template usage", "templates.php?id=2&usage");
testAPI("Filter by category", "templates.php?category=church");

// ============================================
// SUMMARY
// ============================================
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                        TEST RESULTS                            ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

$total = $passed + $failed;
$passRate = $total > 0 ? round(($passed / $total) * 100, 1) : 0;

echo "Total Tests:   $total\n";
echo "Passed:        $passed ✅\n";
echo "Failed:        $failed ❌\n";
echo "Pass Rate:     $passRate%\n\n";

if ($failed == 0) {
    echo "╔════════════════════════════════════════════════════════════════╗\n";
    echo "║          🎉 ALL API TESTS PASSED! READY TO USE! 🎉           ║\n";
    echo "╚════════════════════════════════════════════════════════════════╝\n";
} else {
    echo "╔════════════════════════════════════════════════════════════════╗\n";
    echo "║     ⚠️  SOME TESTS FAILED - CHECK ERRORS ABOVE ⚠️            ║\n";
    echo "╚════════════════════════════════════════════════════════════════╝\n";
}

echo "\n";
echo "Next steps:\n";
echo "1. Open the test console: http://localhost/church/api/rbac/test.html\n";
echo "2. Test APIs manually in your browser\n";
echo "3. Check API documentation: /church/api/rbac/README.md\n";
