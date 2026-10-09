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
        return is_super_admin();
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

if (!function_exists('asset_request_status_label')) {
    function asset_request_status_label(string $status): string {
        $map = [
            'pending' => 'Awaiting Review',
            'approved' => 'Reserved - Awaiting Handover',
            'rejected' => 'Rejected',
            'checked_out' => 'Issued - With Custodian',
            'returned' => 'Returned / Closed',
            'cancelled' => 'Cancelled',
            'overdue' => 'Issued - Overdue',
        ];
        return $map[$status] ?? ucwords(str_replace('_', ' ', $status));
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
    /** Return the numeric sequence only when the code belongs to the category. */
    function asset_category_sequence_from_code(string $code, string $categoryCode): int {
        $parts = explode('/', trim($code));
        if (count($parts) < 6
            || asset_normalize_code_part((string) ($parts[2] ?? ''), 3, 'GEN') !== $categoryCode
            || !ctype_digit((string) ($parts[3] ?? ''))) {
            return 0;
        }
        return (int) $parts[3];
    }

    /**
     * Allocate one permanent sequence per church/category. Department moves
     * and acquisition years never restart or alter this counter.
     */
    function asset_next_category_sequence(
        mysqli $conn,
        int $churchId,
        ?int $assetGroupId,
        string $categoryCode
    ): int {
        if ($assetGroupId !== null && $assetGroupId > 0
            && asset_table_exists($conn, 'asset_category_number_sequences')) {
            $stmt = $conn->prepare(
                'SELECT id FROM asset_groups WHERE id = ? AND church_id = ? LIMIT 1'
            );
            $stmt->bind_param('ii', $assetGroupId, $churchId);
            $stmt->execute();
            $validCategory = (bool) $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$validCategory) {
                throw new RuntimeException('The selected asset category does not belong to this church.');
            }

            $stmt = $conn->prepare(
                'INSERT INTO asset_category_number_sequences
                    (church_id, asset_group_id, last_sequence)
                 VALUES (?, ?, LAST_INSERT_ID(1))
                 ON DUPLICATE KEY UPDATE
                    last_sequence = LAST_INSERT_ID(last_sequence + 1),
                    updated_at = CURRENT_TIMESTAMP'
            );
            $stmt->bind_param('ii', $churchId, $assetGroupId);
            $stmt->execute();
            $stmt->close();
            $sequence = (int) $conn->insert_id;
            if ($sequence < 1) {
                $sequence = (int) $conn->query('SELECT LAST_INSERT_ID()')->fetch_row()[0];
            }
            if ($sequence < 1) {
                throw new RuntimeException('Unable to allocate the next asset category number.');
            }
            return $sequence;
        }

        // Compatibility fallback before Phase 0104: determine the maximum by
        // relational category across every department and acquisition year.
        $maxSequence = 0;
        if ($assetGroupId !== null && $assetGroupId > 0) {
            $stmt = $conn->prepare(
                'SELECT asset_code AS code_value FROM assets
                  WHERE church_id = ? AND asset_group_id = ?'
            );
            $stmt->bind_param('ii', $churchId, $assetGroupId);
        } else {
            $stmt = $conn->prepare('SELECT asset_code AS code_value FROM assets WHERE church_id = ?');
            $stmt->bind_param('i', $churchId);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $maxSequence = max(
                $maxSequence,
                asset_category_sequence_from_code((string) ($row['code_value'] ?? ''), $categoryCode)
            );
        }
        $stmt->close();

        if (asset_table_exists($conn, 'asset_items')) {
            if ($assetGroupId !== null && $assetGroupId > 0) {
                $stmt = $conn->prepare(
                    'SELECT item.item_number AS code_value
                       FROM asset_items item
                       JOIN assets asset ON asset.id = item.asset_id
                      WHERE item.church_id = ? AND asset.asset_group_id = ?'
                );
                $stmt->bind_param('ii', $churchId, $assetGroupId);
            } else {
                $stmt = $conn->prepare('SELECT item_number AS code_value FROM asset_items WHERE church_id = ?');
                $stmt->bind_param('i', $churchId);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $maxSequence = max(
                    $maxSequence,
                    asset_category_sequence_from_code((string) ($row['code_value'] ?? ''), $categoryCode)
                );
            }
            $stmt->close();
        }

        return $maxSequence + 1;
    }

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
            $stmt = $conn->prepare("SELECT name, group_code FROM asset_groups WHERE id = ? AND church_id = ? LIMIT 1");
            $stmt->bind_param('ii', $assetGroupId, $churchId);
            $stmt->execute();
            $group = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($group) {
                $groupCode = asset_normalize_code_part((string) ($group['group_code'] ?? $group['name'] ?? ''), 3, 'GEN');
            }
        }

        $yearSource = $purchaseDate ?: date('Y-m-d');
        $year = substr((string) date('y', strtotime($yearSource) ?: time()), 0, 2);
        $nextSeq = asset_next_category_sequence($conn, $churchId, $assetGroupId, $groupCode);
        return sprintf(
            '%s/%s/%s/%s/%s/%s',
            $churchCode,
            $departmentCode,
            $groupCode,
            str_pad((string) $nextSeq, 3, '0', STR_PAD_LEFT),
            $year,
            $societyCode
        );
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

