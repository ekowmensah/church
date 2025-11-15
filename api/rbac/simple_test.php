<?php
/**
 * Simple API Test - Direct PHP Test
 * Run this file directly: http://localhost/church/api/rbac/simple_test.php
 */

// Start session
session_start();

// Set test user (Super Admin)
$_SESSION['user_id'] = 3;
$_SESSION['is_super_admin'] = true;

?>
<!DOCTYPE html>
<html>
<head>
    <title>RBAC API Simple Test</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .test-result {
            background: white;
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
            border-left: 4px solid #4CAF50;
        }
        .test-result.error {
            border-left-color: #f44336;
        }
        h1 {
            color: #333;
        }
        h2 {
            color: #666;
            margin-top: 30px;
        }
        pre {
            background: #f9f9f9;
            padding: 10px;
            border-radius: 3px;
            overflow-x: auto;
        }
        .success {
            color: #4CAF50;
        }
        .error {
            color: #f44336;
        }
    </style>
</head>
<body>
    <h1>🔐 RBAC API Simple Test</h1>
    <p>Testing API endpoints directly from PHP...</p>
    
    <?php
    
    function testEndpoint($name, $file) {
        echo "<div class='test-result'>";
        echo "<h3>$name</h3>";
        
        // Check if file exists
        if (!file_exists(__DIR__ . '/' . $file)) {
            echo "<p class='error'>❌ File not found: $file</p>";
            echo "</div>";
            return;
        }
        
        // Capture output
        ob_start();
        try {
            include $file;
            $output = ob_get_clean();
            
            // Try to decode as JSON
            $json = json_decode($output, true);
            
            if ($json) {
                if (isset($json['success']) && $json['success']) {
                    echo "<p class='success'>✅ SUCCESS</p>";
                    echo "<pre>" . json_encode($json, JSON_PRETTY_PRINT) . "</pre>";
                } else {
                    echo "<p class='error'>❌ FAILED</p>";
                    echo "<pre>" . json_encode($json, JSON_PRETTY_PRINT) . "</pre>";
                }
            } else {
                echo "<p class='error'>❌ Invalid JSON response</p>";
                echo "<pre>" . htmlspecialchars($output) . "</pre>";
            }
        } catch (Exception $e) {
            ob_end_clean();
            echo "<p class='error'>❌ Error: " . $e->getMessage() . "</p>";
        }
        
        echo "</div>";
    }
    
    // Test Permissions API
    echo "<h2>📋 Permissions API</h2>";
    $_GET = []; // Reset GET params
    testEndpoint("List All Permissions", "permissions.php");
    
    $_GET = ['id' => 1];
    testEndpoint("Get Permission #1", "permissions.php");
    
    // Test Roles API
    echo "<h2>👥 Roles API</h2>";
    $_GET = [];
    testEndpoint("List All Roles", "roles.php");
    
    $_GET = ['id' => 1];
    testEndpoint("Get Role #1", "roles.php");
    
    // Test Audit API
    echo "<h2>📝 Audit Logs API</h2>";
    $_GET = ['limit' => 5];
    testEndpoint("Recent Audit Logs", "audit.php");
    
    $_GET = ['stats' => true];
    testEndpoint("Audit Statistics", "audit.php");
    
    // Test Templates API
    echo "<h2>📋 Templates API</h2>";
    $_GET = [];
    testEndpoint("List All Templates", "templates.php");
    
    $_GET = ['id' => 2];
    testEndpoint("Get Template #2 (Cashier)", "templates.php");
    
    ?>
    
    <h2>✅ Test Complete</h2>
    <p>All API endpoints have been tested. Check results above.</p>
    
    <h3>Next Steps:</h3>
    <ul>
        <li>Open the interactive test console: <a href="test.html">test.html</a></li>
        <li>Review API documentation: <a href="README.md">README.md</a></li>
        <li>Test with JavaScript fetch() calls</li>
    </ul>
</body>
</html>
