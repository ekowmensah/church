<?php
/**
 * Permission Service
 * Handles all permission-related business logic
 * 
 * @package RBAC
 * @version 2.0
 */

class PermissionService {
    private $conn;
    private $auditLogger;
    
    public function __construct($db_connection) {
        $this->conn = $db_connection;
    }
    
    /**
     * Set audit logger (dependency injection)
     */
    public function setAuditLogger($auditLogger) {
        $this->auditLogger = $auditLogger;
    }
    
    /**
     * Get all permissions with optional filters
     * 
     * @param array $filters ['category_id' => int, 'is_active' => bool, 'permission_type' => string]
     * @return array
     */
    public function getAllPermissions($filters = []) {
        $sql = "
            SELECT 
                p.*,
                pc.name as category_name,
                pc.slug as category_slug,
                parent.name as parent_permission_name,
                context_policy.context_type,
                context_policy.context_key
            FROM permissions p
            LEFT JOIN permission_categories pc ON p.category_id = pc.id
            LEFT JOIN permissions parent ON p.parent_id = parent.id
            LEFT JOIN permission_context_policies context_policy
              ON context_policy.permission_id = p.id
             AND context_policy.is_active = 1
            WHERE 1=1
        ";
        
        $params = [];
        $types = '';
        
        if (isset($filters['category_id'])) {
            $sql .= " AND p.category_id = ?";
            $params[] = $filters['category_id'];
            $types .= 'i';
        }
        
        if (isset($filters['is_active'])) {
            $sql .= " AND p.is_active = ?";
            $params[] = $filters['is_active'];
            $types .= 'i';
        }
        
        if (isset($filters['permission_type'])) {
            $sql .= " AND p.permission_type = ?";
            $params[] = $filters['permission_type'];
            $types .= 's';
        }
        
        if (isset($filters['search'])) {
            $sql .= " AND (p.name LIKE ? OR p.description LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types .= 'ss';
        }
        
        $sql .= " ORDER BY pc.sort_order, p.sort_order, p.name";
        
        $stmt = $this->conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $permissions = [];
        while ($row = $result->fetch_assoc()) {
            $permissions[] = $row;
        }
        
        return $permissions;
    }
    
