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
        $sql = "SELECT id, church_id, name, description, is_active FROM asset_departments WHERE 1";
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

if (!function_exists('asset_generate_code')) {
    function asset_generate_code(mysqli $conn, int $churchId, int $departmentId, string $itemGroup = ''): string {
        $departmentCode = 'AST';
        $stmt = $conn->prepare("SELECT name FROM asset_departments WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $departmentId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $basis = trim($itemGroup) !== '' ? $itemGroup : ((string) ($res['name'] ?? 'AST'));
        $basis = strtoupper(preg_replace('/[^A-Z0-9]/', '', $basis));
        if ($basis !== '') {
            $departmentCode = substr($basis, 0, 3);
        }

        $year = date('Y');
        for ($attempt = 1; $attempt <= 100; $attempt++) {
            $seq = str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            $candidate = sprintf('AST-%s-C%02d-%s-%s', $departmentCode, $churchId, $year, $seq);
            $check = $conn->prepare("SELECT id FROM assets WHERE church_id = ? AND asset_code = ? LIMIT 1");
            $check->bind_param('is', $churchId, $candidate);
            $check->execute();
            $exists = $check->get_result()->num_rows > 0;
            $check->close();
            if (!$exists) {
                return $candidate;
            }
        }

        return sprintf('AST-%s-C%02d-%s-%s', $departmentCode, $churchId, date('Y'), uniqid());
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
