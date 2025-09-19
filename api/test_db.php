<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/config.php';

try {
    $response = ['status' => 'ok', 'tests' => []];
    
    // Test database connection
    if (isset($conn)) {
        $response['tests']['database'] = 'Connected';
        
        // Test members table
        $result = $conn->query("SELECT COUNT(*) as count FROM members WHERE status = 'active'");
        if ($result) {
            $row = $result->fetch_assoc();
            $response['tests']['members_count'] = $row['count'];
        }
        
        // Test payment_types table
        $result = $conn->query("SELECT COUNT(*) as count FROM payment_types");
        if ($result) {
            $row = $result->fetch_assoc();
            $response['tests']['payment_types_count'] = $row['count'];
        }
        
        // Test payments table structure
        $result = $conn->query("DESCRIBE payments");
        if ($result) {
            $columns = [];
            while ($row = $result->fetch_assoc()) {
                $columns[] = $row['Field'] . ' (' . $row['Type'] . ')';
            }
            $response['tests']['payments_columns'] = $columns;
        }
        
        // Test recent payments
        $result = $conn->query("SELECT id, member_id, payment_type_id, amount, payment_period, payment_date FROM payments ORDER BY id DESC LIMIT 3");
        if ($result) {
            $payments = [];
            while ($row = $result->fetch_assoc()) {
                $payments[] = $row;
            }
            $response['tests']['recent_payments'] = $payments;
        }
        
        // Get sample member
        $result = $conn->query("SELECT id, crn, CONCAT(first_name, ' ', last_name) as name FROM members WHERE status = 'active' LIMIT 1");
        if ($result && $result->num_rows > 0) {
            $response['tests']['sample_member'] = $result->fetch_assoc();
        }
        
        // Get sample payment type
        $result = $conn->query("SELECT id, name FROM payment_types LIMIT 1");
        if ($result && $result->num_rows > 0) {
            $response['tests']['sample_payment_type'] = $result->fetch_assoc();
        }
        
    } else {
        $response['tests']['database'] = 'Not connected';
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>
