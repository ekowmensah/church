<?php
session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/member_auth.php';

if (!isset($_SESSION['member_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$page_title = 'Church Events';
$member_id = $_SESSION['member_id'];

// Fetch upcoming events with enhanced data
$today = date('Y-m-d');
$sql = "SELECT e.*, et.name AS type_name,
               COUNT(er.id) as registration_count,
               MAX(CASE WHEN er.member_id = ? THEN 1 ELSE 0 END) as is_registered
        FROM events e 
        LEFT JOIN event_types et ON e.event_type_id = et.id 
        LEFT JOIN event_registrations er ON e.id = er.event_id
        WHERE e.event_date >= ? 
        GROUP BY e.id
        ORDER BY e.event_date ASC, e.event_time ASC";

// Define color mapping for event types
$event_type_colors = [
    'worship' => '#667eea',
    'fellowship' => '#f093fb', 
    'service' => '#4facfe',
    'meeting' => '#43e97b',
    'special' => '#fa709a',
    'mini harvest' => '#ff6b6b',
    'brigade party' => '#4ecdc4',
    'general' => '#6c757d'
];

function getEventTypeColor($type_name, $event_type_colors) {
    if (!$type_name) return '#007bff';
    $key = strtolower(trim($type_name));
    return $event_type_colors[$key] ?? '#007bff';
}

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Database error: ' . $conn->error);
}

$stmt->bind_param('is', $member_id, $today);
$stmt->execute();
$events = $stmt->get_result();

// Calculate event statistics
$total_events = 0;
$registered_events = 0;
$upcoming_this_week = 0;
$next_week = date('Y-m-d', strtotime('+7 days'));

if ($events && $events->num_rows > 0) {
    $events_array = [];
    while ($event = $events->fetch_assoc()) {
        $events_array[] = $event;
        $total_events++;
        if ($event['is_registered']) {
            $registered_events++;
        }
        if ($event['event_date'] <= $next_week) {
            $upcoming_this_week++;
        }
    }
    // Reset for display
    $events = $events_array;
} else {
    $events = [];
}

ob_start();
?>

<!-- Page Header with Gradient Background -->
<div class="content-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; margin: -20px -20px 30px -20px; padding: 40px 30px;">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h1 class="mb-2 font-weight-bold">
                    <i class="fas fa-calendar-alt mr-3"></i>
                    Church Events
                </h1>
                <p class="mb-0 opacity-75">Discover and register for upcoming church events and activities</p>
            </div>
            <div class="col-md-4 text-md-right mt-3 mt-md-0">
                <a href="member_registered_events.php" class="btn btn-light btn-lg shadow-sm">
                    <i class="fas fa-list mr-2"></i>My Registered Events
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <!-- Event Statistics Cards -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card h-100 border-0 shadow-sm" style="background: linear-gradient(135deg, #667eea, #764ba2);">
                <div class="card-body text-white text-center">
                    <div class="mb-2">
                        <i class="fas fa-calendar-check" style="font-size: 2.5rem; opacity: 0.8;"></i>
                    </div>
                    <h3 class="mb-1 font-weight-bold"><?= $total_events ?></h3>
                    <p class="mb-0 small opacity-75">Total Upcoming Events</p>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card h-100 border-0 shadow-sm" style="background: linear-gradient(135deg, #f093fb, #f5576c);">
                <div class="card-body text-white text-center">
                    <div class="mb-2">
                        <i class="fas fa-user-check" style="font-size: 2.5rem; opacity: 0.8;"></i>
                    </div>
                    <h3 class="mb-1 font-weight-bold"><?= $registered_events ?></h3>
                    <p class="mb-0 small opacity-75">My Registrations</p>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card h-100 border-0 shadow-sm" style="background: linear-gradient(135deg, #4facfe, #00f2fe);">
                <div class="card-body text-white text-center">
                    <div class="mb-2">
                        <i class="fas fa-clock" style="font-size: 2.5rem; opacity: 0.8;"></i>
                    </div>
                    <h3 class="mb-1 font-weight-bold"><?= $upcoming_this_week ?></h3>
                    <p class="mb-0 small opacity-75">This Week</p>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card h-100 border-0 shadow-sm" style="background: linear-gradient(135deg, #43e97b, #38f9d7);">
                <div class="card-body text-white text-center">
                    <div class="mb-2">
                        <i class="fas fa-calendar-day" style="font-size: 2.5rem; opacity: 0.8;"></i>
                    </div>
                    <h3 class="mb-1 font-weight-bold"><?= date('d') ?></h3>
                    <p class="mb-0 small opacity-75"><?= date('M Y') ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Calendar and Events Section -->
    <div class="row">
        <div class="col-lg-8 mb-4">
            <!-- Enhanced Calendar -->
            <?php include __DIR__.'/partials/event_calendar.php'; ?>
        </div>
        
        <div class="col-lg-4 mb-4">
            <!-- Upcoming Events List -->
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 pb-0">
                    <div class="d-flex align-items-center justify-content-between">
                        <h5 class="mb-0 font-weight-bold text-dark">
                            <i class="fas fa-list-ul mr-2 text-primary"></i>
                            Upcoming Events
                        </h5>
                        <small class="text-muted"><?= count($events) ?> events</small>
                    </div>
                </div>
                <div class="card-body pt-3" style="max-height: 500px; overflow-y: auto;">
                    <?php if (!empty($events)): ?>
                        <?php foreach (array_slice($events, 0, 10) as $event): ?>
                            <div class="event-item mb-3 p-3 border rounded" style="border-left: 4px solid <?= getEventTypeColor($event['type_name'], $event_type_colors) ?>; transition: all 0.3s ease;" 
                                 onmouseover="this.style.backgroundColor='#f8f9fa'; this.style.transform='translateX(5px)'" 
                                 onmouseout="this.style.backgroundColor=''; this.style.transform='translateX(0)'">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h6 class="mb-1 font-weight-bold text-dark"><?= htmlspecialchars($event['name']) ?></h6>
                                    <?php if ($event['is_registered']): ?>
                                        <span class="badge badge-success"><i class="fas fa-check mr-1"></i>Registered</span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted small mb-2">
                                    <div class="mb-1">
                                        <i class="fas fa-calendar mr-1"></i>
                                        <?= date('M j, Y', strtotime($event['event_date'])) ?>
                                        <?php if ($event['event_time']): ?>
                                            <i class="fas fa-clock ml-2 mr-1"></i>
                                            <?= date('g:i A', strtotime($event['event_time'])) ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($event['location']): ?>
                                        <div class="mb-1">
                                            <i class="fas fa-map-marker-alt mr-1"></i>
                                            <?= htmlspecialchars($event['location']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($event['type_name']): ?>
                                        <div class="mb-1">
                                            <i class="fas fa-tag mr-1"></i>
                                            <span class="badge badge-light" style="background-color: <?= getEventTypeColor($event['type_name'], $event_type_colors) ?>; color: white;">
                                                <?= htmlspecialchars($event['type_name']) ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($event['description']): ?>
                                    <p class="text-muted small mb-2"><?= htmlspecialchars(substr($event['description'], 0, 100)) ?><?= strlen($event['description']) > 100 ? '...' : '' ?></p>
                                <?php endif; ?>
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">
                                        <i class="fas fa-users mr-1"></i>
                                        <?= $event['registration_count'] ?> registered
                                    </small>
                                    <a href="event_register.php?event_id=<?= $event['id'] ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-eye mr-1"></i>View Details
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <?php if (count($events) > 10): ?>
                            <div class="text-center mt-3">
                                <small class="text-muted">Showing first 10 of <?= count($events) ?> events</small>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <div class="mb-3">
                                <i class="fas fa-calendar-times text-muted" style="font-size: 3rem;"></i>
                            </div>
                            <h6 class="text-muted mb-2">No Upcoming Events</h6>
                            <p class="text-muted small mb-0">Check back later for new events and activities.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.event-item:hover {
    cursor: pointer;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1) !important;
}

.card {
    transition: all 0.3s ease;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.1) !important;
}

.opacity-75 {
    opacity: 0.75;
}

@media (max-width: 768px) {
    .content-header {
        margin: -15px -15px 20px -15px !important;
        padding: 30px 20px !important;
    }
    
    .event-item {
        margin-bottom: 15px !important;
    }
}
</style>

<?php
$page_content = ob_get_clean();
include '../includes/layout.php';

