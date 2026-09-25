<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../services/DashboardInsightsService.php';

function expect_phase_0028(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$user = $conn->query('SELECT id, church_id FROM users ORDER BY id LIMIT 1')->fetch_assoc();
expect_phase_0028((bool) $user, 'A user is required for the dashboard insights smoke test.');
$userId = (int) $user['id'];
$churchId = (int) $user['church_id'];
$beforeId = (int) ($conn->query('SELECT COALESCE(MAX(id), 0) AS id FROM dashboard_insight_query_audit')->fetch_assoc()['id'] ?? 0);

$permissions = [
    'payments' => true,
    'attendance' => true,
    'health' => true,
    'membership' => true,
    'events' => true,
    'birthdays' => true,
];
$service = new DashboardInsightsService(
    $conn,
    $userId,
    true,
    false,
    $churchId ?: null,
    null,
    null,
    $permissions
);

try {
    $payment = $service->ask('How much was received this month?');
    expect_phase_0028($payment['answered'] === true, 'Payment question was not answered.');
    expect_phase_0028($payment['domain'] === 'payments' && $payment['period'] === 'this_month', 'Payment intent or period was not detected.');
    expect_phase_0028(strpos($payment['answer'], 'GHS') !== false, 'Payment answer omitted the currency value.');

    $attendance = $service->ask('What was attendance this week?');
    expect_phase_0028($attendance['answered'] === true && $attendance['domain'] === 'attendance', 'Attendance question was not answered.');

    $personalPayment = $service->ask('How much have I paid?');
    expect_phase_0028(
        $personalPayment['answered'] === true && $personalPayment['intent'] === 'payments_personal_overall',
        'Personal payment question was not interpreted using the linked member account.'
    );

    $membership = $service->ask('How many active members are there?');
    expect_phase_0028($membership['answered'] === true && $membership['intent'] === 'membership_active', 'Membership question was not answered.');

    $events = $service->ask('How many upcoming events are there?');
    expect_phase_0028($events['answered'] === true && $events['period'] === 'upcoming', 'Upcoming-events question was not answered.');

    $unknown = $service->ask('Explain the weather on Mars');
    expect_phase_0028($unknown['answered'] === false && $unknown['domain'] === 'unknown', 'Unsupported question did not fail safely.');

    $deniedService = new DashboardInsightsService(
        $conn,
        $userId,
        false,
        false,
        $churchId ?: null,
        null,
        null,
        array_fill_keys(array_keys($permissions), false)
    );
    $denied = $deniedService->ask('How much was received this month?');
    expect_phase_0028($denied['answered'] === false, 'A denied dashboard domain disclosed an answer.');
    expect_phase_0028(strpos($denied['answer'], 'do not have permission') !== false, 'Denied-domain response is unclear.');

    $auditCount = (int) ($conn->query(
        'SELECT COUNT(*) AS total FROM dashboard_insight_query_audit WHERE id > ' . $beforeId
    )->fetch_assoc()['total'] ?? 0);
    expect_phase_0028($auditCount === 7, 'Dashboard insight audit did not record each normalized request.');

    $rawColumns = (int) ($conn->query(
        "SELECT COUNT(*) AS total FROM information_schema.COLUMNS"
        . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dashboard_insight_query_audit'"
        . " AND COLUMN_NAME IN ('question','raw_question','prompt','answer','response_text')"
    )->fetch_assoc()['total'] ?? 0);
    expect_phase_0028($rawColumns === 0, 'Dashboard insight audit can retain raw questions or answers.');

    $serviceSource = file_get_contents(__DIR__ . '/../services/DashboardInsightsService.php');
    $endpointSource = file_get_contents(__DIR__ . '/../views/ajax_dashboard_insights.php');
    $dashboardSource = file_get_contents(__DIR__ . '/../views/user_dashboard.php');
    expect_phase_0028(
        strpos($serviceSource, 'curl_') === false && strpos($serviceSource, "file_get_contents('http") === false,
        'Dashboard insights unexpectedly call an external service.'
    );
    expect_phase_0028(
        strpos($endpointSource, "csrf_is_valid(\$_POST['csrf_token'] ?? null)") !== false,
        'Dashboard insight requests are missing CSRF validation.'
    );
    expect_phase_0028(
        strpos($endpointSource, '$recentQueries >= 20') !== false,
        'Dashboard insight requests are missing rate limiting.'
    );
    expect_phase_0028(
        strpos($dashboardSource, "BASE_URL . '/views/ajax_dashboard_insights.php'") !== false,
        'Dashboard insight requests do not use the application-root endpoint path.'
    );
    expect_phase_0028(
        strpos($dashboardSource, 'JSON.parse(responseText)') !== false,
        'Dashboard insight responses are not guarded against HTML error pages.'
    );
} finally {
    $conn->query('DELETE FROM dashboard_insight_query_audit WHERE id > ' . $beforeId . ' AND user_id = ' . $userId);
}

echo "PASS: Phase 0028 local permission-scoped dashboard insights are operational.\n";
