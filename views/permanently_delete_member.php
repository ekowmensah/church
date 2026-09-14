<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Hard deletion previously disabled foreign keys and erased payments,
// attendance, feedback, and identity history. Phase 0016 intentionally blocks
// that path; archive/restore provides the recoverable lifecycle operation.
header('Location: deleted_members_list.php?error=' . urlencode(
    'Permanent deletion is disabled. Archive members to preserve financial and attendance audit history.'
));
exit;
