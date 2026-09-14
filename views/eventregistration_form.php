<?php
// Direct legacy registration editing is disabled; use the audited scoped queue.
require_once __DIR__ . '/../config/config.php';
$eventId = max(0, (int) ($_GET['event_id'] ?? 0));
$target = $eventId > 0
    ? '/views/event_registration_view.php?event_id=' . $eventId
    : '/views/event_registration_list.php';
header('Location: ' . BASE_URL . $target, true, 302);
exit;
