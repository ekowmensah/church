<?php
require_once __DIR__.'/../config/config.php';

header('Content-Type: text/plain');

echo "=== PAYMENTS TABLE DEBUG ===\n\n";

try {
    // Check if payments table exists
    $check_table = $conn->query("SHOW TABLES LIKE 'payments'");
    
    if ($check_table->num_rows > 0) {
        echo "✅ PAYMENTS TABLE EXISTS\n\n";
        
        // Show table structure
        echo "TABLE STRUCTURE:\n";
        $structure = $conn->query("DESCRIBE payments");
        while ($row = $structure->fetch_assoc()) {
            echo "- {$row['Field']}: {$row['Type']} ({$row['Null']}, {$row['Key']}, Default: {$row['Default']})\n";
        }
        
        echo "\n";
        
        // Count total records
        $count = $conn->query("SELECT COUNT(*) as count FROM payments")->fetch_assoc()['count'];
        echo "TOTAL RECORDS: {$count}\n\n";
        
        // Show sample records if any
        if ($count > 0) {
            echo "SAMPLE RECORDS (first 5):\n";
            $sample = $conn->query("SELECT * FROM payments LIMIT 5");
            while ($row = $sample->fetch_assoc()) {
                echo "ID: {$row['id']}, Member: " . ($row['member_id'] ?? 'NULL') . ", Amount: " . ($row['amount'] ?? 'NULL') . ", Status: " . ($row['status'] ?? 'NULL') . "\n";
            }
            echo "\n";
            
            // Test the exact query used in AJAX
            echo "TESTING AJAX QUERY (member_id = 1):\n";
            $test_query = "SELECT SUM(amount) as total FROM payments WHERE member_id = 1 AND status = 'completed'";
            echo "Query: {$test_query}\n";
            $test_result = $conn->query($test_query);
            $test_row = $test_result->fetch_assoc();
            echo "Result: " . ($test_row['total'] ?? 'NULL') . "\n\n";
            
            // Check different status values
            echo "STATUS VALUES IN PAYMENTS TABLE:\n";
            $status_check = $conn->query("SELECT DISTINCT status, COUNT(*) as count FROM payments GROUP BY status");
            while ($row = $status_check->fetch_assoc()) {
                echo "- Status: '{$row['status']}' (Count: {$row['count']})\n";
            }
            echo "\n";
            
            // Check member_id distribution
            echo "MEMBER_ID DISTRIBUTION (top 10):\n";
            $member_check = $conn->query("SELECT member_id, COUNT(*) as count, SUM(amount) as total FROM payments GROUP BY member_id ORDER BY total DESC LIMIT 10");
            while ($row = $member_check->fetch_assoc()) {
                echo "- Member ID: {$row['member_id']}, Records: {$row['count']}, Total: {$row['total']}\n";
            }
        } else {
            echo "❌ NO PAYMENT RECORDS FOUND\n";
        }
        
    } else {
        echo "❌ PAYMENTS TABLE DOES NOT EXIST\n\n";
        echo "Available tables:\n";
        $tables = $conn->query("SHOW TABLES");
        while ($row = $tables->fetch_array()) {
            echo "- {$row[0]}\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
?>
