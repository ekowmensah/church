<?php
// Show upcoming events as a calendar/list for member dashboard
require_once __DIR__.'/../../config/config.php';
$today = date('Y-m-d');
$events = [];
$sql = "SELECT id, name, event_date, event_time, location, photo FROM events WHERE event_date >= ? ORDER BY event_date ASC LIMIT 10";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $today);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $events[] = $row;
?>
<div class="card shadow mb-4">
  <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
    <h6 class="m-0 font-weight-bold text-primary">Upcoming Events</h6>
  </div>
  <div class="card-body p-2">
    <?php if (count($events)): ?>
      <ul class="list-group list-group-flush">
        <?php foreach ($events as $e): ?>
          <li class="list-group-item d-flex align-items-center justify-content-between">
            <div>
              <span class="font-weight-bold text-dark"><?= htmlspecialchars($e['name']) ?></span>
              <small class="text-muted ml-2"><i class="far fa-calendar-alt"></i> <?= htmlspecialchars($e['event_date']) ?> <?= htmlspecialchars($e['event_time']) ?></small>
              <br><span class="text-muted small"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($e['location']) ?></span>
            </div>
            <?php if (!empty($e['photo']) && file_exists(__DIR__.'/../../uploads/events/' . $e['photo'])): ?>
              <img src="<?= BASE_URL . '/uploads/events/' . rawurlencode($e['photo']) ?>" alt="Event Photo" style="height:40px;width:40px;object-fit:cover;border-radius:8px;margin-left:10px;">
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/views/event_register.php?event_id=<?= $e['id'] ?>" class="btn btn-sm btn-outline-success ml-2">View</a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="text-center text-muted">No upcoming events found.</div>
    <?php endif; ?>
  </div>
</div>
