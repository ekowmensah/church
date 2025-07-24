<?php
require_once __DIR__.'/../../config/config.php';
$events = $conn->query("SELECT id, name, event_date, event_time, location, description FROM events ORDER BY event_date, event_time");
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
