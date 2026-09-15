<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/role_based_filter.php';
require_once __DIR__ . '/../services/BirthdayDirectoryService.php';
require_once __DIR__ . '/../services/DashboardPaymentSummaryService.php';
require_once __DIR__ . '/../services/DashboardPeriodSummaryService.php';

global $conn;
if (!isset($conn) && isset($GLOBALS['conn'])) {
    $conn = $GLOBALS['conn'];
}

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

function dashboard_redirect($path)
{
    header('Location: ' . BASE_URL . '/views/' . ltrim($path, '/'));
    exit;
}

function dashboard_scalar($conn, $sql, $field = 'total', $default = 0)
{
    $result = $conn->query($sql);
    if ($result && ($row = $result->fetch_assoc())) {
        return isset($row[$field]) ? $row[$field] : $default;
    }

    return $default;
}

function dashboard_rows($conn, $sql)
{
    $rows = [];
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function dashboard_month_buckets($months = 6)
{
    $buckets = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $stamp = strtotime("-{$i} months");
        $bucket = date('Y-m', $stamp);
        $buckets[$bucket] = [
            'label' => date('M Y', $stamp),
            'value' => 0,
        ];
    }

    return $buckets;
}

function dashboard_merge_month_values($seed, $rows, $bucketKey, $valueKey)
{
    foreach ($rows as $row) {
        $bucket = $row[$bucketKey] ?? null;
        if ($bucket !== null && isset($seed[$bucket])) {
            $seed[$bucket]['value'] = (float) ($row[$valueKey] ?? 0);
        }
    }

    return $seed;
}

function dashboard_percent($numerator, $denominator, $precision = 1)
{
    if ((float) $denominator <= 0) {
        return 0;
    }

    return round(((float) $numerator / (float) $denominator) * 100, $precision);
}

function dashboard_currency($amount)
{
    return 'GHS ' . number_format((float) $amount, 2);
}

function dashboard_status_tone($status)
{
    $value = strtolower((string) $status);
    if ($value === 'active') {
        return 'success';
    }
    if ($value === 'pending') {
        return 'warning';
    }
    if ($value === 'inactive') {
        return 'secondary';
    }

    return 'light';
}

$class_ids = get_user_class_ids();
$org_ids = get_user_organization_ids();
$force_main = isset($_GET['force_main']);
$is_super_admin = function_exists('is_super_admin') ? is_super_admin() : (
    ((int) ($_SESSION['user_id'] ?? 0) === 3) || ((int) ($_SESSION['role_id'] ?? 0) === 1)
);

if (!$force_main && !$is_super_admin) {
    if ($org_ids !== null) {
        dashboard_redirect('my_organization_leader.php');
    }

    if ($class_ids !== null) {
        dashboard_redirect('my_bible_class_leader.php');
    }
}

if (!$is_super_admin && !has_permission('view_dashboard')) {
    http_response_code(403);
    $error_page = __DIR__ . '/errors/403.php';
    if (file_exists($error_page)) {
        include $error_page;
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    }
    exit;
}

$current_user_id = (int) ($_SESSION['user_id'] ?? 0);
$current_user_church_id = (int) dashboard_scalar(
    $conn,
    "SELECT church_id AS value FROM users WHERE id = {$current_user_id} LIMIT 1",
    'value',
    0
);
$deny_unscoped = !$is_super_admin && $current_user_church_id < 1;
$member_scope_no_alias = $is_super_admin ? '' : (
    $deny_unscoped ? ' AND 1 = 0' : " AND church_id = {$current_user_church_id}"
);
$member_scope_alias = $is_super_admin ? '' : (
    $deny_unscoped ? ' AND 1 = 0' : " AND m.church_id = {$current_user_church_id}"
);
$attendance_scope_no_alias = $is_super_admin ? '' : (
    $deny_unscoped ? ' AND 1 = 0' : " AND church_id = {$current_user_church_id}"
);
$attendance_scope_alias = $is_super_admin ? '' : (
    $deny_unscoped ? ' AND 1 = 0' : " AND s.church_id = {$current_user_church_id}"
);
$display_name = trim((string) ($_SESSION['user_name'] ?? ($_SESSION['name'] ?? '')));
if ($display_name === '') {
    $display_name = 'User';
}
$current_hour = (int) date('G');
$time_greeting = $current_hour < 12 ? 'Good morning' : ($current_hour < 17 ? 'Good afternoon' : 'Good evening');

$role_rows = function_exists('get_user_roles') ? get_user_roles() : [];
$role_names = [];
foreach ($role_rows as $role_row) {
    if (!empty($role_row['name'])) {
        $role_names[] = $role_row['name'];
    }
}

if (empty($role_names) && !empty($_SESSION['role_id'])) {
    $fallback_role_id = (int) $_SESSION['role_id'];
    $fallback_role_name = dashboard_scalar(
        $conn,
        "SELECT name FROM roles WHERE id = {$fallback_role_id} LIMIT 1",
        'name',
        'User'
    );
    $role_names[] = $fallback_role_name;
}

$is_cashier = false;
foreach ($role_names as $role_name) {
    if (strtolower((string) $role_name) === 'cashier') {
        $is_cashier = true;
        break;
    }
}

$can_view_membership_dashboard = $is_super_admin || has_permission('view_dashboard_membership_summary');
$can_view_payment_dashboard = $is_super_admin || has_permission('view_dashboard_payment_summary');
$can_view_attendance_dashboard = $is_super_admin || has_permission('view_dashboard_attendance_summary');
$can_view_health_dashboard = $is_super_admin || has_permission('view_dashboard_health_summary');
$can_view_event_dashboard = $is_super_admin || has_permission('view_dashboard_event_summary');
$has_operational_dashboard_data = $can_view_membership_dashboard
    || $can_view_payment_dashboard
    || $can_view_attendance_dashboard
    || $can_view_health_dashboard
    || $can_view_event_dashboard;

$can_view_birthdays = $is_super_admin || has_permission('view_birthdays');
$birthday_summary = ['yesterday' => 0, 'today' => 0, 'tomorrow' => 0, 'month' => 0];
$today_birthdays = [];
if ($can_view_birthdays) {
    $birthday_service = BirthdayDirectoryService::fromSession($conn);
    $birthday_summary = $birthday_service->getSummary();
    $today_birthdays = $birthday_service->getMembers('today', null, 5);
}

$reversal_filter = "(reversal_approved_at IS NULL OR reversal_undone_at IS NOT NULL)";
$successful_payment_filter = "(status IS NULL OR LOWER(TRIM(status)) IN ('completed', 'paid', 'success', 'successful', 'approved'))";
$successful_payment_filter_alias = "(p.status IS NULL OR LOWER(TRIM(p.status)) IN ('completed', 'paid', 'success', 'successful', 'approved'))";
$cashier_filter = $is_cashier && $current_user_id > 0 ? " AND p.recorded_by = {$current_user_id}" : '';
$cashier_filter_no_alias = $is_cashier && $current_user_id > 0 ? " AND recorded_by = {$current_user_id}" : '';
$payment_scope_no_alias = $is_cashier && $current_user_id > 0
    ? $cashier_filter_no_alias
    : ($is_super_admin ? '' : ($deny_unscoped ? ' AND 1 = 0' : " AND church_id = {$current_user_church_id}"));
$payment_scope_alias = $is_cashier && $current_user_id > 0
    ? $cashier_filter
    : ($is_super_admin ? '' : ($deny_unscoped ? ' AND 1 = 0' : " AND p.church_id = {$current_user_church_id}"));
$health_scope = $is_super_admin ? '' : ($deny_unscoped
    ? ' AND 1 = 0'
    : " AND (
        EXISTS (SELECT 1 FROM members scoped_member
                 WHERE scoped_member.id = health_records.member_id
                   AND scoped_member.church_id = {$current_user_church_id})
        OR EXISTS (SELECT 1 FROM sunday_school scoped_child
                   WHERE scoped_child.id = health_records.sundayschool_id
                     AND scoped_child.church_id = {$current_user_church_id})
      )");
$event_scope_no_alias = $is_super_admin
    ? ''
    : ($deny_unscoped ? ' AND 1 = 0' : " AND church_id = {$current_user_church_id}");

