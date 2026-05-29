<?php

/**
 * Shared-hosting-safe Bible class capacity utilities.
 * Works even when migration-trigger support is unavailable.
 */

if (!function_exists('bible_class_rules_table_exists')) {
    function bible_class_rules_table_exists(mysqli $conn): bool
    {
        static $cache = [];

        $cache_key = spl_object_hash($conn);
        if (array_key_exists($cache_key, $cache)) {
            return $cache[$cache_key];
        }

        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'bible_class_rules'"
        );

        if (!$stmt) {
            $cache[$cache_key] = false;
            return false;
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        $exists = ((int) ($row['cnt'] ?? 0)) > 0;
        $cache[$cache_key] = $exists;
        return $exists;
    }
}

if (!function_exists('bible_class_capacity_rule')) {
    function bible_class_capacity_rule(mysqli $conn, int $class_id): array
    {
        $default = [
            'max_members' => 25,
            'enforce_limit' => 1
        ];

        if ($class_id <= 0 || !bible_class_rules_table_exists($conn)) {
            return $default;
        }

        $stmt = $conn->prepare(
            'SELECT max_members, enforce_limit
             FROM bible_class_rules
             WHERE class_id = ?
             LIMIT 1'
        );

        if (!$stmt) {
            return $default;
        }

        $stmt->bind_param('i', $class_id);
        if (!$stmt->execute()) {
            $stmt->close();
            return $default;
        }

        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            return $default;
        }

        $max_members = max(1, (int) ($row['max_members'] ?? 25));
        $enforce_limit = ((int) ($row['enforce_limit'] ?? 1)) === 1 ? 1 : 0;

        return [
            'max_members' => $max_members,
            'enforce_limit' => $enforce_limit
        ];
    }
}

if (!function_exists('bible_class_active_member_count')) {
    function bible_class_active_member_count(mysqli $conn, int $class_id, int $exclude_member_id = 0): int
    {
        if ($class_id <= 0) {
            return 0;
        }

        if ($exclude_member_id > 0) {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS cnt
                 FROM members
                 WHERE class_id = ?
                   AND status = 'active'
                   AND id <> ?"
            );
            if (!$stmt) {
                return 0;
            }
            $stmt->bind_param('ii', $class_id, $exclude_member_id);
        } else {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS cnt
                 FROM members
                 WHERE class_id = ?
                   AND status = 'active'"
            );
            if (!$stmt) {
                return 0;
            }
            $stmt->bind_param('i', $class_id);
        }

        if (!$stmt->execute()) {
            $stmt->close();
            return 0;
        }

        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return (int) ($row['cnt'] ?? 0);
    }
}

if (!function_exists('bible_class_validate_capacity')) {
    function bible_class_validate_capacity(mysqli $conn, int $class_id, int $exclude_member_id = 0): array
    {
        if ($class_id <= 0) {
            return [
                'allowed' => true,
                'enforce_limit' => 0,
                'max_members' => 0,
                'active_members' => 0,
                'message' => ''
            ];
        }

        $rule = bible_class_capacity_rule($conn, $class_id);
        $max_members = (int) $rule['max_members'];
        $enforce_limit = (int) $rule['enforce_limit'] === 1;
        $active_members = bible_class_active_member_count($conn, $class_id, $exclude_member_id);
        $allowed = !$enforce_limit || $active_members < $max_members;

        return [
            'allowed' => $allowed,
            'enforce_limit' => $enforce_limit ? 1 : 0,
            'max_members' => $max_members,
            'active_members' => $active_members,
            'message' => $allowed ? '' : 'Bible class membership limit reached (max 25 active members).'
        ];
    }
}

if (!function_exists('bible_class_capacity_error_message')) {
    function bible_class_capacity_error_message(): string
    {
        return 'Bible class is full (maximum 25 active members).';
    }
}

if (!function_exists('is_bible_class_capacity_error')) {
    function is_bible_class_capacity_error(string $error_text): bool
    {
        return stripos($error_text, 'Bible class membership limit reached') !== false;
    }
}

if (!function_exists('ensure_bible_class_rule')) {
    function ensure_bible_class_rule(mysqli $conn, int $class_id): bool
    {
        if ($class_id <= 0) {
            return false;
        }

        if (!bible_class_rules_table_exists($conn)) {
            // Shared hosting fallback: table not available, continue without failing class creation.
            return true;
        }

        $stmt = $conn->prepare(
            'INSERT INTO bible_class_rules (class_id, max_members, enforce_limit)
             VALUES (?, 25, 1)
             ON DUPLICATE KEY UPDATE class_id = VALUES(class_id)'
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $class_id);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }
}
