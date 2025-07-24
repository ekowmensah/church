<?php
require_once __DIR__.'/../config/config.php';
// 1. Clear all role_permissions
$conn->query('DELETE FROM role_permissions');
// 2. Reseed role_permissions only (not roles)
require __DIR__.'/seed_roles_permissions.php';
echo "role_permissions table cleared and reseeded based on current roles.\n";
