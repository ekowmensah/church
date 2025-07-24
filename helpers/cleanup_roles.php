<?php
require_once __DIR__.'/../config/config.php';
$conn->query('DELETE FROM role_permissions');
$conn->query('DELETE FROM roles');
$conn->query('ALTER TABLE roles AUTO_INCREMENT = 1');
echo "roles and role_permissions tables truncated, roles auto_increment reset.\n";