if (!function_exists('asset_item_is_disposed')) {
    /**
     * Lifecycle state takes precedence over custody state. A disposed asset
     * may retain the legacy custody value "available", but it is never
     * operationally available for reservation, issue or transfer.
     */
    function asset_item_is_disposed(array $asset): bool {
        return strtolower((string) ($asset['status'] ?? '')) === 'disposed'
            || strtolower((string) ($asset['lifecycle_status'] ?? '')) === 'disposed'
            || strcasecmp((string) ($asset['condition_status'] ?? ''), 'Disposed') === 0;
    }
}

if (!function_exists('asset_operational_state')) {
    /** @return array{key:string,label:string,badge:string,icon:string} */
    function asset_operational_state(array $asset): array {
        if (asset_item_is_disposed($asset)) {
            return [
                'key' => 'disposed',
                'label' => 'Disposed',
                'badge' => 'secondary',
                'icon' => 'fas fa-ban',
            ];
        }

        $lifecycle = strtolower((string) ($asset['lifecycle_status'] ?? ''));
        $condition = (string) ($asset['condition_status'] ?? '');
        if ($lifecycle === 'retired') {
            return ['key' => 'retired', 'label' => 'Retired', 'badge' => 'dark', 'icon' => 'fas fa-archive'];
        }
        if ($lifecycle === 'under_maintenance' || $condition === 'Under Maintenance') {
            return ['key' => 'under_maintenance', 'label' => 'Under Maintenance', 'badge' => 'warning', 'icon' => 'fas fa-tools'];
        }

        $custody = strtolower((string) ($asset['custody_status'] ?? 'available'));
        $states = [
            'reserved' => ['key' => 'reserved', 'label' => 'Reserved', 'badge' => 'warning', 'icon' => 'fas fa-clock'],
            'issued' => ['key' => 'issued', 'label' => 'Issued', 'badge' => 'primary', 'icon' => 'fas fa-hand-holding'],
        ];
        return $states[$custody]
            ?? ['key' => 'available', 'label' => 'Available', 'badge' => 'success', 'icon' => 'fas fa-check-circle'];
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

if (!function_exists('asset_item_tracking_available')) {
    function asset_item_tracking_available(mysqli $conn): bool {
        return asset_table_exists($conn, 'asset_items');
    }
}

if (!function_exists('asset_request_lines_available')) {
    function asset_request_lines_available(mysqli $conn): bool {
        return asset_table_exists($conn, 'asset_use_request_items')
            && asset_column_exists($conn, 'asset_use_requests', 'request_model_version');
    }
}

if (!function_exists('asset_fetch_physical_items')) {
    function asset_fetch_physical_items(mysqli $conn, int $assetId, bool $activeOnly = false): array {
        if (!asset_item_tracking_available($conn)) {
            return [];
        }
        $sql = 'SELECT item.*, department.name AS department_name
                FROM asset_items item
                LEFT JOIN asset_departments department ON department.id = item.department_id
                WHERE item.asset_id = ?';
        if ($activeOnly) {
            $sql .= " AND item.status = 'active'";
        }
        $sql .= ' ORDER BY item.item_number, item.id';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $assetId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('asset_replace_department_segment')) {
    function asset_replace_department_segment(string $itemNumber, string $departmentCode): string {
        $parts = explode('/', $itemNumber);
        if (count($parts) < 6) {
            throw new InvalidArgumentException('The item number does not contain the expected department segment.');
        }
        $parts[1] = asset_normalize_code_part($departmentCode, 3, 'GEN');
        return implode('/', $parts);
    }
}

if (!function_exists('asset_assert_item_number_available')) {
    /**
     * Refuse a movement that would give two assets the same identity.
     * Movement keeps every number segment except the department segment, so a
     * collision must be reviewed instead of silently renumbering the asset.
     */
    function asset_assert_item_number_available(
        mysqli $conn,
        int $churchId,
        string $itemNumber,
        int $excludeItemId = 0
    ): void {
        $stmt = $conn->prepare(
            'SELECT id FROM asset_items
              WHERE church_id = ? AND item_number = ? AND id <> ? LIMIT 1'
        );
        $stmt->bind_param('isi', $churchId, $itemNumber, $excludeItemId);
        $stmt->execute();
        $collision = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($collision) {
            throw new RuntimeException(
                'The destination would duplicate asset number ' . $itemNumber
                . '. Resolve the destination numbering conflict before approving this movement.'
            );
        }
    }
}

if (!function_exists('asset_assert_asset_code_available')) {
    /**
     * Keep the register identity unique when a department movement rewrites
     * the department segment of a parent asset code.
     */
    function asset_assert_asset_code_available(
        mysqli $conn,
        int $churchId,
        string $assetCode,
        int $excludeAssetId = 0
    ): void {
        $stmt = $conn->prepare(
            'SELECT id FROM assets
              WHERE church_id = ? AND asset_code = ? AND id <> ? LIMIT 1'
        );
        $stmt->bind_param('isi', $churchId, $assetCode, $excludeAssetId);
        $stmt->execute();
        $collision = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($collision) {
            throw new RuntimeException(
                'The destination would duplicate asset code ' . $assetCode
                . '. Resolve the destination numbering conflict before completing this movement.'
            );
        }
    }
}

if (!function_exists('asset_move_parent_to_department')) {
    /**
     * Move a legacy/category-level asset and rewrite its department segment
     * using the same canonical convention used for asset movements.
     * Callers are expected to own the surrounding transaction.
     *
     * @return array{old_asset_code:string,new_asset_code:string,department_id:int}
     */
    function asset_move_parent_to_department(mysqli $conn, int $assetId, int $departmentId): array {
        $stmt = $conn->prepare(
            'SELECT id, church_id, asset_code FROM assets WHERE id = ? FOR UPDATE'
        );
        $stmt->bind_param('i', $assetId);
        $stmt->execute();
        $asset = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$asset) {
            throw new RuntimeException('Asset no longer exists.');
        }

        $churchId = (int) $asset['church_id'];
        $stmt = $conn->prepare(
            'SELECT name, department_code FROM asset_departments
              WHERE id = ? AND church_id = ? LIMIT 1'
        );
        $stmt->bind_param('ii', $departmentId, $churchId);
        $stmt->execute();
        $department = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$department) {
            throw new RuntimeException('Destination department not found for this church.');
        }

        $oldAssetCode = (string) $asset['asset_code'];
        $newAssetCode = asset_replace_department_segment(
            $oldAssetCode,
            (string) ($department['department_code'] ?: $department['name'])
        );
        asset_assert_asset_code_available($conn, $churchId, $newAssetCode, $assetId);

        $stmt = $conn->prepare('UPDATE assets SET department_id = ?, asset_code = ? WHERE id = ?');
        $stmt->bind_param('isi', $departmentId, $newAssetCode, $assetId);
        $stmt->execute();
        $stmt->close();

        return [
            'old_asset_code' => $oldAssetCode,
            'new_asset_code' => $newAssetCode,
            'department_id' => $departmentId,
        ];
    }
}

if (!function_exists('asset_sync_parent_from_items')) {
    /**
     * Synchronize a legacy parent record from its active assets. When every
     * asset is in one department, the parent code follows that department as
     * well. A split legacy record keeps its existing code and receives a NULL
     * department because no single destination represents it.
     *
     * @return array{old_asset_code:string,new_asset_code:string,department_id:?int}
     */
    function asset_sync_parent_from_items(mysqli $conn, int $assetId): array {
        if (!asset_item_tracking_available($conn)) {
            return ['old_asset_code' => '', 'new_asset_code' => '', 'department_id' => null];
        }
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS active_count, COUNT(DISTINCT department_id) AS departments,
                    MIN(department_id) AS department_id
             FROM asset_items WHERE asset_id = ? AND status = 'active'"
        );
        $stmt->bind_param('i', $assetId);
        $stmt->execute();
        $summary = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $quantity = (int) ($summary['active_count'] ?? 0);
        $departmentId = (int) ($summary['departments'] ?? 0) === 1
            ? (int) ($summary['department_id'] ?? 0)
            : null;
        $status = $quantity > 0 ? 'active' : 'disposed';

        $stmt = $conn->prepare('SELECT church_id, asset_code FROM assets WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $assetId);
        $stmt->execute();
        $parent = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$parent) {
            throw new RuntimeException('Asset parent no longer exists.');
        }

        $oldAssetCode = (string) $parent['asset_code'];
        $newAssetCode = $oldAssetCode;
        if ($departmentId !== null && $departmentId > 0) {
            $stmt = $conn->prepare(
                'SELECT name, department_code FROM asset_departments
                  WHERE id = ? AND church_id = ? LIMIT 1'
            );
            $churchId = (int) $parent['church_id'];
            $stmt->bind_param('ii', $departmentId, $churchId);
            $stmt->execute();
            $department = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$department) {
                throw new RuntimeException('The asset department is not valid for this church.');
            }
            $newAssetCode = asset_replace_department_segment(
                $oldAssetCode,
                (string) ($department['department_code'] ?: $department['name'])
            );
            asset_assert_asset_code_available($conn, $churchId, $newAssetCode, $assetId);
        }

        $stmt = $conn->prepare(
            'UPDATE assets SET quantity = ?, department_id = ?, status = ?, asset_code = ? WHERE id = ?'
        );
        $stmt->bind_param('iissi', $quantity, $departmentId, $status, $newAssetCode, $assetId);
        $stmt->execute();
        $stmt->close();

        return [
            'old_asset_code' => $oldAssetCode,
            'new_asset_code' => $newAssetCode,
            'department_id' => $departmentId,
        ];
    }
}

