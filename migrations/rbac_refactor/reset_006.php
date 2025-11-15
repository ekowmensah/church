<?php
require_once __DIR__ . '/../../config/config.php';
$conn->query("DROP TABLE IF EXISTS role_template_usage");
$conn->query("DROP TABLE IF EXISTS role_templates");
$conn->query("UPDATE rbac_migrations SET status = 'rolled_back' WHERE migration_number = '006'");
echo "Migration 006 reset complete\n";
