<?php
/**
 * Role Service
 * Handles all role-related business logic
 * 
 * @package RBAC
 * @version 2.0
 */

class RoleService {
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
     * Get all roles with optional filters
     * 
     * @param array $filters ['is_active' => bool, 'is_system' => bool]
     * @return array
     */
    public function getAllRoles($filters = []) {
        $sql = "
            SELECT 
                r.*,
                parent.name as parent_role_name,
                COUNT(DISTINCT ur.user_id) as user_count,
                COUNT(DISTINCT rp.permission_id) as permission_count
            FROM roles r
            LEFT JOIN roles parent ON r.parent_id = parent.id
            LEFT JOIN user_roles ur ON r.id = ur.role_id AND ur.is_active = 1
            LEFT JOIN role_permissions rp ON r.id = rp.role_id AND rp.is_active = 1
            WHERE 1=1
        ";
        
        $params = [];
        $types = '';
        
        if (isset($filters['is_active'])) {
            $sql .= " AND r.is_active = ?";
            $params[] = $filters['is_active'];
            $types .= 'i';
        }
        
        if (isset($filters['is_system'])) {
            $sql .= " AND r.is_system = ?";
            $params[] = $filters['is_system'];
            $types .= 'i';
        }
        
        if (isset($filters['level'])) {
            $sql .= " AND r.level = ?";
            $params[] = $filters['level'];
            $types .= 'i';
        }
        
        $sql .= " GROUP BY r.id ORDER BY r.level, r.name";
        
        $stmt = $this->conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $roles = [];
        while ($row = $result->fetch_assoc()) {
            $roles[] = $row;
        }
        
        return $roles;
    }
    