if (!function_exists('asset_transfer_registered_item')) {
    /**
     * Transfer one accountable asset while preserving its category sequence.
     * Callers must own the surrounding transaction.
     *
     * @return array{movement_id:int,from_department_id:?int,to_department_id:int,old_item_number:string,new_item_number:string,old_asset_code:string,new_asset_code:string}
     */
    function asset_transfer_registered_item(
        mysqli $conn,
        int $assetId,
        int $assetItemId,
        int $toDepartmentId,
        ?int $actorUserId,
        string $notes,
        ?int $approvalRequestId = null,
        ?int $expectedFromDepartmentId = null
    ): array {
        $notes = trim($notes);
        if ($notes === '') {
            throw new InvalidArgumentException('A transfer reason is required.');
        }

        $stmt = $conn->prepare(
            'SELECT item.*, asset.church_id
               FROM asset_items item
               JOIN assets asset ON asset.id = item.asset_id
              WHERE item.id = ? AND item.asset_id = ?
              FOR UPDATE'
        );
        $stmt->bind_param('ii', $assetItemId, $assetId);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$item) {
            throw new RuntimeException('The asset no longer exists.');
        }
        if ((string) ($item['status'] ?? '') !== 'active'
            || (string) ($item['custody_status'] ?? 'available') !== 'available'
            || in_array((string) ($item['lifecycle_status'] ?? ''), ['under_maintenance', 'retired', 'disposed'], true)
            || asset_item_is_disposed($item)) {
            throw new RuntimeException(
                'Only an active, available asset can be transferred. Complete custody, maintenance or retirement workflows first.'
            );
        }

        $churchId = (int) $item['church_id'];
        $fromDepartmentId = (int) ($item['department_id'] ?? 0) ?: null;
        if ($expectedFromDepartmentId !== null
            && (int) $fromDepartmentId !== $expectedFromDepartmentId) {
            throw new RuntimeException(
                'The asset department changed after this transfer was requested. Submit a new transfer request.'
            );
        }
        if ($toDepartmentId < 1 || $toDepartmentId === (int) $fromDepartmentId) {
            throw new InvalidArgumentException('Destination department must be different from the current department.');
        }

        $stmt = $conn->prepare(
            'SELECT name, department_code FROM asset_departments
              WHERE id = ? AND church_id = ? LIMIT 1'
        );
        $stmt->bind_param('ii', $toDepartmentId, $churchId);
        $stmt->execute();
        $destination = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$destination) {
            throw new RuntimeException('Destination department not found for this church.');
        }

        $oldItemNumber = (string) $item['item_number'];
        $newItemNumber = asset_replace_department_segment(
            $oldItemNumber,
            (string) ($destination['department_code'] ?: $destination['name'])
        );
        $oldParts = explode('/', $oldItemNumber);
        $newParts = explode('/', $newItemNumber);
        foreach ($oldParts as $index => $part) {
            if ($index !== 1 && (!array_key_exists($index, $newParts) || $newParts[$index] !== $part)) {
                throw new RuntimeException('Transfer numbering attempted to change a non-department asset-code segment.');
            }
        }
        asset_assert_item_number_available($conn, $churchId, $newItemNumber, $assetItemId);

        $stmt = $conn->prepare(
            'UPDATE asset_items SET department_id = ?, item_number = ?
              WHERE id = ? AND asset_id = ?'
        );
        $stmt->bind_param('isii', $toDepartmentId, $newItemNumber, $assetItemId, $assetId);
        $stmt->execute();
        $stmt->close();

        $codeChange = asset_sync_parent_from_items($conn, $assetId);
        if (asset_column_exists($conn, 'asset_movements', 'old_item_number')
            && asset_column_exists($conn, 'asset_movements', 'new_item_number')
            && asset_column_exists($conn, 'asset_movements', 'approval_request_id')) {
            $stmt = $conn->prepare(
                'INSERT INTO asset_movements
                    (asset_id, asset_item_id, from_department_id, to_department_id,
                     moved_by, notes, old_item_number, new_item_number,
                     approval_request_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'iiiiisssi',
                $assetId,
                $assetItemId,
                $fromDepartmentId,
                $toDepartmentId,
                $actorUserId,
                $notes,
                $oldItemNumber,
                $newItemNumber,
                $approvalRequestId
            );
        } else {
            $stmt = $conn->prepare(
                'INSERT INTO asset_movements
                    (asset_id, asset_item_id, from_department_id, to_department_id,
                     moved_by, notes)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'iiiiis',
                $assetId,
                $assetItemId,
                $fromDepartmentId,
                $toDepartmentId,
                $actorUserId,
                $notes
            );
        }
        $stmt->execute();
        $movementId = (int) $conn->insert_id;
        $stmt->close();

        return [
            'movement_id' => $movementId,
            'from_department_id' => $fromDepartmentId,
            'to_department_id' => $toDepartmentId,
            'old_item_number' => $oldItemNumber,
            'new_item_number' => $newItemNumber,
            'old_asset_code' => $codeChange['old_asset_code'],
            'new_asset_code' => $codeChange['new_asset_code'],
        ];
    }
}

