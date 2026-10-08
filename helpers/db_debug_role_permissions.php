<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
$stmt = $conn->prepare(
    "SELECT permission.name
       FROM roles role
       JOIN role_permissions grant_row ON grant_row.role_id = role.id
       JOIN permissions permission ON permission.id = grant_row.permission_id
      WHERE LOWER(TRIM(role.name)) = 'super admin'
        AND role.is_active = 1 AND grant_row.is_active = 1
        AND permission.is_active = 1
        AND (grant_row.expires_at IS NULL OR grant_row.expires_at > NOW())
      ORDER BY permission.name"
);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    echo $row['name'] . PHP_EOL;
}
