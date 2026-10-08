<?php
// Retired: permission identities may only change through audited migrations
// and the governed permission-administration service.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
}

$stream = PHP_SAPI === 'cli' ? STDERR : fopen('php://output', 'wb');
fwrite($stream, "This destructive permission seeder is retired. Apply versioned migrations instead.\n");
exit(1);
