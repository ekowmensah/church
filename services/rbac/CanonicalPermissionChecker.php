<?php
/**
 * Canonical RBAC permission evaluator.
 *
 * hasPermission() remains the compatibility capability check used by menus
 * and older pages. authorize() is the fail-closed resource-level check.
 */
final class PermissionChecker {
    private $conn;
    private $auditLogger;
    private $cache = [];
    private $cacheEnabled = true;
    private $cacheTTL = 300;
    private $schemaCache = [];

    public function __construct($db_connection) {
        $this->conn = $db_connection;
    }

    public function setAuditLogger($auditLogger) {
        $this->auditLogger = $auditLogger;
    }

    public function setCacheEnabled($enabled) {
        $this->cacheEnabled = (bool) $enabled;
    }

    public function clearCache($userId = null) {
        if ($userId === null) {
            $this->cache = [];
            return;
        }
        $prefix = (int) $userId . '|';
        foreach (array_keys($this->cache) as $key) {
            if (strpos($key, $prefix) === 0) unset($this->cache[$key]);
        }
    }

    public function hasPermission($permission, $userId = null, $context = [], $logCheck = false) {
        $userId = (int) ($userId ?: ($_SESSION['user_id'] ?? 0));
        if ($userId < 1) return false;

        $context = is_array($context) ? $context : [];
        $cacheKey = $this->getCacheKey($userId, $permission, $context, false);
        if ($this->cacheEnabled && isset($this->cache[$cacheKey])) {
            $cached = $this->cache[$cacheKey];
            if (time() - $cached['time'] < $this->cacheTTL) return (bool) $cached['result'];
        }

        $decision = $this->decide($permission, $userId, $context, false);
        $this->cacheResult($cacheKey, $decision['allowed']);
        $this->logDecision($userId, $decision, $context, $logCheck);
        return (bool) $decision['allowed'];
    }

    /**
     * Return a fail-closed authorization decision. Contextual permissions
     * must have a configured and valid context when this method is used.
     */
    public function authorize($permission, $userId = null, $context = [], $logCheck = true) {
        $userId = (int) ($userId ?: ($_SESSION['user_id'] ?? 0));
        $context = is_array($context) ? $context : [];
        $decision = $this->decide($permission, $userId, $context, true);
        $this->logDecision($userId, $decision, $context, $logCheck);
        return $decision;
    }

    public function hasAllPermissions($permissions, $userId = null, $context = []) {
        foreach ((array) $permissions as $permission) {
            if (!$this->hasPermission($permission, $userId, $context)) return false;
        }
        return true;
    }

    public function hasAnyPermission($permissions, $userId = null, $context = []) {
        foreach ((array) $permissions as $permission) {
            if ($this->hasPermission($permission, $userId, $context)) return true;
        }
        return false;
    }

