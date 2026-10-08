<?php
// Retired: bulk grant replacement bypassed provenance, expiry and audit.
if (PHP_SAPI !== 'cli') http_response_code(404);
$stream = PHP_SAPI === 'cli' ? STDERR : fopen('php://output', 'wb');
fwrite($stream, "This destructive role grant seeder is retired. Apply a versioned migration.\n");
exit(1);
