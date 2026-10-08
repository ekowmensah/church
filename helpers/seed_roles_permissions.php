<?php
// Retired: destructive RBAC seeders bypass migrations, authorization audit,
// immutable permission codes and grant provenance. This file remains only to
// fail safely for old CLI commands or browser bookmarks.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
}

$stream = PHP_SAPI === 'cli' ? STDERR : fopen('php://output', 'wb');
fwrite($stream, "This destructive RBAC seeder is retired. Apply versioned migrations instead.\n");
exit(1);
