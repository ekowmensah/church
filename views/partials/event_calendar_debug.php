<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../helpers/permissions_v2.php';
if (!is_logged_in() || !is_super_admin()) {
    http_response_code(404);
    exit;
}
$events = $conn->query("SELECT id, name, event_date, event_time, location, description FROM events WHERE status = 'active' ORDER BY event_date, event_time");
echo '<pre>Events in DB:\n';
if ($events && $events->num_rows > 0) {
    while ($e = $events->fetch_assoc()) {
        var_export($e);
        echo "\n";
    }
} else {
    echo 'No events found.';
}
echo '</pre>';