if (!function_exists('asset_generate_item_number')) {
    /**
     * Generate the next asset number for an existing legacy parent record.
     * The church, department, group, year and society segments follow the
     * canonical asset format; only the sequence is newly allocated.
     */
    function asset_generate_item_number(mysqli $conn, array $asset, int $departmentId): string {
        $candidate = asset_generate_code(
            $conn,
            (int) $asset['church_id'],
            $departmentId,
            (string) ($asset['item_group'] ?? ''),
            isset($asset['asset_group_id']) ? (int) $asset['asset_group_id'] : null,
            (string) ($asset['purchase_date'] ?? date('Y-m-d'))
        );

        return $candidate;
    }
}

if (!function_exists('asset_fetch_request_lines')) {
    function asset_fetch_request_lines(mysqli $conn, int $requestId): array {
        if (!asset_request_lines_available($conn)) {
            return [];
        }
        $stmt = $conn->prepare(
            'SELECT line.*, asset.asset_code, asset.item_name,
                    asset.asset_group_id AS category_id, item.item_number,
                    item.serial_number, item.condition_status, item.lifecycle_status,
                    category.name AS category_name, category.group_code AS category_code,
                    department.name AS department_name
             FROM asset_use_request_items line
             JOIN assets asset ON asset.id = line.asset_id
             LEFT JOIN asset_items item ON item.id = line.asset_item_id
             LEFT JOIN asset_groups category ON category.id = asset.asset_group_id
             LEFT JOIN asset_departments department ON department.id = item.department_id
             WHERE line.request_id = ? ORDER BY line.id'
        );
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('asset_request_line_audit')) {
    function asset_request_line_audit(
        mysqli $conn,
        int $requestId,
        ?int $requestItemId,
        string $action,
        ?array $before = null,
        ?array $after = null,
        ?int $actorUserId = null
    ): void {
        if (!asset_table_exists($conn, 'asset_use_request_item_audit')) {
            return;
        }
        $beforeJson = $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $afterJson = $after === null ? null : json_encode($after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $stmt = $conn->prepare(
            'INSERT INTO asset_use_request_item_audit
                (request_id, request_item_id, action, before_json, after_json, performed_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iisssi', $requestId, $requestItemId, $action, $beforeJson, $afterJson, $actorUserId);
        $stmt->execute();
        $stmt->close();
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

if (!function_exists('asset_approval_payload_details')) {
    /**
     * Convert retained approval JSON into safe, human-readable evidence.
     * Lookup maps are keyed by "church_id:id" to prevent cross-church labels.
     */
    function asset_approval_payload_details(
        array $request,
        array $departmentNames = [],
        array $itemNumbers = []
    ): array {
        $payload = $request['_payload_data'] ?? json_decode((string) ($request['payload_json'] ?? ''), true);
        if (!is_array($payload)) {
            return [['label' => 'Request details', 'value' => 'Invalid retained payload']];
        }

        $churchId = (int) ($request['church_id'] ?? 0);
        $departmentLabel = static function (int $id) use ($churchId, $departmentNames): string {
            if ($id < 1) return 'Not recorded';
            $name = trim((string) ($departmentNames[$churchId . ':' . $id] ?? ''));
            return $name !== '' ? $name . ' (#' . $id . ')' : 'Department #' . $id;
        };
        $itemLabel = static function () use ($payload, $churchId, $itemNumbers): string {
            $itemId = (int) ($payload['asset_item_id'] ?? 0);
            $number = trim((string) ($payload['item_number'] ?? ''));
            if ($number === '' && $itemId > 0) {
                $number = trim((string) ($itemNumbers[$churchId . ':' . $itemId] ?? ''));
            }
            if ($number !== '') return $number . ($itemId > 0 ? ' (#' . $itemId . ')' : '');
            return $itemId > 0 ? 'Asset #' . $itemId : 'Legacy asset request';
        };

        $type = (string) ($request['request_type'] ?? '');
        $details = [];
        if ($type === 'transfer') {
            $details[] = ['label' => 'Asset', 'value' => $itemLabel()];
            $details[] = [
                'label' => 'Move from',
                'value' => $departmentLabel((int) ($payload['from_department_id'] ?? 0)),
            ];
            $details[] = [
                'label' => 'Destination',
                'value' => $departmentLabel((int) ($payload['to_department_id'] ?? 0)),
            ];
        } elseif ($type === 'dispose') {
            $details[] = ['label' => 'Asset', 'value' => $itemLabel()];
            $details[] = ['label' => 'Requested action', 'value' => 'Dispose item'];
        } elseif ($type === 'status_change') {
            $details[] = ['label' => 'Asset', 'value' => $itemLabel()];
            $details[] = [
                'label' => 'Asset status',
                'value' => ucfirst(str_replace('_', ' ', (string) ($payload['new_status'] ?? 'Not recorded'))),
            ];
            if (isset($payload['new_lifecycle_status'])) {
                $details[] = [
                    'label' => 'Lifecycle',
                    'value' => asset_lifecycle_label((string) $payload['new_lifecycle_status']),
                ];
            }
        } else {
            $details[] = ['label' => 'Request details', 'value' => 'Unsupported retained request type'];
        }

        $note = trim((string) ($payload['note'] ?? ''));
        $details[] = ['label' => 'Reason', 'value' => $note !== '' ? $note : 'No reason provided'];
        return $details;
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
        if (asset_column_exists($conn, 'asset_approval_requests', 'asset_code_snapshot')
            && asset_column_exists($conn, 'asset_approval_requests', 'asset_name_snapshot')) {
            $asset = null;
            $lookup = $conn->prepare('SELECT asset_code, item_name FROM assets WHERE id = ? AND church_id = ? LIMIT 1');
            $lookup->bind_param('ii', $assetId, $churchId);
            $lookup->execute();
            $asset = $lookup->get_result()->fetch_assoc();
            $lookup->close();
            if (!$asset) {
                throw new RuntimeException('The selected asset no longer exists.');
            }
            $assetCode = (string) $asset['asset_code'];
            $assetName = (string) $asset['item_name'];
            $stmt = $conn->prepare(
                'INSERT INTO asset_approval_requests
                    (church_id, asset_id, asset_code_snapshot, asset_name_snapshot,
                     request_type, payload_json, requested_by, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "pending")'
            );
            $stmt->bind_param(
                'iissssi',
                $churchId,
                $assetId,
                $assetCode,
                $assetName,
                $requestType,
                $payloadJson,
                $requestedBy
            );
        } else {
            $stmt = $conn->prepare(
                'INSERT INTO asset_approval_requests (church_id, asset_id, request_type, payload_json, requested_by, status) VALUES (?, ?, ?, ?, ?, "pending")'
            );
            $stmt->bind_param('iissi', $churchId, $assetId, $requestType, $payloadJson, $requestedBy);
        }
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
