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
                parent.name as parent_permission_name
            FROM permissions p
            LEFT JOIN permission_categories pc ON p.category_id = pc.id
            LEFT JOIN permissions parent ON p.parent_id = parent.id
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
                parent.name as parent_permission_name
            FROM permissions p
            LEFT JOIN permission_categories pc ON p.category_id = pc.id
            LEFT JOIN permissions parent ON p.parent_id = parent.id
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
        // Validate required fields
        if (empty($data['name'])) {
            throw new Exception('Permission name is required');
        }
        
        // Check for duplicate name
        if ($this->permissionExists($data['name'])) {
            throw new Exception('Permission with this name already exists');
        }
        
        $this->conn->begin_transaction();
        
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO permissions (
                    name, 
                    description, 
                    category_id, 
                    parent_id,
                    permission_type,
                    is_system,
                    requires_context,
                    sort_order,
                    is_active
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $permissionType = $data['permission_type'] ?? 'action';
            $isSystem = isset($data['is_system']) ? (int)$data['is_system'] : 0;
            $requiresContext = isset($data['requires_context']) ? (int)$data['requires_context'] : 0;
            $sortOrder = $data['sort_order'] ?? 0;
            $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;
            
            $stmt->bind_param(
                'ssiisisii',
                $data['name'],
                $data['description'],
                $data['category_id'],
                $data['parent_id'],
                $permissionType,
                $isSystem,
                $requiresContext,
                $sortOrder,
                $isActive
            );
            
            $stmt->execute();
            $permissionId = $this->conn->insert_id;
            
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
                'sort_order' => 'i',
                'is_active' => 'i'
            ];
            
            foreach ($allowedFields as $field => $type) {
                if (isset($data[$field])) {
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
        
        $this->conn->begin_transaction();
        
        try {
            if ($hardDelete) {
                // Hard delete - remove from database
                $stmt = $this->conn->prepare("DELETE FROM permissions WHERE id = ?");
                $stmt->bind_param('i', $permissionId);
                $stmt->execute();
            } else {
                // Soft delete - mark as inactive
                $stmt = $this->conn->prepare("UPDATE permissions SET is_active = 0 WHERE id = ?");
                $stmt->bind_param('i', $permissionId);
                $stmt->execute();
            }
            
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
                    $hardDelete ? 'Permission deleted (hard)' : 'Permission deleted (soft)'
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
