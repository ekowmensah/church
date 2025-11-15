<?php
require_once __DIR__ . '/../../config/config.php';

echo "Checking migration 003 status...\n";
$result = $conn->query("SELECT * FROM rbac_migrations WHERE migration_number = '003'");
$migration = $result->fetch_assoc();
echo "Status: {$migration['status']}\n";
echo "Error: " . ($migration['error_message'] ?? 'None') . "\n\n";

// Mark as rolled back so we can re-run
$conn->query("UPDATE rbac_migrations SET status = 'rolled_back' WHERE migration_number = '003'");
echo "Migration 003 marked as rolled_back. Ready to re-run.\n";
