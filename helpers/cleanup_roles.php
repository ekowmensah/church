<?php
// Retired: roles are immutable catalog records and may only be deactivated.
if (PHP_SAPI !== 'cli') http_response_code(404);
$stream = PHP_SAPI === 'cli' ? STDERR : fopen('php://output', 'wb');
fwrite($stream, "This destructive role cleanup is retired. Use governed role administration.\n");
exit(1);
