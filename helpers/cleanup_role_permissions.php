<?php
require_once __DIR__.'/../config/config.php';
$conn->query('DELETE FROM role_permissions');
echo "role_permissions table truncated.\n";
