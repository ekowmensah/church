<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/member_auth.php';
if (!isset($_SESSION['member_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
// Fetch only upcoming events (today or future)
$today = date('Y-m-d');
$sql = "SELECT e.*, et.name AS type_name FROM events e LEFT JOIN event_types et ON e.event_type_id = et.id WHERE e.event_date >= ? ORDER BY e.event_date ASC, e.event_time ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $today);
$stmt->execute();
$events = $stmt->get_result();
// Prepare events for FullCalendar
$calendar_events = [];
if ($events && $events->num_rows > 0) {
    foreach ($events as $e) {
        $calendar_events[] = [
            'title' => $e['name'],
            'start' => $e['event_date'] . ($e['event_time'] ? 'T' . $e['event_time'] : ''),
            'description' => $e['description'],
            'location' => $e['location'],
            'id' => $e['id'],
        ];
    }
}
// Reset pointer for table
$stmt->execute();
$events = $stmt->get_result();
ob_start();
?>
<div class="container mt-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><i class="fas fa-calendar-alt mr-2"></i>Upcoming Events</h2>
    <a href="member_registered_events.php" class="btn btn-sm btn-outline-primary ml-3"><i class="fas fa-list mr-1"></i>My Events</a>
  </div>
  <?php include __DIR__.'/partials/event_calendar.php'; ?>
</div>

<?php
$page_content = ob_get_clean();
include '../includes/layout.php';