$dashboard_period_summary = (new DashboardPeriodSummaryService(
    $conn,
    $current_user_id,
    $is_super_admin,
    $is_cashier,
    $current_user_church_id ?: null,
    $class_ids,
    $org_ids
))->summarize(
    $can_view_payment_dashboard,
    $can_view_attendance_dashboard,
    $can_view_health_dashboard
);

$active_members = $pending_members = $adherents = $junior_members = 0;
$full_members = $catechumens = $distant_members = $invalid_members = 0;
$unclassified_members = $status_review_members = $members_without_payments = 0;
if ($can_view_membership_dashboard) {
    $active_members = (int) dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM members WHERE status = 'active'{$member_scope_no_alias}");
    $pending_members = (int) dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM members WHERE status = 'pending'{$member_scope_no_alias}");
    $adherents = (int) dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM members WHERE membership_status = 'Adherent' AND status = 'active'{$member_scope_no_alias}");
    $junior_members = (int) dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM sunday_school WHERE transferred_to_member_id IS NULL{$member_scope_no_alias}")
        + (int) dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM members WHERE membership_status = 'Junior Member' AND status = 'active'{$member_scope_no_alias}");
    $full_members = (int) dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM members WHERE membership_status = 'Full Member' AND LOWER(COALESCE(baptized, '')) = 'yes' AND LOWER(COALESCE(confirmed, '')) = 'yes' AND status = 'active'{$member_scope_no_alias}");
    $catechumens = (int) dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM members WHERE membership_status = 'Catechumen' AND LOWER(COALESCE(baptized, '')) = 'yes' AND LOWER(COALESCE(confirmed, '')) <> 'yes' AND status = 'active'{$member_scope_no_alias}");
    $distant_members = (int) dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM members WHERE membership_status = 'Distant Member' AND status = 'active'{$member_scope_no_alias}");
    $invalid_members = (int) dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM members WHERE membership_status = 'Invalid' AND status = 'active'{$member_scope_no_alias}");
    $unclassified_members = (int) dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM members WHERE membership_status IS NULL AND status = 'active'{$member_scope_no_alias}");
    $status_review_members = (int) dashboard_scalar(
        $conn,
        "SELECT COUNT(DISTINCT m.id) AS total
           FROM membership_status_integrity_review review
           JOIN members m ON m.id = review.member_id
          WHERE review.resolved = 0 AND m.status = 'active'{$member_scope_alias}"
    );
    $members_without_payments = (int) dashboard_scalar(
        $conn,
        "SELECT COUNT(*) AS total
         FROM members m
         LEFT JOIN v_posted_payments p
           ON p.member_id = m.id
           AND {$reversal_filter}
           AND {$successful_payment_filter_alias}
         WHERE m.status = 'active' AND p.id IS NULL{$member_scope_alias}"
    );
}

$community_size = $full_members + $catechumens + $adherents + $junior_members;
$classified_members = $community_size + $distant_members + $invalid_members;

$payment_total = $payments_today = $payments_this_week = $payments_this_month = 0.0;
$payment_count = 0;
if ($can_view_payment_dashboard) {
    $payment_total = (float) ($dashboard_period_summary['payments']['overall'] ?? 0);
    $payment_count = (int) dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM v_posted_payments WHERE 1=1{$payment_scope_no_alias}");
    $payments_today = (float) ($dashboard_period_summary['payments']['today'] ?? 0);
    $payments_this_week = (float) ($dashboard_period_summary['payments']['this_week'] ?? 0);
    $payments_this_month = (float) ($dashboard_period_summary['payments']['this_month'] ?? 0);
}
$average_payment = $payment_count > 0 ? ($payment_total / $payment_count) : 0;

$current_year = (int) date('Y');
$current_year_payment_types = [];
if ($can_view_payment_dashboard) {
    $current_year_payment_types = (new DashboardPaymentSummaryService(
        $conn,
        $current_user_id,
        $is_super_admin,
        $is_cashier
    ))->getCurrentYearByPaymentType($current_year);
}

$attendance_sessions = 0;
$latest_session = [];
$latest_attendance_rate = 0;
$latest_attendance_title = 'No attendance session yet';
if ($can_view_attendance_dashboard) {
    $attendance_sessions = (int) dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM attendance_sessions WHERE 1 = 1{$attendance_scope_no_alias}");
    $latest_session = dashboard_rows(
        $conn,
        "SELECT id, service_date, COALESCE(NULLIF(title, ''), CONCAT('Session ', id)) AS title
         FROM attendance_sessions
         WHERE 1 = 1{$attendance_scope_no_alias}
         ORDER BY service_date DESC, id DESC
         LIMIT 1"
    );
    if (!empty($latest_session)) {
        $latest_session_id = (int) $latest_session[0]['id'];
        $latest_attendance_title = $latest_session[0]['title'];
        $latest_attendance = dashboard_rows(
            $conn,
            "SELECT
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) AS present_count,
                COUNT(*) AS total_count
             FROM attendance_records
             WHERE session_id = {$latest_session_id}"
        );
        if (!empty($latest_attendance)) {
            $latest_attendance_rate = dashboard_percent(
                (int) ($latest_attendance[0]['present_count'] ?? 0),
                (int) ($latest_attendance[0]['total_count'] ?? 0)
            );
        }
    }
}

$health_records = $health_this_month = 0;
if ($can_view_health_dashboard) {
    $health_records = (int) ($dashboard_period_summary['health']['overall'] ?? 0);
    $health_this_month = (int) ($dashboard_period_summary['health']['this_month'] ?? 0);
}

$upcoming_events = $events_this_month = 0;
if ($can_view_event_dashboard) {
    $upcoming_events = (int) dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM events WHERE status = 'active' AND event_date >= CURDATE(){$event_scope_no_alias}");
    $events_this_month = (int) dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM events WHERE status = 'active' AND YEAR(event_date) = YEAR(CURDATE()) AND MONTH(event_date) = MONTH(CURDATE()){$event_scope_no_alias}");
}

$membership_mix = [
    ['label' => 'Full Members', 'value' => $full_members],
    ['label' => 'Catechumens', 'value' => $catechumens],
    ['label' => 'Adherents', 'value' => $adherents],
    ['label' => 'Junior Members', 'value' => $junior_members],
    ['label' => 'Distant Members', 'value' => $distant_members],
    ['label' => 'Invalid Members', 'value' => $invalid_members],
];

$payment_modes = [];
if ($can_view_payment_dashboard) {
    $payment_modes = dashboard_rows(
        $conn,
        "SELECT
            COALESCE(NULLIF(mode, ''), 'Unspecified') AS label,
            COUNT(*) AS entry_count,
            COALESCE(SUM(amount), 0) AS total_amount
         FROM v_posted_payments
         WHERE {$reversal_filter} AND {$successful_payment_filter}{$payment_scope_no_alias}
         GROUP BY COALESCE(NULLIF(mode, ''), 'Unspecified')
         ORDER BY total_amount DESC"
    );
}
$top_payment_types = $current_year_payment_types;

$recent_members = [];
if ($can_view_membership_dashboard) {
    $recent_members = dashboard_rows(
        $conn,
        "SELECT
            m.id,
            CONCAT_WS(' ', m.last_name, m.first_name, m.middle_name) AS member_name,
            COALESCE(NULLIF(bc.name, ''), 'Unassigned') AS class_name,
            COALESCE(NULLIF(m.status, ''), 'unknown') AS status,
            m.created_at
         FROM members m
         LEFT JOIN bible_classes bc ON bc.id = m.class_id
         WHERE 1 = 1{$member_scope_alias}
         ORDER BY m.created_at DESC, m.id DESC
         LIMIT 6"
    );
}

