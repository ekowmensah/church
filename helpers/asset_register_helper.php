<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions_v2.php';
require_once __DIR__ . '/church_helper.php';
require_once __DIR__ . '/global_audit_log.php';

if (!function_exists('asset_condition_options')) {
    function asset_condition_options(): array {
        return [
            'New',
            'Good',
            'Fair',
            'Poor',
            'Under Maintenance',
            'Damaged',
            'Obsolete',
            'Condemned',
            'Disposed',
        ];
    }
}

if (!function_exists('asset_acquisition_mode_options')) {
    function asset_acquisition_mode_options(): array {
        return [
            'purchase' => 'Purchase',
            'donation' => 'Donation',
            'grant' => 'Grant',
            'gift' => 'Gift',
            'inheritance' => 'Inheritance',
            'construction' => 'Construction',
            'manufactured_fabricated' => 'Manufactured/Fabricated',
            'transfer' => 'Transfer',
            'exchange_trade_in' => 'Exchange/Trade-In',
            'lease' => 'Lease',
            'hire_purchase' => 'Hire Purchase',
            'capital_project' => 'Capital Project',
            'sponsorship' => 'Sponsorship',
            'recovered' => 'Recovered',
            'other' => 'Other (specify)',
        ];
    }
}

if (!function_exists('asset_acquisition_mode_descriptions')) {
    function asset_acquisition_mode_descriptions(): array {
        return [
            'purchase' => 'Asset bought using church funds.',
            'donation' => 'Asset received free of charge from an individual, family, or organization.',
            'grant' => 'Asset acquired through a grant or funding from a donor agency, NGO, government, or partner organization.',
            'gift' => 'Asset presented to the church as a gift during an event or special occasion.',
            'inheritance' => 'Asset received through a will or estate after the owner\'s death.',
            'construction' => 'Asset built or constructed by the church.',
            'manufactured_fabricated' => 'Asset produced or assembled by the church or its members.',
            'transfer' => 'Asset transferred from another church society, circuit, diocese, organization, or department.',
            'exchange_trade_in' => 'Asset acquired by exchanging an old asset, with or without additional payment.',
            'lease' => 'Asset not fully owned yet and obtained through a long-term lease arrangement.',
            'hire_purchase' => 'Asset acquired through instalment payments with ownership transferred after full payment.',
            'capital_project' => 'Asset acquired as part of a specific development or capital project.',
            'sponsorship' => 'Asset provided by a sponsor, corporate body, or philanthropist.',
            'recovered' => 'Asset recovered and returned to the church after being lost or misappropriated.',
            'other' => 'Any acquisition method not covered by the standard options.',
        ];
    }
}

if (!function_exists('asset_is_super_admin')) {
    function asset_is_super_admin(): bool {
        return (isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] === 3)
            || (isset($_SESSION['role_id']) && (int) $_SESSION['role_id'] === 1)
            || (isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin']);
    }
}

if (!function_exists('asset_current_church_id')) {
    function asset_current_church_id(mysqli $conn): ?int {
        $churchId = get_user_church_id($conn);
        return $churchId ? (int) $churchId : null;
    }
}

if (!function_exists('asset_require_permission')) {
    function asset_require_permission(string $permission): void {
        if (!is_logged_in()) {
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }

        if (!asset_is_super_admin() && !has_permission($permission)) {
            http_response_code(403);
            $error403 = __DIR__ . '/../views/errors/403.php';
            if (file_exists($error403)) {
                include $error403;
            } else {
                echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
            }
            exit;
        }
    }
}

if (!function_exists('asset_fetch_departments')) {
    function asset_fetch_departments(mysqli $conn, ?int $churchId, bool $includeInactive = false): array {
        $hasDepartmentCode = asset_column_exists($conn, 'asset_departments', 'department_code');
        $sql = "SELECT id, church_id, name, description, is_active";
        if ($hasDepartmentCode) {
            $sql .= ", department_code";
        }
        $sql .= " FROM asset_departments WHERE 1";
        $params = [];
        $types = '';

        if ($churchId !== null) {
            $sql .= " AND church_id = ?";
            $params[] = $churchId;
            $types .= 'i';
        }

        if (!$includeInactive) {
            $sql .= " AND is_active = 1";
        }

        $sql .= " ORDER BY name ASC";

        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();

        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }
}

