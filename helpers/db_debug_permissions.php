<?php
// Retired: this browser diagnostic trusted stale session role identifiers and
// exposed authorization internals. Use the protected RBAC audit workspace.
if (PHP_SAPI !== 'cli') http_response_code(404);
$stream = PHP_SAPI === 'cli' ? STDERR : fopen('php://output', 'wb');
fwrite($stream, "This legacy permission diagnostic is retired.\n");
exit(1);