$recent_payments = [];
if ($can_view_payment_dashboard) {
    $recent_payments = dashboard_rows(
        $conn,
        "SELECT
            p.id,
            p.amount,
            p.payment_date,
            COALESCE(NULLIF(pt.name, ''), 'Payment') AS payment_type,
            COALESCE(
                NULLIF(CONCAT_WS(' ', m.last_name, m.first_name), ''),
                NULLIF(CONCAT_WS(' ', ss.last_name, ss.first_name), ''),
                'Unknown payer'
            ) AS payer_name
         FROM v_posted_payments p
         LEFT JOIN members m ON m.id = p.member_id
         LEFT JOIN sunday_school ss ON ss.id = p.sundayschool_id
         LEFT JOIN payment_types pt ON pt.id = p.payment_type_id
         WHERE {$reversal_filter} AND {$successful_payment_filter_alias}{$payment_scope_alias}
         ORDER BY p.payment_date DESC, p.id DESC
         LIMIT 6"
    );
}

$recent_events = [];
if ($can_view_event_dashboard) {
    $recent_events = dashboard_rows(
        $conn,
        "SELECT id, name, event_date, location
         FROM events
         WHERE status = 'active' AND event_date >= CURDATE(){$event_scope_no_alias}
         ORDER BY event_date ASC, id ASC
         LIMIT 5"
    );
}

$attendance_trend_rows = [];
if ($can_view_attendance_dashboard) {
    $attendance_trend_rows = dashboard_rows(
        $conn,
        "SELECT
            s.id,
            s.service_date,
            COALESCE(NULLIF(s.title, ''), CONCAT('Session ', s.id)) AS title,
            SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) AS present_count,
            COUNT(ar.id) AS total_count
         FROM attendance_sessions s
         LEFT JOIN attendance_records ar ON ar.session_id = s.id
         WHERE 1 = 1{$attendance_scope_alias}
         GROUP BY s.id, s.service_date, s.title
         ORDER BY s.service_date DESC, s.id DESC
         LIMIT 6"
    );
}
$attendance_trend_rows = array_reverse($attendance_trend_rows);

$month_seed = dashboard_month_buckets(6);
$member_growth_rows = $can_view_membership_dashboard ? dashboard_rows(
    $conn,
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS bucket, COUNT(*) AS total_count
     FROM members
     WHERE created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01'){$member_scope_no_alias}
     GROUP BY DATE_FORMAT(created_at, '%Y-%m')
     ORDER BY bucket ASC"
) : [];
$payment_growth_rows = $can_view_payment_dashboard ? dashboard_rows(
    $conn,
    "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS bucket, COALESCE(SUM(amount), 0) AS total_amount
     FROM v_posted_payments
     WHERE payment_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
       AND {$reversal_filter} AND {$successful_payment_filter}{$payment_scope_no_alias}
     GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
     ORDER BY bucket ASC"
) : [];

$member_growth_map = dashboard_merge_month_values($month_seed, $member_growth_rows, 'bucket', 'total_count');
$payment_growth_map = dashboard_merge_month_values($month_seed, $payment_growth_rows, 'bucket', 'total_amount');

$trend_labels = [];
$member_growth_values = [];
$payment_growth_values = [];
foreach ($member_growth_map as $bucket => $item) {
    $trend_labels[] = $item['label'];
    $member_growth_values[] = (int) $item['value'];
    $payment_growth_values[] = (float) ($payment_growth_map[$bucket]['value'] ?? 0);
}

$attendance_labels = [];
$attendance_values = [];
foreach ($attendance_trend_rows as $trend_row) {
    $attendance_labels[] = date('M j', strtotime((string) $trend_row['service_date']));
    $attendance_values[] = dashboard_percent(
        (int) ($trend_row['present_count'] ?? 0),
        (int) ($trend_row['total_count'] ?? 0)
    );
}

$membership_labels = [];
$membership_values = [];
foreach ($membership_mix as $item) {
    if ((int) $item['value'] > 0) {
        $membership_labels[] = $item['label'];
        $membership_values[] = (int) $item['value'];
    }
}

$cashier_payment_count = 0;
$cashier_total_amount = 0;
$cashier_today_amount = 0;
$cashier_today_count = 0;
$cashier_week_count = 0;
$cashier_month_count = 0;
$cashier_average_ticket = 0;
$cashier_payment_modes = [];
$cashier_recent_payments = [];
$cashier_month_labels = $trend_labels;
$cashier_month_values = array_fill(0, count($trend_labels), 0);

if ($is_cashier && $can_view_payment_dashboard && $current_user_id > 0) {
    $cashier_payment_count = (int) dashboard_scalar(
        $conn,
        "SELECT COUNT(*) AS total FROM v_posted_payments WHERE {$reversal_filter} AND {$successful_payment_filter}{$cashier_filter_no_alias}"
    );
    $cashier_total_amount = (float) dashboard_scalar(
        $conn,
        "SELECT COALESCE(SUM(amount), 0) AS total FROM v_posted_payments WHERE {$reversal_filter} AND {$successful_payment_filter}{$cashier_filter_no_alias}"
    );
    $cashier_today_count = (int) dashboard_scalar(
        $conn,
        "SELECT COUNT(*) AS total FROM v_posted_payments WHERE DATE(payment_date) = CURDATE() AND {$reversal_filter} AND {$successful_payment_filter}{$cashier_filter_no_alias}"
    );
    $cashier_today_amount = (float) dashboard_scalar(
        $conn,
        "SELECT COALESCE(SUM(amount), 0) AS total FROM v_posted_payments WHERE DATE(payment_date) = CURDATE() AND {$reversal_filter} AND {$successful_payment_filter}{$cashier_filter_no_alias}"
    );
    $cashier_week_count = (int) dashboard_scalar(
        $conn,
        "SELECT COUNT(*) AS total FROM v_posted_payments WHERE YEARWEEK(payment_date, 1) = YEARWEEK(CURDATE(), 1) AND {$reversal_filter} AND {$successful_payment_filter}{$cashier_filter_no_alias}"
    );
    $cashier_month_count = (int) dashboard_scalar(
        $conn,
        "SELECT COUNT(*) AS total FROM v_posted_payments WHERE YEAR(payment_date) = YEAR(CURDATE()) AND MONTH(payment_date) = MONTH(CURDATE()) AND {$reversal_filter} AND {$successful_payment_filter}{$cashier_filter_no_alias}"
    );
    $cashier_average_ticket = $cashier_payment_count > 0 ? ($cashier_total_amount / $cashier_payment_count) : 0;

    $cashier_payment_modes = dashboard_rows(
        $conn,
        "SELECT
            COALESCE(NULLIF(p.mode, ''), 'Unspecified') AS label,
            COUNT(*) AS entry_count,
            COALESCE(SUM(p.amount), 0) AS total_amount
         FROM v_posted_payments p
         WHERE {$reversal_filter} AND {$successful_payment_filter_alias}{$cashier_filter}
         GROUP BY COALESCE(NULLIF(p.mode, ''), 'Unspecified')
         ORDER BY total_amount DESC"
    );

    $cashier_recent_payments = dashboard_rows(
        $conn,
        "SELECT
            p.id,
            p.amount,
            p.payment_date,
            COALESCE(NULLIF(pt.name, ''), 'Payment') AS payment_type,
            COALESCE(
                NULLIF(CONCAT_WS(' ', m.last_name, m.first_name), ''),
                NULLIF(CONCAT_WS(' ', ss.last_name, ss.first_name), ''),
                'Unknown payer'
            ) AS payer_name
         FROM v_posted_payments p
         LEFT JOIN members m ON m.id = p.member_id
         LEFT JOIN sunday_school ss ON ss.id = p.sundayschool_id
         LEFT JOIN payment_types pt ON pt.id = p.payment_type_id
         WHERE {$reversal_filter} AND {$successful_payment_filter_alias}{$cashier_filter}
         ORDER BY p.payment_date DESC, p.id DESC
         LIMIT 8"
    );

    $cashier_month_rows = dashboard_rows(
        $conn,
        "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS bucket, COALESCE(SUM(amount), 0) AS total_amount
         FROM v_posted_payments
         WHERE payment_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
           AND {$reversal_filter} AND {$successful_payment_filter}{$cashier_filter_no_alias}
         GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
         ORDER BY bucket ASC"
    );

    $cashier_month_map = dashboard_merge_month_values(dashboard_month_buckets(6), $cashier_month_rows, 'bucket', 'total_amount');
    $cashier_month_labels = [];
    $cashier_month_values = [];
    foreach ($cashier_month_map as $cashier_month) {
        $cashier_month_labels[] = $cashier_month['label'];
        $cashier_month_values[] = (float) $cashier_month['value'];
    }
}

