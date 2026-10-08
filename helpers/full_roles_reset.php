<?php
// Retired: destructive role resets must be implemented as reviewed, tracked
// CLI migrations. This historical browser-accessible helper performs no work.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
fwrite(STDERR, "This unsafe legacy reset has been retired. Use the migration runner.\n");
exit(1);
