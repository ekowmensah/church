<?php
$conn = require_once __DIR__ . '/config/database.php';

header('Content-Type: text/plain');

echo "Checking member_hikvision_data table structure...\n\n";

// Show table structure
echo "=== TABLE STRUCTURE ===\n";
$result = $conn->query("DESCRIBE member_hikvision_data");
while ($row = $result->fetch_assoc()) {
    echo sprintf("%-20s %-15s %-10s %-10s %-15s %s\n", 
        $row['Field'], 
        $row['Type'], 
        $row['Null'], 
        $row['Key'], 
        $row['Default'], 
        $row['Extra']
    );
}

echo "\n=== FOREIGN KEY CONSTRAINTS ===\n";
$result = $conn->query("
    SELECT 
        CONSTRAINT_NAME,
        COLUMN_NAME,
        REFERENCED_TABLE_NAME,
        REFERENCED_COLUMN_NAME
    FROM information_schema.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = 'myfreemangit' 
    AND TABLE_NAME = 'member_hikvision_data' 
    AND REFERENCED_TABLE_NAME IS NOT NULL
");

while ($row = $result->fetch_assoc()) {
    echo sprintf("%-30s %-15s -> %-20s %s\n",
        $row['CONSTRAINT_NAME'],
        $row['COLUMN_NAME'],
        $row['REFERENCED_TABLE_NAME'],
        $row['REFERENCED_COLUMN_NAME']
    );
}

echo "\n=== CURRENT DATA ===\n";
$result = $conn->query("SELECT * FROM member_hikvision_data LIMIT 5");
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        print_r($row);
    }
} else {
    echo "No data in table\n";
}

$conn->close();
?>