$dashboard_actions = [];
if ($is_super_admin || has_permission('create_member')) {
    $dashboard_actions[] = ['label' => 'Register Member', 'icon' => 'fas fa-user-plus', 'href' => BASE_URL . '/views/member_form.php', 'tone' => 'primary'];
}
if ($is_super_admin || has_permission('view_member')) {
    $dashboard_actions[] = ['label' => 'Member Directory', 'icon' => 'fas fa-users', 'href' => BASE_URL . '/views/member_list.php', 'tone' => 'dark'];
}
if ($is_super_admin || has_permission('create_payment')) {
    $dashboard_actions[] = ['label' => 'Record Payment', 'icon' => 'fas fa-hand-holding-usd', 'href' => BASE_URL . '/views/payment_form.php', 'tone' => 'success'];
}
if ($is_super_admin || has_permission('view_payment_list')) {
    $dashboard_actions[] = ['label' => 'Payment History', 'icon' => 'fas fa-receipt', 'href' => BASE_URL . '/views/payment_list.php', 'tone' => 'success'];
}
if ($is_super_admin || has_permission('view_attendance_list') || has_permission('mark_attendance')) {
    $dashboard_actions[] = ['label' => 'Attendance', 'icon' => 'fas fa-clipboard-check', 'href' => BASE_URL . '/views/attendance_list.php', 'tone' => 'info'];
}
if ($is_super_admin || has_permission('view_reports_dashboard')) {
    $dashboard_actions[] = ['label' => 'Reports', 'icon' => 'fas fa-chart-line', 'href' => BASE_URL . '/views/reports.php', 'tone' => 'warning'];
}
if ($is_super_admin || has_permission('view_event_list')) {
    $dashboard_actions[] = ['label' => 'Events', 'icon' => 'fas fa-calendar-alt', 'href' => BASE_URL . '/views/event_list.php', 'tone' => 'secondary'];
}
if ($is_super_admin || has_permission('view_health_list')) {
    $dashboard_actions[] = ['label' => 'Health Records', 'icon' => 'fas fa-heartbeat', 'href' => BASE_URL . '/views/health_list.php', 'tone' => 'danger'];
}
if ($can_view_birthdays) {
    $dashboard_actions[] = ['label' => 'Birthdays', 'icon' => 'fas fa-birthday-cake', 'href' => BASE_URL . '/views/birthday_directory.php', 'tone' => 'warning'];
}
if ($is_super_admin || has_permission('view_sms_bulk') || has_permission('send_bulk_sms')) {
    $dashboard_actions[] = ['label' => 'Bulk SMS', 'icon' => 'fas fa-paper-plane', 'href' => BASE_URL . '/views/sms_bulk.php', 'tone' => 'warning'];
}

$leadership_links = [];
if ($class_ids !== null) {
    $leadership_links[] = [
        'label' => 'Bible Class Leadership',
        'href' => BASE_URL . '/views/my_bible_class_leader.php',
        'icon' => 'fas fa-chalkboard-teacher',
    ];
}
if ($org_ids !== null) {
    $leadership_links[] = [
        'label' => 'Organization Leadership',
        'href' => BASE_URL . '/views/my_organization_leader.php',
        'icon' => 'fas fa-sitemap',
    ];
}

$page_title = $is_cashier ? 'Cashier Dashboard' : 'Dashboard';

ob_start();
?>
<style>
:root {
    --dashboard-ink: #0f172a;
    --dashboard-muted: #64748b;
    --dashboard-surface: #ffffff;
    --dashboard-border: rgba(15, 23, 42, 0.08);
    --dashboard-shadow: 0 18px 44px rgba(15, 23, 42, 0.10);
    --dashboard-primary: #0f766e;
    --dashboard-primary-soft: #ccfbf1;
    --dashboard-accent: #ea580c;
    --dashboard-accent-soft: #ffedd5;
    --dashboard-gold: #d97706;
    --dashboard-slate-soft: #e2e8f0;
}

.dashboard-shell {
    padding: 26px 18px 34px;
    background:
        radial-gradient(circle at top left, rgba(15, 118, 110, 0.16), transparent 28%),
        radial-gradient(circle at top right, rgba(234, 88, 12, 0.12), transparent 25%),
        linear-gradient(180deg, #f8fafc 0%, #eef2f7 100%);
}

.dashboard-hero {
    position: relative;
    overflow: hidden;
    border-radius: 28px;
    padding: 28px;
    margin-bottom: 22px;
    color: #fff;
    background: linear-gradient(135deg, #0f172a 0%, #0f766e 54%, #ea580c 100%);
    box-shadow: 0 22px 50px rgba(15, 23, 42, 0.20);
}

.dashboard-hero::after {
    content: "";
    position: absolute;
    inset: auto -70px -90px auto;
    width: 240px;
    height: 240px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.10);
}

.dashboard-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
    padding: 7px 12px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.14);
    font-size: 0.82rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.dashboard-title {
    margin: 0 0 8px;
    font-size: 2rem;
    font-weight: 700;
    line-height: 1.15;
}

.dashboard-subtitle {
    max-width: 720px;
    margin: 0;
    color: rgba(255, 255, 255, 0.86);
    font-size: 1rem;
}

.dashboard-role-list,
.dashboard-link-list {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.dashboard-role-chip,
.dashboard-link-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.14);
    color: #fff;
    text-decoration: none;
    transition: transform 0.2s ease, background 0.2s ease;
}

.dashboard-role-chip {
    cursor: default;
}

.dashboard-link-chip:hover {
    color: #fff;
    text-decoration: none;
    transform: translateY(-1px);
    background: rgba(255, 255, 255, 0.22);
}

.dashboard-panel {
    background: var(--dashboard-surface);
    border: 1px solid var(--dashboard-border);
    border-radius: 22px;
    box-shadow: var(--dashboard-shadow);
    overflow: hidden;
    height: 100%;
}

.dashboard-panel-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding: 22px 22px 0;
}

.dashboard-panel-header h3,
.dashboard-panel-header h4 {
    margin: 0;
    color: var(--dashboard-ink);
    font-size: 1.05rem;
    font-weight: 700;
}

.dashboard-panel-header p {
    margin: 6px 0 0;
    color: var(--dashboard-muted);
    font-size: 0.92rem;
}

.dashboard-panel-body {
    padding: 22px;
}

.dashboard-stat-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 22px;
}

.dashboard-stat-card {
    position: relative;
    overflow: hidden;
    padding: 18px 18px 16px;
    border-radius: 20px;
    border: 1px solid rgba(255, 255, 255, 0.4);
    min-height: 142px;
}

