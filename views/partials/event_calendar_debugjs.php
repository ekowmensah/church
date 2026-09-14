<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../helpers/permissions_v2.php';
if (!is_logged_in() || !is_super_admin()) {
    http_response_code(404);
    exit;
}
$events = $conn->query("SELECT id, name, event_date, event_time, location, description FROM events WHERE status = 'active' ORDER BY event_date, event_time");
$calendar_events = [];
if ($events && $events->num_rows > 0) {
    while ($e = $events->fetch_assoc()) {
        $calendar_events[] = [
            'title' => $e['name'],
            'start' => $e['event_date'] . 'T' . $e['event_time'],
            'description' => $e['description'],
            'location' => $e['location'],
            'id' => $e['id']
        ];
    }
}
?>
<pre>JS JSON:
<?php echo json_encode($calendar_events, JSON_PRETTY_PRINT); ?>
</pre>
<script>
console.log('FullCalendar events:', <?php echo json_encode($calendar_events); ?>);
</script>