    /**
     * Get role by ID
     * 
     * @param int $roleId
     * @return array|null
     */
    public function getRoleById($roleId) {
        $stmt = $this->conn->prepare("
            SELECT 
                r.*,
                parent.name as parent_role_name,
                COUNT(DISTINCT ur.user_id) as user_count,
                COUNT(DISTINCT rp.permission_id) as permission_count
            FROM roles r
            LEFT JOIN roles parent ON r.parent_id = parent.id
            LEFT JOIN user_roles ur ON r.id = ur.role_id AND ur.is_active = 1
            LEFT JOIN role_permissions rp ON r.id = rp.role_id AND rp.is_active = 1
            WHERE r.id = ?
            GROUP BY r.id
        ");
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_assoc();
    }
    
    /**
     * Get role by name
     * 
     * @param string $roleName
     * @return array|null
     */
    public function getRoleByName($roleName) {
        $stmt = $this->conn->prepare("
            SELECT r.* FROM roles r WHERE r.name = ?
        ");
        $stmt->bind_param('s', $roleName);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_assoc();
    }
    
    /**
     * Create new role
     * 
     * @param array $data
     * @param int $createdBy User ID
     * @return int|false Role ID or false on failure
     */
    public function createRole($data, $createdBy = null) {
        // Validate required fields
        if (empty($data['name'])) {
            throw new Exception('Role name is required');
        }
        
        // Check for duplicate name
        if ($this->roleExists($data['name'])) {
            throw new Exception('Role with this name already exists');
        }
        
        $this->conn->begin_transaction();
        
        try {
            // Calculate level based on parent
            $level = 0;
            if (!empty($data['parent_id'])) {
                $parent = $this->getRoleById($data['parent_id']);
                if ($parent) {
                    $level = $parent['level'] + 1;
                }
            }
            
            $stmt = $this->conn->prepare("
                INSERT INTO roles (
                    name, 
                    description, 
                    parent_id,
                    level,
                    is_system,
                    is_active
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $isSystem = isset($data['is_system']) ? (int)$data['is_system'] : 0;
            $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;
            
            $stmt->bind_param(
                'ssiiii',
                $data['name'],
                $data['description'],
                $data['parent_id'],
                $level,
                $isSystem,
                $isActive
            );
            
            $stmt->execute();
            $roleId = $this->conn->insert_id;
            
            // Log audit
            if ($this->auditLogger && $createdBy) {
                $this->auditLogger->log(
                    $createdBy,
                    'grant',
                    'role',
                    $roleId,
                    null,
                    $roleId,
                    null,
                    json_encode($data),
                    'success',
                    'Role created'
                );
            }
            
            $this->conn->commit();
            return $roleId;
            
        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }
    
    /**
     * Update role
     * 
     * @param int $roleId
     * @param array $data
     * @param int $updatedBy User ID
     * @return bool
     */
    public function updateRole($roleId, $data, $updatedBy = null) {
        $role = $this->getRoleById($roleId);
        if (!$role) {
            throw new Exception('Role not found');
        }
        
        // Prevent updating system roles
        if ($role['is_system'] && !isset($data['allow_system_update'])) {
            throw new Exception('Cannot update system role');
        }
        
        // Check for duplicate name if name is being changed
        if (isset($data['name']) && $data['name'] !== $role['name']) {
            if ($this->roleExists($data['name'], $roleId)) {
                throw new Exception('Role with this name already exists');
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
                'parent_id' => 'i',
                'is_active' => 'i'
            ];
            
            foreach ($allowedFields as $field => $type) {
                if (isset($data[$field])) {
                    $updates[] = "$field = ?";
                    $params[] = $data[$field];
                    $types .= $type;
                }
            }
            
            // Recalculate level if parent changed
            if (isset($data['parent_id'])) {
                $level = 0;
                if ($data['parent_id']) {
                    $parent = $this->getRoleById($data['parent_id']);
                    if ($parent) {
                        $level = $parent['level'] + 1;
                    }
                }
                $updates[] = "level = ?";
                $params[] = $level;
                $types .= 'i';
            }
            
            if (empty($updates)) {
                throw new Exception('No fields to update');
            }
            
            $sql = "UPDATE roles SET " . implode(', ', $updates) . " WHERE id = ?";
            $params[] = $roleId;
            $types .= 'i';
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            
            // Log audit
            if ($this->auditLogger && $updatedBy) {
                $this->auditLogger->log(
                    $updatedBy,
                    'modify',
                    'role',
                    $roleId,
                    null,
                    $roleId,
                    json_encode($role),
                    json_encode($data),
                    'success',
                    'Role updated'
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
     * Delete role (soft delete by default)
     * 
     * @param int $roleId
     * @param int $deletedBy User ID
     * @param bool $hardDelete Permanently delete
     * @return bool
     */
    public function deleteRole($roleId, $deletedBy = null, $hardDelete = false) {
        $role = $this->getRoleById($roleId);
        if (!$role) {
            throw new Exception('Role not found');
        }
        
        // Prevent deleting system roles
        if ($role['is_system']) {
            throw new Exception('Cannot delete system role');
        }
        
        // Check if role has users
        if ($role['user_count'] > 0 && $hardDelete) {
            throw new Exception('Cannot delete role with assigned users. Remove users first.');
        }
        
        $this->conn->begin_transaction();
        
        try {
            if ($hardDelete) {
                // Hard delete - remove from database
                $stmt = $this->conn->prepare("DELETE FROM roles WHERE id = ?");
                $stmt->bind_param('i', $roleId);
                $stmt->execute();
            } else {
                // Soft delete - mark as inactive
                $stmt = $this->conn->prepare("UPDATE roles SET is_active = 0 WHERE id = ?");
                $stmt->bind_param('i', $roleId);
                $stmt->execute();
            }
            
            // Log audit
            if ($this->auditLogger && $deletedBy) {
                $this->auditLogger->log(
                    $deletedBy,
                    'revoke',
                    'role',
                    $roleId,
                    null,
                    $roleId,
                    json_encode($role),
                    null,
                    'success',
                    $hardDelete ? 'Role deleted (hard)' : 'Role deleted (soft)'
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
     * Check if role exists
     * 
     * @param string $name
     * @param int $excludeId Exclude this ID from check
     * @return bool
     */
    public function roleExists($name, $excludeId = null) {
        $sql = "SELECT COUNT(*) as count FROM roles WHERE name = ?";
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
     * Get role hierarchy tree
     * 
     * @param int|null $parentId
     * @return array
     */
    public function getRoleTree($parentId = null) {
        $sql = "
            SELECT r.* FROM roles r
            WHERE parent_id " . ($parentId ? "= ?" : "IS NULL") . " 
            AND is_active = 1
            ORDER BY name
        ";
        
        $stmt = $this->conn->prepare($sql);
        if ($parentId) {
            $stmt->bind_param('i', $parentId);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $tree = [];
        while ($row = $result->fetch_assoc()) {
            $row['children'] = $this->getRoleTree($row['id']);
            $tree[] = $row;
        }
        
        return $tree;
    }
    
    /**
     * Get permissions for a role (including inherited)
     * 
     * @param int $roleId
     * @param bool $includeInherited Include parent role permissions
     * @return array
     */
    public function getRolePermissions($roleId, $includeInherited = true) {
        $permissions = [];
        
        // Get direct permissions
        $stmt = $this->conn->prepare("
            SELECT 
                p.*,
                rp.granted_by,
                rp.granted_at,
                rp.expires_at,
                rp.conditions
            FROM role_permissions rp
            JOIN permissions p ON rp.permission_id = p.id
            WHERE rp.role_id = ? AND rp.is_active = 1 AND p.is_active = 1
            ORDER BY p.name
        ");
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $row['source'] = 'direct';
            $permissions[$row['id']] = $row;
        }
        
        // Get inherited permissions from parent roles
        if ($includeInherited) {
            $role = $this->getRoleById($roleId);
            if ($role && $role['parent_id']) {
                $parentPermissions = $this->getRolePermissions($role['parent_id'], true);
                foreach ($parentPermissions as $perm) {
                    if (!isset($permissions[$perm['id']])) {
                        $perm['source'] = 'inherited';
                        $permissions[$perm['id']] = $perm;
                    }
                }
            }
        }
        
        return array_values($permissions);
    }
    
    /**
     * Grant permission to role
     * 
     * @param int $roleId
     * @param int $permissionId
     * @param int $grantedBy User ID
     * @param array $options ['expires_at' => timestamp, 'conditions' => json]
     * @return bool
     */
    public function grantPermission($roleId, $permissionId, $grantedBy, $options = []) {
        // Check if already granted
        $stmt = $this->conn->prepare("
            SELECT id FROM role_permissions 
            WHERE role_id = ? AND permission_id = ? AND is_active = 1
        ");
        $stmt->bind_param('ii', $roleId, $permissionId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            throw new Exception('Permission already granted to this role');
        }
        
        $this->conn->begin_transaction();
        
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO role_permissions (
                    role_id, 
                    permission_id, 
                    granted_by,
                    expires_at,
                    conditions,
                    is_active
                ) VALUES (?, ?, ?, ?, ?, 1)
            ");
            
            $expiresAt = $options['expires_at'] ?? null;
            $conditions = isset($options['conditions']) ? json_encode($options['conditions']) : null;
            
            $stmt->bind_param(
                'iiiss',
                $roleId,
                $permissionId,
                $grantedBy,
                $expiresAt,
                $conditions
            );
            
            $stmt->execute();
            
            // Log audit
            if ($this->auditLogger) {
                $this->auditLogger->log(
                    $grantedBy,
                    'grant',
                    'role',
                    $roleId,
                    $permissionId,
                    $roleId,
                    null,
                    json_encode(['permission_id' => $permissionId, 'options' => $options]),
                    'success',
                    'Permission granted to role'
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
     * Revoke permission from role
     * 
     * @param int $roleId
     * @param int $permissionId
     * @param int $revokedBy User ID
     * @return bool
     */
    public function revokePermission($roleId, $permissionId, $revokedBy) {
        $this->conn->begin_transaction();
        
        try {
            $stmt = $this->conn->prepare("
                UPDATE role_permissions 
                SET is_active = 0 
                WHERE role_id = ? AND permission_id = ?
            ");
            $stmt->bind_param('ii', $roleId, $permissionId);
            $stmt->execute();
            
            // Log audit
            if ($this->auditLogger) {
                $this->auditLogger->log(
                    $revokedBy,
                    'revoke',
                    'role',
                    $roleId,
                    $permissionId,
                    $roleId,
                    json_encode(['permission_id' => $permissionId]),
                    null,
                    'success',
                    'Permission revoked from role'
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
     * Sync role permissions (replace all permissions)
     * 
     * @param int $roleId
     * @param array $permissionIds
     * @param int $syncedBy User ID
     * @return bool
     */
    public function syncPermissions($roleId, $permissionIds, $syncedBy) {
        $this->conn->begin_transaction();
        
        try {
            // Deactivate all current permissions
            $stmt = $this->conn->prepare("
                UPDATE role_permissions 
                SET is_active = 0 
                WHERE role_id = ?
            ");
            $stmt->bind_param('i', $roleId);
            $stmt->execute();
            
            // Grant new permissions
            foreach ($permissionIds as $permissionId) {
                // Check if permission exists (inactive)
                $stmt = $this->conn->prepare("
                    SELECT id FROM role_permissions 
                    WHERE role_id = ? AND permission_id = ?
                ");
                $stmt->bind_param('ii', $roleId, $permissionId);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    // Reactivate existing
                    $stmt = $this->conn->prepare("
                        UPDATE role_permissions 
                        SET is_active = 1, granted_by = ?, granted_at = NOW()
                        WHERE role_id = ? AND permission_id = ?
                    ");
                    $stmt->bind_param('iii', $syncedBy, $roleId, $permissionId);
                    $stmt->execute();
                } else {
                    // Insert new
                    $stmt = $this->conn->prepare("
                        INSERT INTO role_permissions (role_id, permission_id, granted_by, is_active)
                        VALUES (?, ?, ?, 1)
                    ");
                    $stmt->bind_param('iii', $roleId, $permissionId, $syncedBy);
                    $stmt->execute();
                }
            }
            
            // Log audit
            if ($this->auditLogger) {
                $this->auditLogger->log(
                    $syncedBy,
                    'modify',
                    'role',
                    $roleId,
                    null,
                    $roleId,
                    null,
                    json_encode(['permission_ids' => $permissionIds]),
                    'success',
                    'Role permissions synced'
                );
            }
            
            $this->conn->commit();
            return true;
            
        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }
}