.dashboard-stat-card.primary {
    background: linear-gradient(135deg, #ecfeff 0%, #ccfbf1 100%);
}

.dashboard-stat-card.success {
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
}

.dashboard-stat-card.warning {
    background: linear-gradient(135deg, #fff7ed 0%, #ffedd5 100%);
}

.dashboard-stat-card.dark {
    background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%);
}

.dashboard-stat-card.info {
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
}

.dashboard-stat-card.danger {
    background: linear-gradient(135deg, #fff1f2 0%, #ffe4e6 100%);
}

.dashboard-stat-label {
    color: var(--dashboard-muted);
    font-size: 0.84rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.dashboard-stat-value {
    margin-top: 12px;
    color: var(--dashboard-ink);
    font-size: 2rem;
    font-weight: 800;
    line-height: 1;
}

.dashboard-stat-meta {
    margin-top: 12px;
    color: #334155;
    font-size: 0.92rem;
}

.dashboard-action-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px;
}

.dashboard-action-card {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 14px;
    padding: 16px 18px;
    border-radius: 18px;
    border: 1px solid var(--dashboard-border);
    background: #fff;
    color: var(--dashboard-ink);
    text-decoration: none;
    min-height: 120px;
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
}

.dashboard-action-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 16px 28px rgba(15, 23, 42, 0.10);
    color: var(--dashboard-ink);
    text-decoration: none;
}

.dashboard-action-card .icon {
    width: 46px;
    height: 46px;
    border-radius: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
}

.dashboard-action-card.primary .icon { background: #dbeafe; color: #1d4ed8; }
.dashboard-action-card.success .icon { background: #dcfce7; color: #15803d; }
.dashboard-action-card.warning .icon { background: #ffedd5; color: #c2410c; }
.dashboard-action-card.info .icon { background: #cffafe; color: #0f766e; }
.dashboard-action-card.danger .icon { background: #ffe4e6; color: #be123c; }
.dashboard-action-card.secondary .icon,
.dashboard-action-card.dark .icon { background: #e2e8f0; color: #334155; }

.dashboard-action-title {
    margin: 0;
    font-size: 1rem;
    font-weight: 700;
}

.dashboard-action-copy {
    margin: 0;
    font-size: 0.9rem;
    color: var(--dashboard-muted);
}

.dashboard-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.dashboard-list-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 14px 16px;
    border: 1px solid var(--dashboard-border);
    border-radius: 18px;
    background: #fff;
}

.dashboard-list-item strong {
    display: block;
    color: var(--dashboard-ink);
    line-height: 1.25;
}

.dashboard-list-item small {
    color: var(--dashboard-muted);
}

.dashboard-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 10px;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.dashboard-pill.success { background: #dcfce7; color: #166534; }
.dashboard-pill.warning { background: #fef3c7; color: #92400e; }
.dashboard-pill.secondary { background: #e2e8f0; color: #475569; }
.dashboard-pill.light { background: #f1f5f9; color: #64748b; }

.dashboard-empty {
    padding: 26px 20px;
    border-radius: 18px;
    border: 1px dashed #cbd5e1;
    color: var(--dashboard-muted);
    text-align: center;
    background: #f8fafc;
}

.dashboard-chart-wrap {
    position: relative;
    height: 290px;
}

.dashboard-chart-wrap.compact {
    height: 250px;
}

.dashboard-highlight {
    display: grid;
    gap: 14px;
}

.dashboard-highlight-card {
    padding: 16px 18px;
    border-radius: 18px;
    background: linear-gradient(135deg, #fff7ed 0%, #ffffff 100%);
    border: 1px solid var(--dashboard-border);
}

.dashboard-highlight-card h5 {
    margin: 0 0 8px;
    font-size: 0.92rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--dashboard-muted);
}

.dashboard-highlight-value {
    color: var(--dashboard-ink);
    font-size: 1.5rem;
    font-weight: 800;
}

.dashboard-mini-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.dashboard-mini-card {
    padding: 16px;
    border-radius: 18px;
    background: #f8fafc;
    border: 1px solid var(--dashboard-border);
}

.dashboard-mini-card .label {
    display: block;
    margin-bottom: 8px;
    color: var(--dashboard-muted);
    font-size: 0.84rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.dashboard-mini-card .value {
    color: var(--dashboard-ink);
    font-size: 1.55rem;
    font-weight: 800;
    line-height: 1.05;
}

.dashboard-mini-card .meta {
    display: block;
    margin-top: 8px;
    color: var(--dashboard-muted);
    font-size: 0.88rem;
}

@media (max-width: 1199.98px) {
    .dashboard-stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 767.98px) {
    .dashboard-shell {
        padding: 16px 10px 28px;
    }

    .dashboard-hero {
        padding: 22px 18px;
        border-radius: 22px;
    }

    .dashboard-title {
        font-size: 1.7rem;
    }

    .dashboard-stat-grid,
    .dashboard-mini-grid {
        grid-template-columns: 1fr;
    }

    .dashboard-list-item {
        flex-direction: column;
        align-items: flex-start;
    }

    .dashboard-chart-wrap,
    .dashboard-chart-wrap.compact {
        height: 240px;
    }
}
</style>

<div class="dashboard-shell">
    <section class="dashboard-hero">
        <div class="row align-items-end">
            <div class="col-lg-8">
                <div class="dashboard-eyebrow">
                    <i class="fas fa-chart-pie"></i>
                    <span><?= $is_cashier ? 'Cash Collection Dashboard' : 'Church Operations Dashboard' ?></span>
                </div>
                <h1 class="dashboard-title">
                    <?= htmlspecialchars($time_greeting . ', ' . $display_name) ?>
                </h1>
                <p class="dashboard-subtitle">
                    <?= $is_cashier
                        ? 'This view focuses on your recorded collections while still keeping the broader church snapshot close at hand.'
                        : 'This redesigned dashboard gives you a cleaner view of membership, giving, attendance, health activity, and upcoming events.' ?>
                </p>
            </div>
            <div class="col-lg-4 mt-4 mt-lg-0">
                <div class="dashboard-role-list justify-content-lg-end">
                    <?php if (!empty($role_names)): ?>
                        <?php foreach ($role_names as $role_name): ?>
                            <span class="dashboard-role-chip">
                                <i class="fas fa-user-shield"></i>
                                <?= htmlspecialchars($role_name) ?>
                            </span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="dashboard-role-chip">
                            <i class="fas fa-user"></i>
                            User
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!empty($leadership_links) && $force_main): ?>
            <div class="dashboard-link-list mt-4">
                <?php foreach ($leadership_links as $link): ?>
                    <a class="dashboard-link-chip" href="<?= htmlspecialchars($link['href']) ?>">
                        <i class="<?= htmlspecialchars($link['icon']) ?>"></i>
                        <?= htmlspecialchars($link['label']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($is_cashier && $can_view_payment_dashboard): ?>
        <section class="dashboard-stat-grid">
            <article class="dashboard-stat-card success">
                <div class="dashboard-stat-label">Total Collected</div>
                <div class="dashboard-stat-value"><?= htmlspecialchars(dashboard_currency($cashier_total_amount)) ?></div>
                <div class="dashboard-stat-meta"><?= number_format($cashier_payment_count) ?> successful payments recorded by you</div>
            </article>
            <article class="dashboard-stat-card info">
                <div class="dashboard-stat-label">Collected Today</div>
                <div class="dashboard-stat-value"><?= htmlspecialchars(dashboard_currency($cashier_today_amount)) ?></div>
                <div class="dashboard-stat-meta"><?= number_format($cashier_today_count) ?> payments posted today</div>
            </article>
            <article class="dashboard-stat-card warning">
                <div class="dashboard-stat-label">This Week</div>
                <div class="dashboard-stat-value"><?= number_format($cashier_week_count) ?></div>
                <div class="dashboard-stat-meta">Payments recorded in the current week</div>
            </article>
            <article class="dashboard-stat-card dark">
                <div class="dashboard-stat-label">Average Ticket</div>
                <div class="dashboard-stat-value"><?= htmlspecialchars(dashboard_currency($cashier_average_ticket)) ?></div>
                <div class="dashboard-stat-meta"><?= number_format($cashier_month_count) ?> payments in the current month</div>
            </article>
        </section>
    <?php elseif ($has_operational_dashboard_data): ?>
        <section class="dashboard-stat-grid">
            <?php if ($can_view_membership_dashboard): ?>
            <article class="dashboard-stat-card primary">
                <div class="dashboard-stat-label">Community Size</div>
                <div class="dashboard-stat-value"><?= number_format($community_size) ?></div>
                <div class="dashboard-stat-meta">Full, catechumen, adherent and junior members</div>
            </article>
            <?php endif; ?>
            <?php if ($can_view_payment_dashboard): ?>
            <article class="dashboard-stat-card success">
                <div class="dashboard-stat-label">Total Giving</div>
                <div class="dashboard-stat-value"><?= htmlspecialchars(dashboard_currency($payment_total)) ?></div>
                <div class="dashboard-stat-meta"><?= number_format($payment_count) ?> successful payment records</div>
            </article>
            <?php endif; ?>
            <?php if ($can_view_attendance_dashboard): ?>
            <article class="dashboard-stat-card warning">
                <div class="dashboard-stat-label">Attendance Snapshot</div>
                <div class="dashboard-stat-value"><?= number_format($latest_attendance_rate, 1) ?>%</div>
                <div class="dashboard-stat-meta"><?= htmlspecialchars($latest_attendance_title) ?></div>
            </article>
            <?php endif; ?>
            <?php if ($can_view_event_dashboard): ?>
            <article class="dashboard-stat-card dark">
                <div class="dashboard-stat-label">Upcoming Events</div>
                <div class="dashboard-stat-value"><?= number_format($upcoming_events) ?></div>
                <div class="dashboard-stat-meta"><?= number_format($events_this_month) ?> scheduled this month</div>
            </article>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if (!empty($dashboard_actions)): ?>
        <section class="dashboard-panel mb-4">
            <div class="dashboard-panel-header">
                <div>
                    <h3>Quick Actions</h3>
                    <p>Shortcuts to the pages you are most likely to need next.</p>
                </div>
            </div>
            <div class="dashboard-panel-body">
                <div class="dashboard-action-grid">
                    <?php foreach ($dashboard_actions as $action): ?>
                        <a class="dashboard-action-card <?= htmlspecialchars($action['tone']) ?>" href="<?= htmlspecialchars($action['href']) ?>">
                            <span class="icon">
                                <i class="<?= htmlspecialchars($action['icon']) ?>"></i>
                            </span>
                            <div>
                                <h4 class="dashboard-action-title"><?= htmlspecialchars($action['label']) ?></h4>
                                <p class="dashboard-action-copy">Open this workspace directly from your dashboard.</p>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($can_view_birthdays): ?>
        <section class="dashboard-panel mb-4">
            <div class="dashboard-panel-header">
                <div>
                    <h3><i class="fas fa-birthday-cake text-warning mr-2"></i>Birthday Summary</h3>
                    <p>Only active members within your authorized scope are included.</p>
                </div>
                <a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>/views/birthday_directory.php">View Directory</a>
            </div>
            <div class="dashboard-panel-body">
                <div class="row">
                    <div class="col-xl-5 mb-3 mb-xl-0">
                        <div class="dashboard-mini-grid">
                            <div class="dashboard-mini-card"><span class="label">Yesterday</span><span class="value"><?= number_format($birthday_summary['yesterday']) ?></span></div>
                            <div class="dashboard-mini-card"><span class="label">Today</span><span class="value"><?= number_format($birthday_summary['today']) ?></span></div>
                            <div class="dashboard-mini-card"><span class="label">Tomorrow</span><span class="value"><?= number_format($birthday_summary['tomorrow']) ?></span></div>
                            <div class="dashboard-mini-card"><span class="label">This Month</span><span class="value"><?= number_format($birthday_summary['month']) ?></span></div>
                        </div>
                    </div>
                    <div class="col-xl-7">
                        <?php if ($today_birthdays): ?>
                            <div class="dashboard-list">
                                <?php foreach ($today_birthdays as $birthday_member): ?>
                                    <div class="dashboard-list-item">
                                        <div>
                                            <strong><?= htmlspecialchars($birthday_member['full_name']) ?></strong>
                                            <small><?= htmlspecialchars((string) ($birthday_member['class_name'] ?: 'No Bible Class')) ?> &middot; <?= htmlspecialchars((string) ($birthday_member['organizations'] ?: 'No organization')) ?></small>
                                        </div>
                                        <span class="dashboard-pill warning"><?= (int) $birthday_member['current_age'] ?> years</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="dashboard-empty">No members in your scope are celebrating a birthday today.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($is_cashier && $can_view_payment_dashboard): ?>
        <div class="row">
            <div class="col-xl-8 mb-4">
                <section class="dashboard-panel">
                    <div class="dashboard-panel-header">
                        <div>
                            <h3>Collection Trend</h3>
                            <p>Your successful collections across the last six months.</p>
                        </div>
                    </div>
                    <div class="dashboard-panel-body">
                        <div class="dashboard-chart-wrap">
                            <canvas id="cashierCollectionsChart"></canvas>
                        </div>
                    </div>
                </section>
            </div>
            <div class="col-xl-4 mb-4">
                <section class="dashboard-panel">
                    <div class="dashboard-panel-header">
                        <div>
                            <h3>Church Snapshot</h3>
                            <p>Context around the wider church while you work.</p>
                        </div>
                    </div>
                    <div class="dashboard-panel-body">
                        <div class="dashboard-highlight">
                            <div class="dashboard-highlight-card">
                                <h5>Church Giving This Month</h5>
                                <div class="dashboard-highlight-value"><?= htmlspecialchars(dashboard_currency($payments_this_month)) ?></div>
                            </div>
                            <div class="dashboard-mini-grid">
                                <div class="dashboard-mini-card">
                                    <span class="label">Active Members</span>
                                    <span class="value"><?= number_format($active_members) ?></span>
                                    <span class="meta"><?= number_format($pending_members) ?> pending registrations</span>
                                </div>
                                <div class="dashboard-mini-card">
                                    <span class="label">Latest Attendance</span>
                                    <span class="value"><?= number_format($latest_attendance_rate, 1) ?>%</span>
                                    <span class="meta"><?= htmlspecialchars($latest_attendance_title) ?></span>
                                </div>
                                <div class="dashboard-mini-card">
                                    <span class="label">Health Records</span>
                                    <span class="value"><?= number_format($health_records) ?></span>
                                    <span class="meta"><?= number_format($health_this_month) ?> added this month</span>
                                </div>
                                <div class="dashboard-mini-card">
                                    <span class="label">Upcoming Events</span>
                                    <span class="value"><?= number_format($upcoming_events) ?></span>
                                    <span class="meta"><?= number_format($events_this_month) ?> in this month</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-4 mb-4">
                <section class="dashboard-panel">
                    <div class="dashboard-panel-header">
                        <div>
                            <h3>Payment Modes</h3>
                            <p>How your collections are being recorded.</p>
                        </div>
                    </div>
                    <div class="dashboard-panel-body">
                        <?php if (!empty($cashier_payment_modes)): ?>
                            <div class="dashboard-list">
                                <?php foreach ($cashier_payment_modes as $mode): ?>
                                    <div class="dashboard-list-item">
                                        <div>
                                            <strong><?= htmlspecialchars($mode['label']) ?></strong>
                                            <small><?= number_format((int) $mode['entry_count']) ?> payments</small>
                                        </div>
                                        <strong><?= htmlspecialchars(dashboard_currency($mode['total_amount'])) ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="dashboard-empty">No payment records have been posted by you yet.</div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
            <div class="col-xl-8 mb-4">
                <section class="dashboard-panel">
                    <div class="dashboard-panel-header">
                        <div>
                            <h3>Recent Payments</h3>
                            <p>Your latest payment activity, newest first.</p>
                        </div>
                    </div>
                    <div class="dashboard-panel-body">
                        <?php if (!empty($cashier_recent_payments)): ?>
                            <div class="dashboard-list">
                                <?php foreach ($cashier_recent_payments as $payment): ?>
                                    <div class="dashboard-list-item">
                                        <div>
                                            <strong><?= htmlspecialchars($payment['payer_name']) ?></strong>
                                            <small><?= htmlspecialchars($payment['payment_type']) ?> on <?= date('M j, Y g:i A', strtotime($payment['payment_date'])) ?></small>
                                        </div>
                                        <strong><?= htmlspecialchars(dashboard_currency($payment['amount'])) ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="dashboard-empty">No recent cashier payments are available yet.</div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>
    <?php elseif ($has_operational_dashboard_data): ?>
        <div class="row">
            <?php if ($can_view_membership_dashboard || $can_view_payment_dashboard): ?><div class="<?= $can_view_membership_dashboard ? 'col-xl-8' : 'col-12' ?> mb-4">
                <section class="dashboard-panel">
                    <div class="dashboard-panel-header">
                        <div>
                            <h3><?= $can_view_membership_dashboard && $can_view_payment_dashboard ? 'Growth and Giving Trend' : ($can_view_membership_dashboard ? 'Membership Growth Trend' : 'Giving Trend') ?></h3>
                            <p>Authorized activity over the last six months.</p>
                        </div>
                    </div>
                    <div class="dashboard-panel-body">
                        <div class="dashboard-chart-wrap">
                            <canvas id="growthTrendChart"></canvas>
                        </div>
                    </div>
                </section>
            </div><?php endif; ?>
            <?php if ($can_view_membership_dashboard): ?><div class="col-xl-4 mb-4">
                <section class="dashboard-panel">
                    <div class="dashboard-panel-header">
                        <div>
                            <h3>Membership Mix</h3>
                            <p>A quick look at how the community is distributed.</p>
                        </div>
                    </div>
                    <div class="dashboard-panel-body">
                        <div class="dashboard-chart-wrap compact">
                            <canvas id="membershipMixChart"></canvas>
                        </div>
                    </div>
                </section>
            </div><?php endif; ?>
        </div>

        <?php if ($can_view_membership_dashboard || $can_view_payment_dashboard): ?>
        <div class="row">
            <?php if ($can_view_membership_dashboard): ?><div class="col-xl-4 mb-4">
                <section class="dashboard-panel">
                    <div class="dashboard-panel-header">
                        <div>
                            <h3>Membership Health</h3>
                            <p>Registration and classification totals at a glance.</p>
                        </div>
                    </div>
                    <div class="dashboard-panel-body">
                        <div class="dashboard-mini-grid">
                            <div class="dashboard-mini-card">
                                <span class="label">Full Members</span>
                                <span class="value"><?= number_format($full_members) ?></span>
                                <span class="meta">Confirmed and baptized</span>
                            </div>
                            <div class="dashboard-mini-card">
                                <span class="label">Catechumens</span>
                                <span class="value"><?= number_format($catechumens) ?></span>
                                <span class="meta">Baptized and awaiting confirmation</span>
                            </div>
                            <div class="dashboard-mini-card">
                                <span class="label">Adherents</span>
                                <span class="value"><?= number_format($adherents) ?></span>
                                <span class="meta">Active adherent records</span>
                            </div>
                            <div class="dashboard-mini-card">
                                <span class="label">Junior Members</span>
                                <span class="value"><?= number_format($junior_members) ?></span>
                                <span class="meta">Sunday School community</span>
                            </div>
                            <div class="dashboard-mini-card">
                                <span class="label">Distant Members</span>
                                <span class="value"><?= number_format($distant_members) ?></span>
                                <span class="meta">Active but worshipping at a distance</span>
                            </div>
                            <div class="dashboard-mini-card">
                                <span class="label">Invalid Members</span>
                                <span class="value"><?= number_format($invalid_members) ?></span>
                                <span class="meta">Excluded from community total</span>
                            </div>
                            <div class="dashboard-mini-card">
                                <span class="label">Unclassified</span>
                                <span class="value"><?= number_format($unclassified_members) ?></span>
                                <span class="meta"><?= number_format($pending_members) ?> pending registrations</span>
                            </div>
                            <div class="dashboard-mini-card">
                                <span class="label">Needs Review</span>
                                <span class="value"><?= number_format($status_review_members) ?></span>
                                <span class="meta">Unclassified or conflicting evidence</span>
                            </div>
                        </div>
                        <div class="dashboard-highlight mt-3">
                            <div class="dashboard-highlight-card">
                                <h5>Members with an Approved Status</h5>
                                <div class="dashboard-highlight-value"><?= number_format($classified_members) ?></div>
                            </div>
                            <?php if ($can_view_payment_dashboard): ?><div class="dashboard-highlight-card">
                                <h5>Giving This Week</h5>
                                <div class="dashboard-highlight-value"><?= htmlspecialchars(dashboard_currency($payments_this_week)) ?></div>
                            </div><?php endif; ?>
                        </div>
                    </div>
                </section>
            </div><?php endif; ?>
            <?php if ($can_view_payment_dashboard): ?><div class="col-xl-4 mb-4">
                <section class="dashboard-panel">
                    <div class="dashboard-panel-header">
                        <div>
                            <h3>Payment Modes</h3>
                            <p>Amounts grouped by recorded payment mode.</p>
                        </div>
                    </div>
                    <div class="dashboard-panel-body">
                        <?php if (!empty($payment_modes)): ?>
                            <div class="dashboard-list">
                                <?php foreach ($payment_modes as $mode): ?>
                                    <div class="dashboard-list-item">
                                        <div>
                                            <strong><?= htmlspecialchars($mode['label']) ?></strong>
                                            <small><?= number_format((int) $mode['entry_count']) ?> payments</small>
                                        </div>
                                        <strong><?= htmlspecialchars(dashboard_currency($mode['total_amount'])) ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="dashboard-empty">No payment mode data is available yet.</div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
            <div class="col-xl-4 mb-4">
                <section class="dashboard-panel">
                    <div class="dashboard-panel-header">
                        <div>
                            <h3>This Year (<?= $current_year ?>) by Payment Type</h3>
                            <p>Current-year totals for every active payment type in your authorized scope.</p>
                        </div>
                    </div>
                    <div class="dashboard-panel-body">
                        <?php if (!empty($top_payment_types)): ?>
                            <div class="dashboard-list">
                                <?php foreach ($top_payment_types as $type): ?>
                                    <div class="dashboard-list-item">
                                        <div>
                                            <strong><?= htmlspecialchars($type['label']) ?></strong>
                                            <small><?= number_format((int) $type['entry_count']) ?> payments</small>
                                        </div>
                                        <strong><?= htmlspecialchars(dashboard_currency($type['total_amount'])) ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="dashboard-empty">No payment type data is available yet.</div>
                        <?php endif; ?>
                    </div>
                </section>
            </div><?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="row">
            <?php if ($can_view_membership_dashboard): ?><div class="col-xl-4 mb-4">
                <section class="dashboard-panel">
                    <div class="dashboard-panel-header">
                        <div>
                            <h3>Recent Members</h3>
                            <p>The newest additions to the directory.</p>
                        </div>
                    </div>
                    <div class="dashboard-panel-body">
                        <?php if (!empty($recent_members)): ?>
                            <div class="dashboard-list">
                                <?php foreach ($recent_members as $member): ?>
                                    <div class="dashboard-list-item">
                                        <div>
                                            <strong><?= htmlspecialchars($member['member_name']) ?></strong>
                                            <small><?= htmlspecialchars($member['class_name']) ?> • <?= date('M j, Y', strtotime($member['created_at'])) ?></small>
                                        </div>
                                        <span class="dashboard-pill <?= dashboard_status_tone($member['status']) ?>">
                                            <?= htmlspecialchars($member['status']) ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="dashboard-empty">No member registrations are available yet.</div>
                        <?php endif; ?>
                    </div>
                </section>
            </div><?php endif; ?>
            <?php if ($can_view_payment_dashboard): ?><div class="col-xl-4 mb-4">
                <section class="dashboard-panel">
                    <div class="dashboard-panel-header">
                        <div>
                            <h3>Recent Payments</h3>
                            <p>Latest successful transactions across the church.</p>
                        </div>
                    </div>
                    <div class="dashboard-panel-body">
                        <?php if (!empty($recent_payments)): ?>
                            <div class="dashboard-list">
                                <?php foreach ($recent_payments as $payment): ?>
                                    <div class="dashboard-list-item">
                                        <div>
                                            <strong><?= htmlspecialchars($payment['payer_name']) ?></strong>
                                            <small><?= htmlspecialchars($payment['payment_type']) ?> on <?= date('M j, Y g:i A', strtotime($payment['payment_date'])) ?></small>
                                        </div>
                                        <strong><?= htmlspecialchars(dashboard_currency($payment['amount'])) ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="dashboard-empty">No recent payment activity is available yet.</div>
                        <?php endif; ?>
                    </div>
                </section>
            </div><?php endif; ?>
            <?php if ($can_view_event_dashboard): ?><div class="col-xl-4 mb-4">
                <section class="dashboard-panel">
                    <div class="dashboard-panel-header">
                        <div>
                            <h3>Upcoming Events</h3>
                            <p>What the church calendar looks like next.</p>
                        </div>
                    </div>
                    <div class="dashboard-panel-body">
                        <?php if (!empty($recent_events)): ?>
                            <div class="dashboard-list">
                                <?php foreach ($recent_events as $event): ?>
                                    <div class="dashboard-list-item">
                                        <div>
                                            <strong><?= htmlspecialchars($event['name']) ?></strong>
                                            <small><?= date('D, M j, Y', strtotime($event['event_date'])) ?><?php if (!empty($event['location'])): ?> • <?= htmlspecialchars($event['location']) ?><?php endif; ?></small>
                                        </div>
                                        <span class="dashboard-pill light">Event</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="dashboard-empty">No upcoming events are scheduled right now.</div>
                        <?php endif; ?>
                    </div>
                </section>
            </div><?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($can_view_attendance_dashboard || $can_view_payment_dashboard || $can_view_health_dashboard): ?>
    <div class="row"><div class="col-12 mb-4">
        <section class="dashboard-panel">
            <div class="dashboard-panel-header">
                <div>
                    <h3>Operational Period Summary</h3>
                    <p>Today through overall totals, restricted to your authorized assignments.</p>
                </div>
            </div>
            <div class="dashboard-panel-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="thead-light"><tr><th>Period</th>
                            <?php if ($can_view_payment_dashboard): ?><th class="text-right">Posted Payments</th><?php endif; ?>
                            <?php if ($can_view_attendance_dashboard): ?><th class="text-right">Present Attendance</th><?php endif; ?>
                            <?php if ($can_view_health_dashboard): ?><th class="text-right">Health Records</th><?php endif; ?>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($dashboard_period_summary['periods'] as $periodKey => $period): ?>
                            <tr><th><?= htmlspecialchars($period['label']) ?></th>
                                <?php if ($can_view_payment_dashboard): ?><td class="text-right font-weight-bold"><?= htmlspecialchars(dashboard_currency($dashboard_period_summary['payments'][$periodKey] ?? 0)) ?></td><?php endif; ?>
                                <?php if ($can_view_attendance_dashboard): ?><td class="text-right"><?= number_format((int) ($dashboard_period_summary['attendance'][$periodKey] ?? 0)) ?></td><?php endif; ?>
                                <?php if ($can_view_health_dashboard): ?><td class="text-right"><?= number_format((int) ($dashboard_period_summary['health'][$periodKey] ?? 0)) ?></td><?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="px-3 py-2 small text-muted border-top">Payments include posted transactions only. Attendance counts approved, non-draft Present records.</div>
            </div>
        </section>
    </div></div>
    <div class="row">
        <?php if ($can_view_attendance_dashboard): ?><div class="col-xl-6 mb-4">
            <section class="dashboard-panel">
                <div class="dashboard-panel-header">
                    <div>
                        <h3>Attendance Trend</h3>
                        <p>Attendance rates across the latest six sessions.</p>
                    </div>
                </div>
                <div class="dashboard-panel-body">
                    <?php if (!empty($attendance_values)): ?>
                        <div class="dashboard-chart-wrap compact">
                            <canvas id="attendanceTrendChart"></canvas>
                        </div>
                    <?php else: ?>
                        <div class="dashboard-empty">Attendance trend data is not available yet.</div>
                    <?php endif; ?>
                </div>
            </section>
        </div><?php endif; ?>
        <?php if ($can_view_payment_dashboard || $can_view_health_dashboard || $can_view_attendance_dashboard): ?><div class="col-xl-6 mb-4">
            <section class="dashboard-panel">
                <div class="dashboard-panel-header">
                    <div>
                        <h3>Operational Snapshot</h3>
                        <p>Additional indicators for today and this month.</p>
                    </div>
                </div>
                <div class="dashboard-panel-body">
                    <div class="dashboard-mini-grid">
                        <?php if ($can_view_payment_dashboard): ?><div class="dashboard-mini-card">
                            <span class="label">Giving Today</span>
                            <span class="value"><?= htmlspecialchars(dashboard_currency($payments_today)) ?></span>
                            <span class="meta">Successful payments recorded today</span>
                        </div>
                        <div class="dashboard-mini-card">
                            <span class="label">Giving This Month</span>
                            <span class="value"><?= htmlspecialchars(dashboard_currency($payments_this_month)) ?></span>
                            <span class="meta"><?= htmlspecialchars(dashboard_currency($average_payment)) ?> average payment amount</span>
                        </div><?php endif; ?>
                        <?php if ($can_view_health_dashboard): ?><div class="dashboard-mini-card">
                            <span class="label">Health Activity</span>
                            <span class="value"><?= number_format($health_records) ?></span>
                            <span class="meta"><?= number_format($health_this_month) ?> records added this month</span>
                        </div><?php endif; ?>
                        <?php if ($can_view_attendance_dashboard): ?><div class="dashboard-mini-card">
                            <span class="label">Attendance Sessions</span>
                            <span class="value"><?= number_format($attendance_sessions) ?></span>
                            <span class="meta">Authorized sessions recorded in the system</span>
                        </div><?php endif; ?>
                    </div>
                </div>
            </section>
        </div><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') {
        return;
    }

    const palette = {
        teal: '#0f766e',
        tealSoft: 'rgba(15, 118, 110, 0.18)',
        orange: '#ea580c',
        orangeSoft: 'rgba(234, 88, 12, 0.18)',
        slate: '#334155',
        gold: '#d97706',
        blue: '#2563eb',
        rose: '#e11d48'
    };

    const sharedGrid = {
        color: 'rgba(148, 163, 184, 0.18)',
        drawBorder: false
    };

    <?php if ($is_cashier && $can_view_payment_dashboard): ?>
    const cashierCollectionsCanvas = document.getElementById('cashierCollectionsChart');
    if (cashierCollectionsCanvas) {
        new Chart(cashierCollectionsCanvas, {
            type: 'bar',
            data: {
                labels: <?= json_encode($cashier_month_labels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                datasets: [{
                    label: 'Collections',
                    data: <?= json_encode($cashier_month_values, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                    backgroundColor: palette.tealSoft,
                    borderColor: palette.teal,
                    borderWidth: 2,
                    borderRadius: 10,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        beginAtZero: true,
                        grid: sharedGrid
                    }
                }
            }
        });
    }
    <?php else: ?>
    const growthTrendCanvas = document.getElementById('growthTrendChart');
    if (growthTrendCanvas) {
        new Chart(growthTrendCanvas, {
            type: 'line',
            data: {
                labels: <?= json_encode($trend_labels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                datasets: [{
                    label: 'New Members',
                    data: <?= json_encode($member_growth_values, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                    borderColor: palette.teal,
                    backgroundColor: palette.tealSoft,
                    fill: true,
                    tension: 0.35,
                    borderWidth: 3,
                    pointRadius: 4,
                    pointBackgroundColor: palette.teal
                }, {
                    label: 'Giving Amount',
                    data: <?= json_encode($payment_growth_values, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                    borderColor: palette.orange,
                    backgroundColor: palette.orangeSoft,
                    tension: 0.35,
                    borderWidth: 3,
                    pointRadius: 4,
                    pointBackgroundColor: palette.orange,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        beginAtZero: true,
                        grid: sharedGrid
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        grid: { drawOnChartArea: false }
                    }
                }
            }
        });
    }

    const membershipMixCanvas = document.getElementById('membershipMixChart');
    if (membershipMixCanvas) {
        new Chart(membershipMixCanvas, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($membership_labels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                datasets: [{
                    data: <?= json_encode($membership_values, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                    backgroundColor: [
                        'rgba(15, 118, 110, 0.88)',
                        'rgba(37, 99, 235, 0.82)',
                        'rgba(217, 119, 6, 0.82)',
                        'rgba(225, 29, 72, 0.80)',
                        'rgba(124, 58, 237, 0.80)',
                        'rgba(71, 85, 105, 0.82)'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12,
                            padding: 16
                        }
                    }
                },
                cutout: '62%'
            }
        });
    }
    <?php endif; ?>

    const attendanceTrendCanvas = document.getElementById('attendanceTrendChart');
    if (attendanceTrendCanvas) {
        new Chart(attendanceTrendCanvas, {
            type: 'line',
            data: {
                labels: <?= json_encode($attendance_labels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                datasets: [{
                    label: 'Attendance Rate (%)',
                    data: <?= json_encode($attendance_values, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                    borderColor: palette.slate,
                    backgroundColor: 'rgba(51, 65, 85, 0.12)',
                    fill: true,
                    tension: 0.35,
                    borderWidth: 3,
                    pointRadius: 4,
                    pointBackgroundColor: palette.gold
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        beginAtZero: true,
                        max: 100,
                        grid: sharedGrid
                    }
                }
            }
        });
    }
});
</script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