if (!function_exists('asset_fetch_groups')) {
    function asset_fetch_groups(mysqli $conn, ?int $churchId, bool $includeInactive = false): array {
        if (!asset_table_exists($conn, 'asset_groups')) {
            return [];
        }

        $sql = "SELECT id, church_id, name, group_code, default_quantity, quantity_rule, description, is_active
                FROM asset_groups
                WHERE 1";
        $params = [];
        $types = '';

        if ($churchId !== null) {
            $sql .= " AND church_id = ?";
            $params[] = $churchId;
            $types .= 'i';
        }

        if (!$includeInactive) {
            $sql .= " AND is_active = 1";
        }

        $sql .= " ORDER BY name ASC";

        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();

        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }
}

if (!function_exists('asset_group_map_by_id')) {
    function asset_group_map_by_id(array $groups): array {
        $map = [];
        foreach ($groups as $group) {
            $map[(int) $group['id']] = $group;
        }
        return $map;
    }
}

if (!function_exists('asset_request_statuses')) {
    function asset_request_statuses(): array {
        return ['pending', 'approved', 'rejected', 'checked_out', 'returned', 'cancelled', 'overdue'];
    }
}

if (!function_exists('asset_request_status_badge_class')) {
    function asset_request_status_badge_class(string $status): string {
        $map = [
            'pending' => 'warning',
            'approved' => 'info',
            'rejected' => 'danger',
            'checked_out' => 'primary',
            'returned' => 'success',
            'cancelled' => 'secondary',
            'overdue' => 'dark',
        ];
        return $map[$status] ?? 'secondary';
    }
}

if (!function_exists('asset_normalize_code_part')) {
    function asset_normalize_code_part(string $value, int $length = 3, string $fallback = 'UNK'): string {
        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
        if ($clean === '') {
            $clean = strtoupper($fallback);
        }
        return substr($clean, 0, max(1, $length));
    }
}

