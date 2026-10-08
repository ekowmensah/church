<?php
// Retired because it erased grant provenance and bypassed authorization audit.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
}
fwrite(PHP_SAPI === 'cli' ? STDERR : fopen('php://output', 'wb'),
    "This destructive role-permission reset is retired. Use role administration or migrations.\n");
exit(1);
