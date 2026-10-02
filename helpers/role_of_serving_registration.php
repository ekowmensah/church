<?php

if (!function_exists('role_of_serving_normalize_ids')) {
    function role_of_serving_normalize_ids(array $values): array
    {
        $ids = array_map('intval', $values);
        $ids = array_filter($ids, static fn(int $id): bool => $id > 0);
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}

if (!function_exists('role_of_serving_registration_validation_error')) {
    function role_of_serving_registration_validation_error(mysqli $conn, array $values): string
    {
        $ids = role_of_serving_normalize_ids($values);
        if (!$ids) {
            return 'Select at least one Role of Serving. Choose NONE when the member has no current office.';
        }

        $idList = implode(',', $ids);
        $result = $conn->query(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN LOWER(TRIM(name)) = 'none' THEN 1 ELSE 0 END) AS none_total
               FROM roles_of_serving WHERE id IN (" . $idList . ')'
        );
        $summary = $result ? $result->fetch_assoc() : [];
        $validCount = (int) ($summary['total'] ?? 0);
        if ($validCount !== count($ids)) {
            return 'One or more selected Roles of Serving are no longer available. Refresh and select again.';
        }
        if ((int) ($summary['none_total'] ?? 0) > 0 && count($ids) > 1) {
            return 'NONE cannot be combined with another Role of Serving.';
        }

        return '';
    }
}

if (!function_exists('role_of_serving_replace_member_assignments')) {
    function role_of_serving_replace_member_assignments(mysqli $conn, int $memberId, array $values): array
    {
        $ids = role_of_serving_normalize_ids($values);
        $validationError = role_of_serving_registration_validation_error($conn, $ids);
        if ($validationError !== '') {
            throw new RuntimeException($validationError);
        }

        $delete = $conn->prepare('DELETE FROM member_roles_of_serving WHERE member_id = ?');
        $delete->bind_param('i', $memberId);
        if (!$delete->execute()) {
            throw new RuntimeException($delete->error ?: 'Unable to replace the member Roles of Serving.');
        }
        $delete->close();

        $insert = $conn->prepare(
            'INSERT INTO member_roles_of_serving (member_id, role_id) VALUES (?, ?)'
        );
        foreach ($ids as $roleId) {
            $insert->bind_param('ii', $memberId, $roleId);
            if (!$insert->execute()) {
                throw new RuntimeException($insert->error ?: 'Unable to save a Role of Serving.');
            }
        }
        $insert->close();

        return $ids;
    }
}

if (!function_exists('role_of_serving_member_assignments')) {
    function role_of_serving_member_assignments(mysqli $conn, int $memberId): array
    {
        if ($memberId < 1) {
            return [];
        }

        $stmt = $conn->prepare(
            'SELECT serving_role.id, serving_role.name
               FROM member_roles_of_serving member_role
               JOIN roles_of_serving serving_role ON serving_role.id = member_role.role_id
              WHERE member_role.member_id = ?
              ORDER BY serving_role.name, serving_role.id'
        );
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}
