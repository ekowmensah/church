<?php

function payment_report_valid_date(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
}

function payment_report_resolve_period(string $preset, string $startDate, string $endDate): array
{
    $allowed = [
        'custom', 'today', 'yesterday', 'this_week', 'last_week',
        'this_month', 'last_month', 'q1', 'q2', 'q3', 'q4',
        'this_year', 'last_year', 'overall',
    ];
    if (!in_array($preset, $allowed, true)) $preset = 'custom';
    $today = new DateTimeImmutable('today');
    $year = (int) $today->format('Y');

    switch ($preset) {
        case 'today':
            $startDate = $endDate = $today->format('Y-m-d');
            break;
        case 'yesterday':
            $startDate = $endDate = $today->modify('-1 day')->format('Y-m-d');
            break;
        case 'this_week':
            $startDate = $today->modify('monday this week')->format('Y-m-d');
            $endDate = $today->modify('sunday this week')->format('Y-m-d');
            break;
        case 'last_week':
            $startDate = $today->modify('monday last week')->format('Y-m-d');
            $endDate = $today->modify('sunday last week')->format('Y-m-d');
            break;
        case 'this_month':
            $startDate = $today->modify('first day of this month')->format('Y-m-d');
            $endDate = $today->modify('last day of this month')->format('Y-m-d');
            break;
        case 'last_month':
            $startDate = $today->modify('first day of last month')->format('Y-m-d');
            $endDate = $today->modify('last day of last month')->format('Y-m-d');
            break;
        case 'q1': case 'q2': case 'q3': case 'q4':
            $quarter = (int) substr($preset, 1, 1);
            $month = (($quarter - 1) * 3) + 1;
            $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
            $startDate = $start->format('Y-m-d');
            $endDate = $start->modify('+2 months')->modify('last day of this month')->format('Y-m-d');
            break;
        case 'this_year':
            $startDate = $year . '-01-01';
            $endDate = $year . '-12-31';
            break;
        case 'last_year':
            $startDate = ($year - 1) . '-01-01';
            $endDate = ($year - 1) . '-12-31';
            break;
        case 'overall':
            $startDate = $endDate = '';
            break;
        default:
            $startDate = payment_report_valid_date($startDate);
            $endDate = payment_report_valid_date($endDate);
            if ($startDate !== '' && $endDate !== '' && $startDate > $endDate) {
                [$startDate, $endDate] = [$endDate, $startDate];
            }
    }

    return [$preset, $startDate, $endDate];
}

function payment_report_period_label(string $startDate, string $endDate): string
{
    if ($startDate === '' && $endDate === '') return 'Overall';
    if ($startDate !== '' && $endDate !== '' && $startDate === $endDate) {
        return date('j F Y', strtotime($startDate));
    }
    $from = $startDate !== '' ? date('j M Y', strtotime($startDate)) : 'Beginning';
    $to = $endDate !== '' ? date('j M Y', strtotime($endDate)) : 'Present';
    return $from . ' to ' . $to;
}

function payment_report_current_church_id(mysqli $conn): int
{
    $churchId = (int) ($_SESSION['church_id'] ?? 0);
    if ($churchId > 0) return $churchId;
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId < 1) return 0;
    $stmt = $conn->prepare('SELECT church_id FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $churchId = (int) ($stmt->get_result()->fetch_assoc()['church_id'] ?? 0);
    $stmt->close();
    return $churchId;
}

function payment_report_is_super_admin(): bool
{
    if (function_exists('is_super_admin')) {
        return is_super_admin();
    }

    return false;
}

function payment_report_member_scope_condition(mysqli $conn, string $memberAlias = 'm'): string
{
    if (payment_report_is_super_admin()) return '';
    $churchId = payment_report_current_church_id($conn);
    return $churchId > 0 ? $memberAlias . '.church_id = ' . $churchId : '1 = 0';
}

/** Scope a payment query directly, including Sunday School beneficiaries. */
function payment_report_payment_scope_condition(mysqli $conn, string $paymentAlias = 'p'): string
{
    if (payment_report_is_super_admin()) return '';
    $churchId = payment_report_current_church_id($conn);
    return $churchId > 0 ? $paymentAlias . '.church_id = ' . $churchId : '1 = 0';
}

function payment_report_organizations_expression(string $memberAlias = 'm'): string
{
    return "COALESCE((SELECT GROUP_CONCAT(DISTINCT organization.name ORDER BY organization.name SEPARATOR ', ')"
        . " FROM member_organizations membership"
        . " JOIN organizations organization ON organization.id = membership.organization_id"
        . " WHERE membership.member_id = {$memberAlias}.id), '-')";
}

function payment_report_period_options(): array
{
    return [
        'custom' => 'Custom Range', 'today' => 'Today', 'yesterday' => 'Yesterday',
        'this_week' => 'This Week', 'last_week' => 'Last Week',
        'this_month' => 'This Month', 'last_month' => 'Last Month',
        'q1' => '1st Quarter', 'q2' => '2nd Quarter',
        'q3' => '3rd Quarter', 'q4' => '4th Quarter',
        'this_year' => 'This Year', 'last_year' => 'Last Year', 'overall' => 'Overall',
    ];
}

function payment_report_valid_month(string $value): string
{
    $value = trim($value);
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) return '';
    return $value;
}

function payment_report_reporting_period_expression(string $paymentAlias = 'p'): string
{
    return "COALESCE(NULLIF({$paymentAlias}.reporting_period_label, ''),"
        . " NULLIF({$paymentAlias}.payment_period_description, ''),"
        . " DATE_FORMAT(COALESCE({$paymentAlias}.payment_period, {$paymentAlias}.payment_date), '%M %Y'))";
}

/** Return SQL clauses and values for an inclusive reporting-month range. */
function payment_report_reporting_month_filter(
    string $fromMonth,
    string $toMonth,
    string $paymentAlias = 'p'
): array {
    $fromMonth = payment_report_valid_month($fromMonth);
    $toMonth = payment_report_valid_month($toMonth);
    if ($fromMonth !== '' && $toMonth !== '' && $fromMonth > $toMonth) {
        [$fromMonth, $toMonth] = [$toMonth, $fromMonth];
    }
    $clauses = [];
    $values = [];
    $periodDate = "COALESCE({$paymentAlias}.payment_period, {$paymentAlias}.payment_date)";
    if ($fromMonth !== '') {
        $clauses[] = "{$periodDate} >= CONCAT(?, '-01')";
        $values[] = $fromMonth;
    }
    if ($toMonth !== '') {
        $clauses[] = "{$periodDate} < DATE_ADD(CONCAT(?, '-01'), INTERVAL 1 MONTH)";
        $values[] = $toMonth;
    }
    return [$fromMonth, $toMonth, $clauses, $values];
}
