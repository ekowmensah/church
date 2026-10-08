<?php
/**
 * Shared tenant-scope and date helpers for report routes.
 *
 * Report pages must never infer global access from a numeric account ID. The
 * canonical RBAC helper decides whether the current account is a super admin;
 * every other account is restricted to its resolved church.
 */

function report_scope_is_super_admin(): bool
{
    if (function_exists('is_super_admin')) {
        return is_super_admin();
    }

    return false;
}

function report_scope_current_church_id(mysqli $conn): int
{
    $churchId = (int) ($_SESSION['church_id'] ?? 0);
    if ($churchId > 0) {
        return $churchId;
    }

    $sources = [
        ['key' => 'user_id', 'table' => 'users'],
        ['key' => 'member_id', 'table' => 'members'],
    ];
    foreach ($sources as $source) {
        $actorId = (int) ($_SESSION[$source['key']] ?? 0);
        if ($actorId < 1) {
            continue;
        }
        $statement = $conn->prepare("SELECT church_id FROM {$source['table']} WHERE id = ? LIMIT 1");
        $statement->bind_param('i', $actorId);
        $statement->execute();
        $churchId = (int) ($statement->get_result()->fetch_assoc()['church_id'] ?? 0);
        $statement->close();
        if ($churchId > 0) {
            $_SESSION['church_id'] = $churchId;
            return $churchId;
        }
    }

    return 0;
}

function report_scope_safe_alias(string $alias): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('Invalid SQL alias supplied to report scope helper.');
    }
    return $alias;
}

/** Returns a SQL predicate suitable for an existing WHERE clause. */
function report_scope_church_condition(mysqli $conn, string $alias): string
{
    $alias = report_scope_safe_alias($alias);
    if (report_scope_is_super_admin()) {
        return '1 = 1';
    }

    $churchId = report_scope_current_church_id($conn);
    return $churchId > 0 ? "{$alias}.church_id = {$churchId}" : '1 = 0';
}

function report_scope_member_condition(mysqli $conn, string $alias = 'm'): string
{
    return report_scope_church_condition($conn, $alias);
}

function report_scope_payment_condition(mysqli $conn, string $alias = 'p'): string
{
    return report_scope_church_condition($conn, $alias);
}

function report_scope_valid_date(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
}

function report_scope_exclusive_end(string $date): string
{
    $date = report_scope_valid_date($date);
    return $date === '' ? '' : (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
}
