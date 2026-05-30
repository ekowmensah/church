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

if (!function_exists('asset_log_action')) {
    function asset_log_action(string $action, string $entityType, ?int $entityId, array $payload = []): void {
        log_activity($action, $entityType, $entityId, json_encode($payload));
    }
}
?>
