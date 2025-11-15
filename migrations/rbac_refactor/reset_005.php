<?php
require_once __DIR__ . '/../../config/config.php';
$conn->query("DROP TABLE IF EXISTS permission_audit_log_enhanced");
$conn->query("UPDATE rbac_migrations SET status = 'rolled_back' WHERE migration_number = '005'");
echo "Migration 005 reset complete\n";