    public function getUserPermissions($userId, $includeInherited = true) {
        $userId = (int) $userId;
        if (!$this->isActiveUser($userId)) return [];

        $overrides = [];
        $stmt = $this->conn->prepare(
            'SELECT p.*, up.allowed FROM user_permissions up
             JOIN permissions p ON p.id = up.permission_id
             WHERE up.user_id = ? AND p.is_active = 1' .
             ($this->columnExists('user_permissions', 'is_active') ? ' AND up.is_active = 1' : '') .
             ($this->columnExists('user_permissions', 'expires_at')
                ? ' AND (up.expires_at IS NULL OR up.expires_at > NOW())' : '')
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $overrides[(int) $row['id']] = (int) $row['allowed'];
        }
        $stmt->close();

        $permissions = [];
        $maxDepth = $includeInherited ? 16 : 0;
        $stmt = $this->conn->prepare(
            "WITH RECURSIVE effective_roles AS (
                SELECT role.id, role.parent_id, role.name, 0 AS depth,
                       CAST(CONCAT(',', role.id, ',') AS CHAR(2000)) AS path
                FROM user_roles assignment
                JOIN roles role ON role.id = assignment.role_id
                WHERE assignment.user_id = ? AND assignment.is_active = 1
                  AND (assignment.expires_at IS NULL OR assignment.expires_at > NOW())
                  AND role.is_active = 1
                UNION ALL
                SELECT parent.id, parent.parent_id, parent.name, child.depth + 1,
                       CONCAT(child.path, parent.id, ',')
                FROM roles parent
                JOIN effective_roles child ON child.parent_id = parent.id
                WHERE parent.is_active = 1 AND child.depth < ?
                  AND LOCATE(CONCAT(',', parent.id, ','), child.path) = 0
            )
            SELECT permission.*, effective_roles.name AS role_name, effective_roles.depth
            FROM effective_roles
            JOIN role_permissions grant_row ON grant_row.role_id = effective_roles.id
            JOIN permissions permission ON permission.id = grant_row.permission_id
            WHERE grant_row.is_active = 1 AND permission.is_active = 1
              AND (grant_row.expires_at IS NULL OR grant_row.expires_at > NOW())
            ORDER BY effective_roles.depth, permission.name"
        );
        $stmt->bind_param('ii', $userId, $maxDepth);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $id = (int) $row['id'];
            if (($overrides[$id] ?? 1) === 0 || isset($permissions[$id])) continue;
            $row['source'] = (int) $row['depth'] > 0 ? 'inherited_role' : 'role';
            $permissions[$id] = $row;
        }
        $stmt->close();

        if (!empty($overrides)) {
            $stmt = $this->conn->prepare(
                'SELECT permission.* FROM user_permissions override_row
                 JOIN permissions permission ON permission.id = override_row.permission_id
                 WHERE override_row.user_id = ? AND override_row.allowed = 1
                   AND permission.is_active = 1' .
                 ($this->columnExists('user_permissions', 'is_active')
                    ? ' AND override_row.is_active = 1' : '') .
                 ($this->columnExists('user_permissions', 'expires_at')
                    ? ' AND (override_row.expires_at IS NULL OR override_row.expires_at > NOW())' : '')
            );
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $row['source'] = 'user_override';
                $permissions[(int) $row['id']] = $row;
            }
            $stmt->close();
        }

        return array_values($permissions);
    }

    public function getUserRoles($userId) {
        $userId = (int) $userId;
        $stmt = $this->conn->prepare(
            "SELECT role.*, assignment.is_primary, assignment.assigned_at, assignment.expires_at
             FROM user_roles assignment
             JOIN roles role ON role.id = assignment.role_id
             JOIN users account ON account.id = assignment.user_id
             WHERE assignment.user_id = ? AND account.status = 'active'
               AND assignment.is_active = 1 AND role.is_active = 1
               AND (assignment.expires_at IS NULL OR assignment.expires_at > NOW())
             ORDER BY assignment.is_primary DESC, role.level, role.name"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $roles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $roles;
    }

    private function decide($permission, $userId, array $context, $strictContext) {
        $label = is_scalar($permission) ? (string) $permission : '';
        if ($userId < 1 || !$this->isActiveUser($userId)) {
            return $this->decision(false, 'inactive_or_missing_account', 'account', null, $label);
        }

        if ($label === '*') {
            $allowed = $this->isSuperAdmin($userId);
            return $this->decision($allowed, $allowed ? 'active_super_admin' : 'not_super_admin', 'role', null, '*');
        }

        $permissionRow = $this->resolvePermission($permission);
        if (!$permissionRow) {
            return $this->decision(false, 'permission_not_found_or_inactive', 'catalog', null, $label);
        }

        $permissionId = (int) $permissionRow['id'];
        $permissionName = (string) $permissionRow['name'];
        if ($this->isSuperAdmin($userId)) {
            return $this->decision(true, 'active_super_admin', 'role', $permissionId, $permissionName);
        }

        $override = $this->checkUserOverride($userId, $permissionId);
        if ($override === false) {
            return $this->decision(false, 'explicit_user_denial', 'user_override', $permissionId, $permissionName);
        }

        $source = $override === true ? 'user_override' : $this->rolePermissionSource($userId, $permissionId);
        if ($source === null) {
            return $this->decision(false, 'permission_not_granted', 'role', $permissionId, $permissionName);
        }

        if (($strictContext || !empty($context)) && (int) $permissionRow['requires_context'] === 1) {
            $contextDecision = $this->checkContextPermission($userId, $permissionRow, $context);
            if (!$contextDecision['allowed']) {
                return $this->decision(false, $contextDecision['reason'], 'context', $permissionId, $permissionName);
            }
        }

        return $this->decision(true, 'permission_granted', $source, $permissionId, $permissionName);
    }

    private function decision($allowed, $reason, $source, $permissionId, $permissionName) {
        return [
            'allowed' => (bool) $allowed,
            'reason' => (string) $reason,
            'source' => (string) $source,
            'permission_id' => $permissionId === null ? null : (int) $permissionId,
            'permission_name' => (string) $permissionName,
        ];
    }

    private function resolvePermission($permission) {
        if (is_numeric($permission)) {
            $id = (int) $permission;
            $stmt = $this->conn->prepare('SELECT * FROM permissions WHERE id = ? AND is_active = 1 LIMIT 1');
            $stmt->bind_param('i', $id);
        } elseif ($this->columnExists('permissions', 'permission_code')) {
            $name = trim((string) $permission);
            $stmt = $this->conn->prepare(
                'SELECT * FROM permissions WHERE (name = ? OR permission_code = ?)
                 AND is_active = 1 ORDER BY (name = ?) DESC, id LIMIT 1'
            );
            $stmt->bind_param('sss', $name, $name, $name);
        } else {
            $name = trim((string) $permission);
            $stmt = $this->conn->prepare(
                'SELECT * FROM permissions WHERE name = ? AND is_active = 1 ORDER BY id LIMIT 1'
            );
            $stmt->bind_param('s', $name);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    private function isActiveUser($userId) {
        $stmt = $this->conn->prepare("SELECT 1 FROM users WHERE id = ? AND status = 'active' LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $active = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $active;
    }

    private function isSuperAdmin($userId) {
        $stmt = $this->conn->prepare(
            "SELECT 1 FROM user_roles assignment
             JOIN roles role ON role.id = assignment.role_id
             JOIN users account ON account.id = assignment.user_id
             WHERE assignment.user_id = ? AND account.status = 'active'
               AND assignment.is_active = 1 AND role.is_active = 1
               AND LOWER(TRIM(role.name)) = 'super admin'
               AND (assignment.expires_at IS NULL OR assignment.expires_at > NOW())
             LIMIT 1"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $allowed = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $allowed;
    }

    private function checkUserOverride($userId, $permissionId) {
        $sql = 'SELECT allowed FROM user_permissions WHERE user_id = ? AND permission_id = ?';
        if ($this->columnExists('user_permissions', 'is_active')) $sql .= ' AND is_active = 1';
        if ($this->columnExists('user_permissions', 'expires_at')) {
            $sql .= ' AND (expires_at IS NULL OR expires_at > NOW())';
        }
        $sql .= ' ORDER BY allowed ASC LIMIT 1';
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('ii', $userId, $permissionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (bool) $row['allowed'] : null;
    }

    private function rolePermissionSource($userId, $permissionId) {
        $stmt = $this->conn->prepare(
            "WITH RECURSIVE effective_roles AS (
                SELECT role.id, role.parent_id, 0 AS depth,
                       CAST(CONCAT(',', role.id, ',') AS CHAR(2000)) AS path
                FROM user_roles assignment
                JOIN roles role ON role.id = assignment.role_id
                WHERE assignment.user_id = ? AND assignment.is_active = 1
                  AND (assignment.expires_at IS NULL OR assignment.expires_at > NOW())
                  AND role.is_active = 1
                UNION ALL
                SELECT parent.id, parent.parent_id, child.depth + 1,
                       CONCAT(child.path, parent.id, ',')
                FROM roles parent
                JOIN effective_roles child ON child.parent_id = parent.id
                WHERE parent.is_active = 1 AND child.depth < 16
                  AND LOCATE(CONCAT(',', parent.id, ','), child.path) = 0
            )
            SELECT effective_roles.depth FROM effective_roles
            JOIN role_permissions grant_row ON grant_row.role_id = effective_roles.id
            JOIN permissions permission ON permission.id = grant_row.permission_id
            WHERE grant_row.permission_id = ? AND grant_row.is_active = 1
              AND permission.is_active = 1
              AND (grant_row.expires_at IS NULL OR grant_row.expires_at > NOW())
            ORDER BY effective_roles.depth LIMIT 1"
        );
        $stmt->bind_param('ii', $userId, $permissionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return null;
        return (int) $row['depth'] > 0 ? 'inherited_role' : 'role';
    }

    private function checkContextPermission($userId, array $permission, array $context) {
        $policy = $this->contextPolicy((int) $permission['id'], (string) $permission['name'], $context);
        if (!$policy) return ['allowed' => false, 'reason' => 'context_policy_not_configured'];

        $key = $policy['context_key'];
        if (!array_key_exists($key, $context) || (int) $context[$key] < 1) {
            return ['allowed' => false, 'reason' => 'required_context_missing'];
        }

        $scopeId = (int) $context[$key];
        switch ($policy['context_type']) {
            case 'church': $allowed = $this->userBelongsToChurch($userId, $scopeId); break;
            case 'bible_class': $allowed = $this->userBelongsToClass($userId, $scopeId); break;
            case 'organization': $allowed = $this->userBelongsToOrganization($userId, $scopeId); break;
            case 'organization_unit': $allowed = $this->userBelongsToOrganizationUnit($userId, $scopeId); break;
            default: $allowed = false;
        }
        return ['allowed' => $allowed, 'reason' => $allowed ? 'context_authorized' : 'context_scope_denied'];
    }

    private function contextPolicy($permissionId, $permissionName, array $context) {
        if ($this->tableExists('permission_context_policies')) {
            $stmt = $this->conn->prepare(
                'SELECT context_type, context_key FROM permission_context_policies
                 WHERE permission_id = ? AND is_active = 1 ORDER BY id LIMIT 1'
            );
            $stmt->bind_param('i', $permissionId);
            $stmt->execute();
            $policy = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($policy) return $policy;
        }
        if (strpos($permissionName, 'own_class') !== false || array_key_exists('class_id', $context)) {
            return ['context_type' => 'bible_class', 'context_key' => 'class_id'];
        }
        if (strpos($permissionName, 'own_org') !== false || array_key_exists('organization_id', $context)) {
            return ['context_type' => 'organization', 'context_key' => 'organization_id'];
        }
        if (array_key_exists('organization_unit_id', $context)) {
            return ['context_type' => 'organization_unit', 'context_key' => 'organization_unit_id'];
        }
        if (strpos($permissionName, 'own_church') !== false || array_key_exists('church_id', $context)) {
            return ['context_type' => 'church', 'context_key' => 'church_id'];
        }
        return null;
    }

    private function userBelongsToClass($userId, $classId) {
        $stmt = $this->conn->prepare(
            'SELECT 1 FROM users account JOIN members member ON member.id = account.member_id
             WHERE account.id = ? AND member.class_id = ? LIMIT 1'
        );
        $stmt->bind_param('ii', $userId, $classId);
        $stmt->execute();
        $allowed = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $allowed;
    }

    private function userBelongsToChurch($userId, $churchId) {
        $stmt = $this->conn->prepare(
            "SELECT 1 FROM users WHERE id = ? AND church_id = ? AND status = 'active' LIMIT 1"
        );
        $stmt->bind_param('ii', $userId, $churchId);
        $stmt->execute();
        $allowed = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $allowed;
    }

    private function userBelongsToOrganization($userId, $organizationId) {
        $stmt = $this->conn->prepare(
            'SELECT 1 FROM users account
             JOIN member_organizations membership ON membership.member_id = account.member_id
             WHERE account.id = ? AND membership.organization_id = ? LIMIT 1'
        );
        $stmt->bind_param('ii', $userId, $organizationId);
        $stmt->execute();
        $allowed = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $allowed;
    }

    private function userBelongsToOrganizationUnit($userId, $unitId) {
        if (!$this->tableExists('organization_unit_assignments')) return false;
        $stmt = $this->conn->prepare(
            'SELECT 1 FROM users account
             JOIN member_organizations membership ON membership.member_id = account.member_id
             JOIN organization_unit_assignments assignment ON assignment.member_organization_id = membership.id
             WHERE account.id = ? AND assignment.organization_unit_id = ? LIMIT 1'
        );
        $stmt->bind_param('ii', $userId, $unitId);
        $stmt->execute();
        $allowed = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $allowed;
    }

    private function cacheResult($key, $result) {
        if ($this->cacheEnabled) $this->cache[$key] = ['result' => (bool) $result, 'time' => time()];
    }

    private function getCacheKey($userId, $permission, array $context, $strict) {
        $this->sortRecursive($context);
        return (int) $userId . '|' . hash('sha256', json_encode([
            'permission' => (string) $permission,
            'context' => $context,
            'strict' => (bool) $strict,
        ]));
    }

    private function sortRecursive(array &$value) {
        ksort($value);
        foreach ($value as &$item) if (is_array($item)) $this->sortRecursive($item);
    }

    private function tableExists($table) {
        $key = 'table:' . $table;
        if (!array_key_exists($key, $this->schemaCache)) {
            $stmt = $this->conn->prepare(
                'SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            $stmt->bind_param('s', $table);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows === 1;
            $stmt->close();
            // information_schema does not expose connection-local temporary
            // tables, which are used by isolated authorization tests.
            if (!$exists && preg_match('/^[a-zA-Z0-9_]+$/', (string) $table)) {
                try {
                    $probe = $this->conn->query("SELECT 1 FROM `{$table}` LIMIT 0");
                    $exists = $probe !== false;
                } catch (Throwable $e) {
                    $exists = false;
                }
            }
            $this->schemaCache[$key] = $exists;
        }
        return $this->schemaCache[$key];
    }

    private function columnExists($table, $column) {
        $key = 'column:' . $table . ':' . $column;
        if (!array_key_exists($key, $this->schemaCache)) {
            $stmt = $this->conn->prepare(
                'SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
            );
            $stmt->bind_param('ss', $table, $column);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows === 1;
            $stmt->close();
            if (!$exists
                && preg_match('/^[a-zA-Z0-9_]+$/', (string) $table)
                && preg_match('/^[a-zA-Z0-9_]+$/', (string) $column)) {
                try {
                    $escapedColumn = $this->conn->real_escape_string((string) $column);
                    $probe = $this->conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$escapedColumn}'");
                    $exists = $probe && $probe->num_rows === 1;
                } catch (Throwable $e) {
                    $exists = false;
                }
            }
            $this->schemaCache[$key] = $exists;
        }
        return $this->schemaCache[$key];
    }

    private function logDecision($userId, array $decision, array $context, $shouldLog) {
        if (!$shouldLog || !$this->auditLogger || $userId < 1) return;
        try {
            $this->auditLogger->log(
                $userId, 'check', 'user', $userId, $decision['permission_id'], null, null,
                json_encode(['scope' => $context, 'source' => $decision['source'], 'reason' => $decision['reason']]),
                $decision['allowed'] ? 'success' : 'failure', $decision['reason']
            );
        } catch (Throwable $e) {
            error_log('RBAC decision audit failed: ' . $e->getMessage());
        }
    }
}
