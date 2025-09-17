<?php
session_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/controllers/RoleController.php';

// Simulate being logged in as admin
$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = 1;

$controller = new RoleController($conn);

echo "<h2>Testing Role Creation</h2>";

// Test data
$test_data = [
    'name' => 'Test Role ' . date('Y-m-d H:i:s'),
    'description' => 'Test description'
];

echo "<p>Test data: " . json_encode($test_data) . "</p>";

try {
    $result = $controller->create($test_data);
    echo "<p>Result: " . json_encode($result) . "</p>";
    
    if (isset($result['error'])) {
        echo "<p style='color: red;'>Error: " . $result['error'] . "</p>";
    } else {
        echo "<p style='color: green;'>Success! Role created with ID: " . $result['id'] . "</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>Exception: " . $e->getMessage() . "</p>";
}

// Check database connection
echo "<h3>Database Connection Test</h3>";
if ($conn) {
    echo "<p style='color: green;'>Database connected successfully</p>";
    
    // Test roles table
    $result = $conn->query("SHOW TABLES LIKE 'roles'");
    if ($result->num_rows > 0) {
        echo "<p style='color: green;'>Roles table exists</p>";
        
        // Check table structure
        $result = $conn->query("DESCRIBE roles");
        echo "<p>Roles table structure:</p><ul>";
        while ($row = $result->fetch_assoc()) {
            echo "<li>" . $row['Field'] . " - " . $row['Type'] . "</li>";
        }
        echo "</ul>";
    } else {
        echo "<p style='color: red;'>Roles table does not exist</p>";
    }
} else {
    echo "<p style='color: red;'>Database connection failed</p>";
}
?>
