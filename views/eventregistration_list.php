<?php
// Backward-compatible route for bookmarks created before the scoped queue.
require_once __DIR__ . '/../config/config.php';
header('Location: ' . BASE_URL . '/views/event_registration_list.php', true, 302);
exit;