    /**
     * Get permission by ID
     * 
     * @param int $permissionId
     * @return array|null
     */
    public function getPermissionById($permissionId) {
        $stmt = $this->conn->prepare("
            SELECT 
                p.*,
                pc.name as category_name,
                pc.slug as category_slug,
                parent.name as parent_permission_name,
                context_policy.context_type,
                context_policy.context_key
            FROM permissions p
            LEFT JOIN permission_categories pc ON p.category_id = pc.id
            LEFT JOIN permissions parent ON p.parent_id = parent.id
            LEFT JOIN permission_context_policies context_policy
              ON context_policy.permission_id = p.id
             AND context_policy.is_active = 1
            WHERE p.id = ?
        ");
        $stmt->bind_param('i', $permissionId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_assoc();
    }
    
    /**
     * Get permission by name
     * 
     * @param string $permissionName
     * @return array|null
     */
    public function getPermissionByName($permissionName) {
        $stmt = $this->conn->prepare("
            SELECT 
                p.*,
                pc.name as category_name,
                pc.slug as category_slug
            FROM permissions p
            LEFT JOIN permission_categories pc ON p.category_id = pc.id
            WHERE p.name = ?
        ");
        $stmt->bind_param('s', $permissionName);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_assoc();
    }
    
    /**
     * Get permissions by category
     * 
     * @param int $categoryId
     * @param bool $activeOnly
     * @return array
     */
    public function getPermissionsByCategory($categoryId, $activeOnly = true) {
        $sql = "
            SELECT p.*
            FROM permissions p
            WHERE p.category_id = ?
        ";
        
        if ($activeOnly) {
            $sql .= " AND p.is_active = 1";
        }
        
        $sql .= " ORDER BY p.sort_order, p.name";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $categoryId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $permissions = [];
        while ($row = $result->fetch_assoc()) {
            $permissions[] = $row;
        }
        
        return $permissions;
    }
    
    /**
     * Get all permission categories
     * 
     * @param bool $activeOnly
     * @return array
     */
    public function getAllCategories($activeOnly = true) {
        $sql = "
            SELECT 
                pc.*,
                COUNT(p.id) as permission_count
            FROM permission_categories pc
            LEFT JOIN permissions p ON pc.id = p.category_id AND p.is_active = 1
            WHERE 1=1
        ";
        
        if ($activeOnly) {
            $sql .= " AND pc.is_active = 1";
        }
        
        $sql .= " GROUP BY pc.id ORDER BY pc.sort_order, pc.name";
        
        $result = $this->conn->query($sql);
        
        $categories = [];
        while ($row = $result->fetch_assoc()) {
            $categories[] = $row;
        }
        
        return $categories;
    }
    
    /**
     * Create new permission
     * 
     * @param array $data
     * @param int $createdBy User ID
     * @return int|false Permission ID or false on failure
     */
    public function createPermission($data, $createdBy = null) {
        $data['name'] = trim((string) ($data['name'] ?? ''));
        // Validate required fields
        if (empty($data['name'])) {
            throw new Exception('Permission name is required');
        }
        if (!preg_match('/^[a-z][a-z0-9_.-]{2,119}$/', $data['name'])) {
            throw new Exception('Permission names must use lowercase letters, numbers, dots, dashes or underscores');
        }
        
        // Check for duplicate name
        if ($this->permissionExists($data['name'])) {
            throw new Exception('Permission with this name already exists');
        }
        $permissionCode = trim((string) ($data['permission_code'] ?? $data['name']));
        if (!preg_match('/^[a-z][a-z0-9_.-]{2,119}$/', $permissionCode)) {
            throw new Exception('Permission codes must use lowercase letters, numbers, dots, dashes or underscores');
        }
        if ($this->permissionCodeExists($permissionCode)) {
            throw new Exception('Permission code already exists');
        }
        if (!empty($data['parent_id']) && !$this->getPermissionById((int) $data['parent_id'])) {
            throw new Exception('The selected parent permission is unavailable');
        }
        
        $this->conn->begin_transaction();
        
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO permissions (
                    name,
                    permission_code,
                    description, 
                    category_id, 
                    parent_id,
                    permission_type,
                    is_system,
                    requires_context,
                    risk_level,
                    sort_order,
                    is_active
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $permissionType = $data['permission_type'] ?? 'action';
            $isSystem = isset($data['is_system']) ? (int)$data['is_system'] : 0;
            $requiresContext = isset($data['requires_context']) ? (int)$data['requires_context'] : 0;
            $riskLevel = $data['risk_level'] ?? 'standard';
            if (!in_array($riskLevel, ['standard','sensitive','financial','privileged','system_critical'], true)) {
                throw new Exception('Choose a valid permission risk level');
            }
            $sortOrder = $data['sort_order'] ?? 0;
            $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;
            
            $stmt->bind_param(
                'sssiisiisii',
                $data['name'],
                $permissionCode,
                $data['description'],
                $data['category_id'],
                $data['parent_id'],
                $permissionType,
                $isSystem,
                $requiresContext,
                $riskLevel,
                $sortOrder,
                $isActive
            );
            
            $stmt->execute();
            $permissionId = $this->conn->insert_id;

            $this->syncContextPolicy($permissionId, $requiresContext === 1, $data);
            
            // Log audit
            if ($this->auditLogger && $createdBy) {
                $this->auditLogger->log(
                    $createdBy,
                    'grant',
                    'permission',
                    $permissionId,
                    $permissionId,
                    null,
                    null,
                    json_encode($data),
                    'success',
                    'Permission created'
                );
            }
            
            $this->conn->commit();
            return $permissionId;
            
        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }
    
    /**
     * Update permission
     * 
     * @param int $permissionId
     * @param array $data
     * @param int $updatedBy User ID
     * @return bool
     */
    public function updatePermission($permissionId, $data, $updatedBy = null) {
        // Check if permission exists
        $permission = $this->getPermissionById($permissionId);
        if (!$permission) {
            throw new Exception('Permission not found');
        }

        if (isset($data['name'])) {
            $data['name'] = trim((string) $data['name']);
            if (!preg_match('/^[a-z][a-z0-9_.-]{2,119}$/', $data['name'])) {
                throw new Exception('Permission names must use lowercase letters, numbers, dots, dashes or underscores');
            }
        }
        if (isset($data['risk_level'])
            && !in_array($data['risk_level'], ['standard','sensitive','financial','privileged','system_critical'], true)) {
            throw new Exception('Choose a valid permission risk level');
        }
        
        // Prevent updating system permissions
        if ($permission['is_system'] && !isset($data['allow_system_update'])) {
            throw new Exception('Cannot update system permission');
        }
        
        // Check for duplicate name if name is being changed
        if (isset($data['name']) && $data['name'] !== $permission['name']) {
            if ($this->permissionExists($data['name'], $permissionId)) {
                throw new Exception('Permission with this name already exists');
            }
        }

        if (array_key_exists('parent_id', $data)) {
            $parentId = (int) ($data['parent_id'] ?? 0);
            if ($parentId === (int) $permissionId
                || ($parentId > 0 && $this->wouldCreatePermissionCycle((int) $permissionId, $parentId))) {
                throw new Exception('A permission cannot inherit from itself or one of its descendants');
            }
        }
        
        $this->conn->begin_transaction();
        
        try {
            $updates = [];
            $params = [];
            $types = '';
            
            $allowedFields = [
                'name' => 's',
                'description' => 's',
                'category_id' => 'i',
                'parent_id' => 'i',
                'permission_type' => 's',
                'requires_context' => 'i',
                'risk_level' => 's',
                'sort_order' => 'i',
                'is_active' => 'i'
            ];
            
            foreach ($allowedFields as $field => $type) {
                if (array_key_exists($field, $data)) {
                    if ($field === 'parent_id' && ((int) $data[$field]) < 1) {
                        $data[$field] = null;
                    }
                    $updates[] = "$field = ?";
                    $params[] = $data[$field];
                    $types .= $type;
                }
            }
            
            if (empty($updates)) {
                throw new Exception('No fields to update');
            }
            
            $sql = "UPDATE permissions SET " . implode(', ', $updates) . " WHERE id = ?";
            $params[] = $permissionId;
            $types .= 'i';
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();

            if (array_key_exists('requires_context', $data)
                || array_key_exists('context_type', $data)
                || array_key_exists('context_key', $data)) {
                $requiresContext = array_key_exists('requires_context', $data)
                    ? (int) $data['requires_context'] === 1
                    : (int) $permission['requires_context'] === 1;
                $this->syncContextPolicy((int) $permissionId, $requiresContext, $data);
            }
            
            // Log audit
            if ($this->auditLogger && $updatedBy) {
                $this->auditLogger->log(
                    $updatedBy,
                    'modify',
                    'permission',
                    $permissionId,
                    $permissionId,
                    null,
                    json_encode($permission),
                    json_encode($data),
                    'success',
                    'Permission updated'
                );
            }
            
            $this->conn->commit();
            return true;
            
        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }
    
    /**
     * Delete permission (soft delete by default)
     * 
     * @param int $permissionId
     * @param int $deletedBy User ID
     * @param bool $hardDelete Permanently delete
     * @return bool
     */
    public function deletePermission($permissionId, $deletedBy = null, $hardDelete = false) {
        $permission = $this->getPermissionById($permissionId);
        if (!$permission) {
            throw new Exception('Permission not found');
        }
        
        // Prevent deleting system permissions
        if ($permission['is_system']) {
            throw new Exception('Cannot delete system permission');
        }
        if ($hardDelete) {
            throw new Exception('Permissions are immutable catalog records; deactivate this permission instead');
        }
        
        $this->conn->begin_transaction();
        
        try {
            $stmt = $this->conn->prepare("UPDATE permissions SET is_active = 0 WHERE id = ?");
            $stmt->bind_param('i', $permissionId);
            $stmt->execute();
            
            // Log audit
            if ($this->auditLogger && $deletedBy) {
                $this->auditLogger->log(
                    $deletedBy,
                    'revoke',
                    'permission',
                    $permissionId,
                    $permissionId,
                    null,
                    json_encode($permission),
                    null,
                    'success',
                    'Permission deactivated'
                );
            }
            
            $this->conn->commit();
            return true;
            
        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }
    
    /**
     * Check if permission exists
     * 
     * @param string $name
     * @param int $excludeId Exclude this ID from check
     * @return bool
     */
    public function permissionExists($name, $excludeId = null) {
        $sql = "SELECT COUNT(*) as count FROM permissions WHERE name = ?";
        $params = [$name];
        $types = 's';
        
        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
            $types .= 'i';
        }
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        return $row['count'] > 0;
    }

    private function permissionCodeExists($code, $excludeId = null) {
        $sql = 'SELECT COUNT(*) AS total FROM permissions WHERE permission_code = ?';
        $params = [trim((string) $code)];
        $types = 's';
        if ($excludeId) {
            $sql .= ' AND id <> ?';
            $params[] = (int) $excludeId;
            $types .= 'i';
        }
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $exists = (int) $stmt->get_result()->fetch_assoc()['total'] > 0;
        $stmt->close();
        return $exists;
    }

    private function wouldCreatePermissionCycle($permissionId, $candidateParentId) {
        $current = (int) $candidateParentId;
        $visited = [];
        while ($current > 0 && !isset($visited[$current])) {
            if ($current === (int) $permissionId) return true;
            $visited[$current] = true;
            $stmt = $this->conn->prepare('SELECT parent_id FROM permissions WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $current);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $current = (int) ($row['parent_id'] ?? 0);
        }
        return false;
    }

    private function syncContextPolicy($permissionId, $requiresContext, array $data) {
        $permissionId = (int) $permissionId;
        $this->conn->query(
            'UPDATE permission_context_policies SET is_active = 0
             WHERE permission_id = ' . $permissionId
        );
        if (!$requiresContext) return;

        $contextType = trim((string) ($data['context_type'] ?? 'church'));
        $validTypes = ['church', 'bible_class', 'organization', 'organization_unit'];
        if (!in_array($contextType, $validTypes, true)) {
            throw new Exception('Choose a valid permission context type');
        }
        $defaultKeys = [
            'church' => 'church_id',
            'bible_class' => 'class_id',
            'organization' => 'organization_id',
            'organization_unit' => 'organization_unit_id',
        ];
        $contextKey = trim((string) ($data['context_key'] ?? $defaultKeys[$contextType]));
        if (!preg_match('/^[a-z][a-z0-9_]{1,59}$/', $contextKey)) {
            throw new Exception('Choose a valid permission context key');
        }

        $stmt = $this->conn->prepare(
            'INSERT INTO permission_context_policies
                (permission_id, context_type, context_key, is_active)
             VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE is_active = 1, updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->bind_param('iss', $permissionId, $contextType, $contextKey);
        $stmt->execute();
        $stmt->close();
    }
    
    /**
     * Get permissions grouped by category
     * 
     * @param bool $activeOnly
     * @return array
     */
    public function getPermissionsGroupedByCategory($activeOnly = true) {
        // Optimized: Single query instead of N+1 queries
        $sql = "
            SELECT 
                p.id,
                p.name,
                p.description,
                p.category_id,
                c.name as category_name
            FROM permissions p
            LEFT JOIN permission_categories c ON p.category_id = c.id
        ";
        
        if ($activeOnly) {
            $sql .= " WHERE p.is_active = 1 AND c.is_active = 1";
        }
        
        $sql .= " ORDER BY c.sort_order, c.name, p.sort_order, p.name";
        
        $result = $this->conn->query($sql);
        $grouped = [];
        
        while ($row = $result->fetch_assoc()) {
            $categoryName = $row['category_name'] ?? 'Uncategorized';
            
            if (!isset($grouped[$categoryName])) {
                $grouped[$categoryName] = [];
            }
            
            $grouped[$categoryName][] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'description' => $row['description']
            ];
        }
        
        return $grouped;
    }
    
    /**
     * Get child permissions
     * 
     * @param int $parentId
     * @return array
     */
    public function getChildPermissions($parentId) {
        $stmt = $this->conn->prepare("
            SELECT * FROM permissions 
            WHERE parent_id = ? AND is_active = 1
            ORDER BY sort_order, name
        ");
        $stmt->bind_param('i', $parentId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $permissions = [];
        while ($row = $result->fetch_assoc()) {
            $permissions[] = $row;
        }
        
        return $permissions;
    }
    
    /**
     * Get permission hierarchy tree
     * 
     * @param int|null $parentId
     * @return array
     */
    public function getPermissionTree($parentId = null) {
        $sql = "
            SELECT * FROM permissions 
            WHERE parent_id " . ($parentId ? "= ?" : "IS NULL") . " 
            AND is_active = 1
            ORDER BY sort_order, name
        ";
        
        $stmt = $this->conn->prepare($sql);
        if ($parentId) {
            $stmt->bind_param('i', $parentId);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $tree = [];
        while ($row = $result->fetch_assoc()) {
            $row['children'] = $this->getPermissionTree($row['id']);
            $tree[] = $row;
        }
        
        return $tree;
    }
}