if (!function_exists('asset_generate_code')) {
    function asset_generate_code(
        mysqli $conn,
        int $churchId,
        int $departmentId,
        string $itemGroup = '',
        ?int $assetGroupId = null,
        ?string $purchaseDate = null
    ): string {
        $churchCode = 'FMC';
        $societyCode = 'SOC';
        $stmt = $conn->prepare("SELECT church_code, circuit_code, name FROM churches WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $churchId);
        $stmt->execute();
        $church = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($church) {
            $churchCode = asset_normalize_code_part((string) ($church['church_code'] ?? $church['name'] ?? ''), 3, 'FMC');
            $societyCode = asset_normalize_code_part((string) ($church['circuit_code'] ?? $church['name'] ?? ''), 3, 'SOC');
        }

        $departmentCode = 'GEN';
        $hasDepartmentCode = asset_column_exists($conn, 'asset_departments', 'department_code');
        if ($hasDepartmentCode) {
            $stmt = $conn->prepare("SELECT name, department_code FROM asset_departments WHERE id = ? LIMIT 1");
        } else {
            $stmt = $conn->prepare("SELECT name FROM asset_departments WHERE id = ? LIMIT 1");
        }
        $stmt->bind_param('i', $departmentId);
        $stmt->execute();
        $department = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($department) {
            $departmentCode = asset_normalize_code_part(
                (string) ($department['department_code'] ?? $department['name'] ?? ''),
                3,
                'GEN'
            );
        }

        $groupCode = asset_normalize_code_part($itemGroup, 3, 'GEN');
        if ($assetGroupId !== null && $assetGroupId > 0 && asset_table_exists($conn, 'asset_groups')) {
            $stmt = $conn->prepare("SELECT name, group_code FROM asset_groups WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $assetGroupId);
            $stmt->execute();
            $group = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($group) {
                $groupCode = asset_normalize_code_part((string) ($group['group_code'] ?? $group['name'] ?? ''), 3, 'GEN');
            }
        }

        $yearSource = $purchaseDate ?: date('Y-m-d');
        $year = substr((string) date('y', strtotime($yearSource) ?: time()), 0, 2);
        $prefix = sprintf('%s/%s/%s/', $churchCode, $departmentCode, $groupCode);
        $suffix = sprintf('/%s/%s', $year, $societyCode);

        $stmt = $conn->prepare("SELECT asset_code FROM assets WHERE church_id = ? AND asset_code LIKE ? ORDER BY id DESC");
        $like = $prefix . '%' . $suffix;
        $stmt->bind_param('is', $churchId, $like);
        $stmt->execute();
        $res = $stmt->get_result();
        $maxSeq = 0;
        while ($row = $res->fetch_assoc()) {
            $code = (string) ($row['asset_code'] ?? '');
            if (preg_match('#^' . preg_quote($prefix, '#') . '([0-9]+)' . preg_quote($suffix, '#') . '$#', $code, $m)) {
                $maxSeq = max($maxSeq, (int) $m[1]);
            }
        }
        $stmt->close();

        $nextSeq = $maxSeq + 1;
        return sprintf('%s%s%s', $prefix, str_pad((string) $nextSeq, 3, '0', STR_PAD_LEFT), $suffix);
    }
}

if (!function_exists('asset_scope_sql')) {
    function asset_scope_sql(?int $churchId, string $alias = 'a'): array {
        if (asset_is_super_admin() && $churchId === null) {
            return ['clause' => '', 'types' => '', 'params' => []];
        }

        return [
            'clause' => " AND {$alias}.church_id = ?",
            'types' => 'i',
            'params' => [(int) $churchId],
        ];
    }
}

if (!function_exists('asset_table_exists')) {
    function asset_table_exists(mysqli $conn, string $table): bool {
        static $cache = [];
        $key = 'tbl:' . strtolower($table);
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $stmt = $conn->prepare(
            "SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
        );
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $exists = ((int) ($stmt->get_result()->fetch_row()[0] ?? 0)) > 0;
        $stmt->close();
        $cache[$key] = $exists;
        return $exists;
    }
}

if (!function_exists('asset_column_exists')) {
    function asset_column_exists(mysqli $conn, string $table, string $column): bool {
        static $cache = [];
        $key = 'col:' . strtolower($table) . '.' . strtolower($column);
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $stmt = $conn->prepare(
            "SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $exists = ((int) ($stmt->get_result()->fetch_row()[0] ?? 0)) > 0;
        $stmt->close();
        $cache[$key] = $exists;
        return $exists;
    }
}

if (!function_exists('asset_lifecycle_options')) {
    function asset_lifecycle_options(): array {
        return [
            'requested',
            'approved',
            'procured',
            'in_use',
            'under_maintenance',
            'retired',
            'disposed',
        ];
    }
}

if (!function_exists('asset_lifecycle_label')) {
    function asset_lifecycle_label(string $value): string {
        $map = [
            'requested' => 'Requested',
            'approved' => 'Approved',
            'procured' => 'Procured',
            'in_use' => 'In Use',
            'under_maintenance' => 'Under Maintenance',
            'retired' => 'Retired',
            'disposed' => 'Disposed',
        ];
        return $map[$value] ?? ucfirst(str_replace('_', ' ', $value));
    }
}

if (!function_exists('asset_condition_badge_class')) {
    function asset_condition_badge_class(string $condition): string {
        $map = [
            'New' => 'success',
            'Good' => 'primary',
            'Fair' => 'warning',
            'Poor' => 'danger',
            'Under Maintenance' => 'info',
            'Damaged' => 'danger',
            'Obsolete' => 'dark',
            'Condemned' => 'dark',
            'Disposed' => 'secondary',
        ];
        return $map[$condition] ?? 'secondary';
    }
}

if (!function_exists('asset_lifecycle_badge_class')) {
    function asset_lifecycle_badge_class(string $lifecycle): string {
        $map = [
            'requested' => 'warning',
            'approved' => 'primary',
            'procured' => 'info',
            'in_use' => 'success',
            'under_maintenance' => 'warning',
            'retired' => 'dark',
            'disposed' => 'secondary',
        ];
        return $map[$lifecycle] ?? 'secondary';
    }
}

if (!function_exists('asset_default_lifecycle')) {
    function asset_default_lifecycle(string $status = 'active', string $condition = 'Good'): string {
        if ($status === 'disposed' || $condition === 'Disposed') {
            return 'disposed';
        }
        if ($condition === 'Under Maintenance') {
            return 'under_maintenance';
        }
        return 'in_use';
    }
}

if (!function_exists('asset_allowed_lifecycle_transitions')) {
    function asset_allowed_lifecycle_transitions(): array {
        return [
            'requested' => ['approved', 'disposed'],
            'approved' => ['procured', 'disposed'],
            'procured' => ['in_use', 'disposed'],
            'in_use' => ['under_maintenance', 'retired', 'disposed'],
            'under_maintenance' => ['in_use', 'retired', 'disposed'],
            'retired' => ['disposed'],
            'disposed' => [],
        ];
    }
}

if (!function_exists('asset_validate_lifecycle_transition')) {
    function asset_validate_lifecycle_transition(string $from, string $to): bool {
        if ($from === '' || $from === $to) {
            return true;
        }
        $allowed = asset_allowed_lifecycle_transitions();
        if (!isset($allowed[$from])) {
            return false;
        }
        return in_array($to, $allowed[$from], true);
    }
}

if (!function_exists('asset_can_use_lifecycle')) {
    function asset_can_use_lifecycle(mysqli $conn): bool {
        return asset_column_exists($conn, 'assets', 'lifecycle_status');
    }
}

if (!function_exists('asset_can_use_groups')) {
    function asset_can_use_groups(mysqli $conn): bool {
        return asset_table_exists($conn, 'asset_groups') && asset_column_exists($conn, 'assets', 'asset_group_id');
    }
}

if (!function_exists('asset_can_use_maintenance_fields')) {
    function asset_can_use_maintenance_fields(mysqli $conn): bool {
        return asset_column_exists($conn, 'assets', 'next_maintenance_date')
            && asset_column_exists($conn, 'assets', 'last_maintenance_date')
            && asset_column_exists($conn, 'assets', 'warranty_expiry_date');
    }
}

if (!function_exists('asset_document_categories')) {
    function asset_document_categories(): array {
        return [
            'invoice' => 'Invoice',
            'warranty' => 'Warranty',
            'service' => 'Service',
            'manual' => 'Manual',
            'photo' => 'Photo',
            'other' => 'Other',
        ];
    }
}

if (!function_exists('asset_use_requests_available')) {
    function asset_use_requests_available(mysqli $conn): bool {
        return asset_table_exists($conn, 'asset_use_requests');
    }
}

if (!function_exists('asset_document_allowed_mimes')) {
    function asset_document_allowed_mimes(): array {
        return [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
        ];
    }
}

if (!function_exists('asset_documents_upload_dir')) {
    function asset_documents_upload_dir(): string {
        return dirname(__DIR__) . '/uploads/assets_documents';
    }
}

if (!function_exists('asset_ensure_documents_dir')) {
    function asset_ensure_documents_dir(): bool {
        $dir = asset_documents_upload_dir();
        if (!is_dir($dir)) {
            return mkdir($dir, 0775, true);
        }
        return true;
    }
}

if (!function_exists('asset_user_can_approve_requests')) {
    function asset_user_can_approve_requests(): bool {
        return asset_is_super_admin() || has_permission('approve_asset_request');
    }
}

if (!function_exists('asset_user_can_view_use_requests')) {
    function asset_user_can_view_use_requests(): bool {
        return asset_is_super_admin() || has_permission('view_asset_requests') || has_permission('approve_asset_use_request');
    }
}

if (!function_exists('asset_user_can_manage_groups')) {
    function asset_user_can_manage_groups(): bool {
        return asset_is_super_admin() || has_permission('manage_asset_groups');
    }
}

if (!function_exists('asset_use_request_actor')) {
    function asset_use_request_actor(mysqli $conn): array {
        $name = (string) ($_SESSION['name'] ?? $_SESSION['member_name'] ?? 'Requester');
        $phone = null;
        $memberId = isset($_SESSION['member_id']) ? (int) $_SESSION['member_id'] : null;
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

        if ($memberId) {
            $stmt = $conn->prepare('SELECT CONCAT_WS(" ", first_name, middle_name, last_name) AS full_name, phone FROM members WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $memberId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $name = trim((string) ($row['full_name'] ?? $name)) !== '' ? trim((string) $row['full_name']) : $name;
                $phone = (string) ($row['phone'] ?? '');
            }
        } elseif ($userId) {
            $stmt = $conn->prepare('SELECT name, phone FROM users WHERE id = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $name = trim((string) ($row['name'] ?? $name)) !== '' ? trim((string) $row['name']) : $name;
                    $phone = (string) ($row['phone'] ?? '');
                }
            }
        }

        return [
            'user_id' => $userId,
            'member_id' => $memberId,
            'name' => $name,
            'phone' => $phone,
        ];
    }
}

if (!function_exists('asset_parse_serial_numbers')) {
    function asset_parse_serial_numbers(string $input): array {
        $parts = preg_split('/[\r\n,;]+/', $input) ?: [];
        $seen = [];
        $rows = [];
        foreach ($parts as $part) {
            $serial = trim($part);
            if ($serial === '') {
                continue;
            }
            $key = strtolower($serial);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $rows[] = $serial;
        }
        return $rows;
    }
}

if (!function_exists('asset_fetch_serial_numbers')) {
    function asset_fetch_serial_numbers(mysqli $conn, int $assetId): array {
        if (!asset_table_exists($conn, 'asset_serial_numbers')) {
            return [];
        }

        $stmt = $conn->prepare('SELECT serial_number FROM asset_serial_numbers WHERE asset_id = ? ORDER BY serial_number ASC');
        $stmt->bind_param('i', $assetId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = (string) $row['serial_number'];
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('asset_sync_serial_numbers')) {
    function asset_sync_serial_numbers(mysqli $conn, int $churchId, int $assetId, array $serials): array {
        if (!asset_table_exists($conn, 'asset_serial_numbers')) {
            return ['ok' => true, 'message' => 'serial table unavailable'];
        }

        foreach ($serials as $serial) {
            $stmt = $conn->prepare('SELECT asset_id FROM asset_serial_numbers WHERE church_id = ? AND serial_number = ? AND asset_id <> ? LIMIT 1');
            $stmt->bind_param('isi', $churchId, $serial, $assetId);
            $stmt->execute();
            $dup = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($dup) {
                return ['ok' => false, 'message' => 'Serial number already assigned: ' . $serial];
            }
        }

        $stmt = $conn->prepare('DELETE FROM asset_serial_numbers WHERE asset_id = ?');
        $stmt->bind_param('i', $assetId);
        $stmt->execute();
        $stmt->close();

        if (empty($serials)) {
            return ['ok' => true, 'message' => 'cleared'];
        }

        $stmt = $conn->prepare('INSERT INTO asset_serial_numbers (church_id, asset_id, serial_number) VALUES (?, ?, ?)');
        foreach ($serials as $serial) {
            $stmt->bind_param('iis', $churchId, $assetId, $serial);
            $stmt->execute();
        }
        $stmt->close();

        return ['ok' => true, 'message' => 'saved'];
    }
}

if (!function_exists('asset_create_approval_request')) {
    function asset_create_approval_request(
        mysqli $conn,
        int $churchId,
        int $assetId,
        string $requestType,
        array $payload
    ): int {
        $payloadJson = json_encode($payload);
        $requestedBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $stmt = $conn->prepare(
            'INSERT INTO asset_approval_requests (church_id, asset_id, request_type, payload_json, requested_by, status) VALUES (?, ?, ?, ?, ?, "pending")'
        );
        $stmt->bind_param('iissi', $churchId, $assetId, $requestType, $payloadJson, $requestedBy);
        $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();
        return $id;
    }
}

if (!function_exists('asset_log_action')) {
    function asset_log_action(
        string $action,
        string $entityType,
        ?int $entityId,
        array $payload = [],
        array $before = [],
        array $after = []
    ): void {
        log_activity($action, $entityType, $entityId, json_encode($payload));

        if (!isset($GLOBALS['conn']) || !($GLOBALS['conn'] instanceof mysqli)) {
            return;
        }
        $conn = $GLOBALS['conn'];

        if (!asset_table_exists($conn, 'asset_audit_log')) {
            return;
        }

        $assetId = null;
        if (isset($payload['asset_id']) && (int) $payload['asset_id'] > 0) {
            $assetId = (int) $payload['asset_id'];
        } elseif ($entityType === 'asset' && $entityId !== null) {
            $assetId = (int) $entityId;
        }

        $churchId = isset($payload['church_id']) && (int) $payload['church_id'] > 0 ? (int) $payload['church_id'] : null;
        if ($churchId === null && $assetId !== null) {
            $stmtChurch = $conn->prepare('SELECT church_id FROM assets WHERE id = ? LIMIT 1');
            $stmtChurch->bind_param('i', $assetId);
            $stmtChurch->execute();
            $churchId = (int) (($stmtChurch->get_result()->fetch_assoc()['church_id'] ?? 0));
            $stmtChurch->close();
            if ($churchId <= 0) {
                $churchId = null;
            }
        }

        if ($churchId === null) {
            return;
        }

        $performedBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $beforeJson = !empty($before) ? json_encode($before) : null;
        $afterJson = !empty($after) ? json_encode($after) : null;
        $metaJson = !empty($payload) ? json_encode($payload) : null;

        $stmt = $conn->prepare(
            'INSERT INTO asset_audit_log (church_id, asset_id, action, performed_by, before_json, after_json, meta_json) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            error_log('Asset audit prepare failed: ' . $conn->error);
            return;
        }
        $stmt->bind_param(
            'iisisss',
            $churchId,
            $assetId,
            $action,
            $performedBy,
            $beforeJson,
            $afterJson,
            $metaJson
        );
        if (!$stmt->execute()) {
            error_log('Asset audit insert failed: ' . $stmt->error);
        }
        $stmt->close();
    }
}
?>
