<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../services/BibleClassAttendanceScheduleService.php';

$date = date('Y-m-d');
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--date=')) {
        $date = substr($argument, 7);
    }
}

try {
    $service = new BibleClassAttendanceScheduleService($conn);
    $result = $service->ensureForDate($date, 'cron');
    echo sprintf(
        "%s: %d generated, %d already available.\n",
        $result['date'], $result['generated'], $result['existing']
    );
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Generation failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
