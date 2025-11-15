<?php
require_once __DIR__ . '/../../config/config.php';

// Mark migration 004 as rolled back so we can re-run it
$conn->query("UPDATE rbac_migrations SET status = 'rolled_back' WHERE migration_number = '004'");
echo "Migration 004 marked as rolled back\n";

// Show current status
$result = $conn->query("SELECT migration_number, migration_name, status FROM rbac_migrations ORDER BY migration_number");
echo "\nCurrent migration status:\n";
while ($row = $result->fetch_assoc()) {
    echo "  [{$row['migration_number']}] {$row['migration_name']}: {$row['status']}\n";
}
