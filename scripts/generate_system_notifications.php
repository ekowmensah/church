<?php

// Recommended cron: every 15 minutes. All generated reminders are protected
// by deterministic dedupe keys, so overlapping invocations are safe.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../services/SystemNotificationService.php';

try {
    $service = new SystemNotificationService($conn);
    $service->queueScheduledReminders();
    $delivered = $service->dispatchPending(100);
    echo 'System notifications processed successfully; events delivered: ' . $delivered . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'System notification processing failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
