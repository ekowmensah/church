<?php
$conn = require_once __DIR__ . '/config/database.php';

header('Content-Type: text/plain');

echo "Applying HikVision database fixes...\n";

try {
    // Drop the foreign key constraint
    echo "1. Dropping foreign key constraint...\n";
    $conn->query("ALTER TABLE `member_hikvision_data` DROP FOREIGN KEY `member_hikvision_data_ibfk_1`");
    echo "✓ Foreign key constraint dropped\n";

    // Modify the member_id column to allow NULL
    echo "2. Modifying member_id column to allow NULL...\n";
    $conn->query("ALTER TABLE `member_hikvision_data` MODIFY COLUMN `member_id` int(11) DEFAULT NULL");
    echo "✓ Column modified to allow NULL\n";

    // Re-add the foreign key constraint with NULL allowed
    echo "3. Re-adding foreign key constraint with NULL support...\n";
    $conn->query("ALTER TABLE `member_hikvision_data` ADD CONSTRAINT `member_hikvision_data_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL ON UPDATE CASCADE");
    echo "✓ Foreign key constraint re-added with NULL support\n";

    echo "\n✓ Database fixes applied successfully!\n";
    echo "You can now sync HikVision users.\n";

} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
}

$conn->close();
?>
