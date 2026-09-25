<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../services/AttendanceScheduleService.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$service = new AttendanceScheduleService($conn);
$result = $conn->query(
    "SELECT id FROM attendance_schedules
     WHERE status = 'active' AND start_date <= end_date
     ORDER BY id"
);
$created = 0;
$processed = 0;
while ($row = $result->fetch_assoc()) {
    $created += $service->generate((int) $row['id']);
    $processed++;
}

echo "Processed {$processed} attendance schedule(s); created {$created} new session(s).\n";
