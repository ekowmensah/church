<?php
/**
 * Legacy permission-helper compatibility entry point.
 *
 * Older pages still require this filename, while the shared header and newer
 * pages require permissions_v2.php. Loading both historical implementations
 * declared has_permission() twice and caused a fatal error. Keep one canonical
 * RBAC implementation and let every legacy include resolve to it.
 */
require_once __DIR__ . '/permissions_v2.php';
