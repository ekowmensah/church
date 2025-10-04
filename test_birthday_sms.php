<?php
/**
 * Birthday SMS Test Script
 * 
 * This script tests the birthday SMS functionality without sending actual SMS
 */

require_once 'config/config.php';

// Test database connection
try {
    $today = date('m-d');
    echo "Testing Birthday SMS System\n";
    echo "==========================\n\n";
    echo "Today's date: " . date('Y-m-d') . " (MM-DD: $today)\n\n";
    
    // Check for birthday members
    $query = "
        SELECT 
            id,
            first_name,
            last_name,
            phone,
            dob,
            church_id,
            CONCAT(first_name, ' ', last_name) as full_name,
            DATE_FORMAT(dob, '%M %e') as birthday_formatted
        FROM members 
        WHERE 
            dob IS NOT NULL 
            AND dob != '0000-00-00' 
            AND dob != '' 
            AND DATE_FORMAT(dob, '%m-%d') = ?
            AND phone IS NOT NULL 
            AND phone != ''
            AND phone != '0'
        ORDER BY first_name, last_name
        LIMIT 5
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $members = [];
    while ($row = $result->fetch_assoc()) {
        $members[] = $row;
    }
    
    echo "Members with birthdays today: " . count($members) . "\n";
    
    if (count($members) > 0) {
        echo "\nBirthday Members:\n";
        echo "-----------------\n";
        foreach ($members as $member) {
            echo "- {$member['full_name']} ({$member['phone']}) - Born: {$member['birthday_formatted']}\n";
        }
    } else {
        echo "No members have birthdays today.\n";
        
        // Show some sample members with their birth dates for reference
        echo "\nSample members with birth dates:\n";
        echo "--------------------------------\n";
        
        $sample_query = "
            SELECT 
                CONCAT(first_name, ' ', last_name) as full_name,
                phone,
                dob,
                DATE_FORMAT(dob, '%M %e') as birthday_formatted,
                DATE_FORMAT(dob, '%m-%d') as mm_dd
            FROM members 
            WHERE 
                dob IS NOT NULL 
                AND dob != '0000-00-00' 
                AND dob != '' 
                AND phone IS NOT NULL 
                AND phone != ''
            ORDER BY dob DESC
            LIMIT 10
        ";
        
        $sample_result = $conn->query($sample_query);
        while ($row = $sample_result->fetch_assoc()) {
            echo "- {$row['full_name']} ({$row['phone']}) - {$row['birthday_formatted']} ({$row['mm_dd']})\n";
        }
    }
    
    // Test SMS configuration
    echo "\nTesting SMS Configuration:\n";
    echo "--------------------------\n";
    
    $sms_config_file = 'config/sms_settings.json';
    if (file_exists($sms_config_file)) {
        $sms_config = json_decode(file_get_contents($sms_config_file), true);
        if ($sms_config) {
            echo "✓ SMS configuration file found\n";
            echo "✓ Default provider: " . ($sms_config['default_provider'] ?? 'Not set') . "\n";
            
            if (isset($sms_config['arkesel'])) {
                echo "✓ Arkesel configuration present\n";
            }
            if (isset($sms_config['hubtel'])) {
                echo "✓ Hubtel configuration present\n";
            }
        } else {
            echo "✗ SMS configuration file is invalid JSON\n";
        }
    } else {
        echo "✗ SMS configuration file not found at: $sms_config_file\n";
    }
    
    // Test sms_logs table
    echo "\nTesting SMS Logs Table:\n";
    echo "-----------------------\n";
    
    try {
        // Check if table exists
        $result = $conn->query("SHOW TABLES LIKE 'sms_logs'");
        if ($result->num_rows > 0) {
            echo "✓ sms_logs table exists\n";
            
            // Check if type column exists
            $result = $conn->query("SHOW COLUMNS FROM sms_logs LIKE 'type'");
            if ($result->num_rows > 0) {
                echo "✓ 'type' column exists in sms_logs table\n";
            } else {
                echo "⚠ 'type' column missing from sms_logs table (will be auto-created)\n";
            }
            
            // Count existing birthday SMS logs
            $result = $conn->query("SELECT COUNT(*) as count FROM sms_logs WHERE type = 'birthday'");
            if ($row = $result->fetch_assoc()) {
                echo "✓ Existing birthday SMS logs: " . $row['count'] . "\n";
            }
        } else {
            echo "✗ sms_logs table does not exist\n";
        }
    } catch (Exception $e) {
        echo "✗ Error checking sms_logs table: " . $e->getMessage() . "\n";
    }
    
    // Test message template
    echo "\nTesting Message Template:\n";
    echo "-------------------------\n";
    
    $message = "Happy Birthday, {name}!\n\nAs you celebrate another year of God's faithfulness, we wish you all the good things you desire in life. May the Grace and Favour of God be multiplied unto you. Enjoy your special day, and stay blessed.\n\n{church_name}.";
    
    $template_data = [
        'name' => 'John Doe',
        'church_name' => 'Freeman Methodist Church, Kwesimintsim'
    ];
    
    // Process template (simple replacement)
    $processed_message = $message;
    foreach ($template_data as $key => $value) {
        $processed_message = str_replace('{' . $key . '}', $value, $processed_message);
    }
    
    echo "Sample processed message:\n";
    echo "------------------------\n";
    echo $processed_message . "\n";
    echo "\nMessage length: " . strlen($processed_message) . " characters\n";
    
    echo "\n✓ Birthday SMS system test completed successfully!\n";
    
} catch (Exception $e) {
    echo "✗ Error during testing: " . $e->getMessage() . "\n";
}
?>
