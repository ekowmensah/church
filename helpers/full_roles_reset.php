<?php
require_once __DIR__.'/../config/config.php';

// 1. Set all users' role_id to NULL
$conn->query('UPDATE users SET role_id = NULL');

// 2. Truncate role_permissions and roles
$conn->query('DELETE FROM role_permissions');
$conn->query('DELETE FROM roles');
$conn->query('ALTER TABLE roles AUTO_INCREMENT = 1');

// 3. Reseed roles and permissions
require __DIR__.'/seed_roles_permissions.php';

// 4. Update Super Admin user to have role_id = 1
$email = 'ekowme@gmail.com'; // use your Super Admin email
$conn->query("UPDATE users SET role_id = 1 WHERE email = '$email'");
echo "Roles reset, reseeded, and Super Admin role_id set to 1.\n";
