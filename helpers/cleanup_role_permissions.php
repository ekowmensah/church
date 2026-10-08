<?php
// Retired: grant revocation must retain audit evidence.
if (PHP_SAPI !== 'cli') http_response_code(404);
$stream = PHP_SAPI === 'cli' ? STDERR : fopen('php://output', 'wb');
fwrite($stream, "This destructive RBAC cleanup is retired. Use governed role administration.\n");
exit(1);
