<?php
/**
 * Permission Checker
 * Core service for checking user permissions with caching and context support
 * 
 * @package RBAC
 * @version 2.0
 */

class PermissionChecker {
    private $conn;
    private $auditLogger;
    private $cache = [];
    private $cacheEnabled = true;
    private $cacheTTL = 300; // 5 minutes
    
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
     * Enable/disable caching
     */
    public function setCacheEnabled($enabled) {
        $this->cacheEnabled = $enabled;
    }
    
    /**
     * Clear permission cache
     */
    public function clearCache($userId = null) {
        if ($userId) {
            unset($this->cache[$userId]);
        } else {
            $this->cache = [];
        }
    }
    
    /**
     * Check if user has permission
     * 
     * @param string|int $permission Permission name or ID
     * @param int $userId User ID (null = current session user)
     * @param array $context Additional context for context-aware permissions
     * @param bool $logCheck Whether to log this check
     * @return bool
     */
    public function hasPermission($permission, $userId = null, $context = [], $logCheck = false) {
        // Get user ID from session if not provided
        if (!$userId) {
            $userId = $_SESSION['user_id'] ?? null;
            if (!$userId) {
                return false;
            }
        }
        
        // Check cache first
        $cacheKey = $this->getCacheKey($userId, $permission, $context);
        if ($this->cacheEnabled && isset($this->cache[$cacheKey])) {
            $cached = $this->cache[$cacheKey];
            if (time() - $cached['time'] < $this->cacheTTL) {
                return $cached['result'];
            }
        }
        
        // 1. Super Admin Bypass
        if ($this->isSuperAdmin($userId)) {
            $this->cacheResult($cacheKey, true);
            return true;
        }
        
        // Get permission ID if name was provided
        if (!is_numeric($permission)) {
            $permissionId = $this->getPermissionId($permission);
            if (!$permissionId) {
                $this->logPermissionCheck($userId, $permission, false, 'Permission not found', $logCheck);
                return false;
            }
        } else {
            $permissionId = $permission;
        }
        
        // 2. User-Level Overrides (highest priority)
        $userOverride = $this->checkUserOverride($userId, $permissionId);
        if ($userOverride !== null) {
            $this->cacheResult($cacheKey, $userOverride);
            $this->logPermissionCheck($userId, $permission, $userOverride, 'User override', $logCheck);
            return $userOverride;
        }
        
        // 3. Context-Aware Permission Check
        if (!empty($context)) {
            $contextResult = $this->checkContextPermission($userId, $permissionId, $context);
            if ($contextResult !== null) {
                $this->cacheResult($cacheKey, $contextResult);
                $this->logPermissionCheck($userId, $permission, $contextResult, 'Context check', $logCheck);
                return $contextResult;
            }
        }
        
        // 4. Role-Based Permissions (including inheritance)
        $rolePermission = $this->checkRolePermission($userId, $permissionId);
        $this->cacheResult($cacheKey, $rolePermission);
        $this->logPermissionCheck($userId, $permission, $rolePermission, 'Role check', $logCheck);
        
        return $rolePermission;
    }
    
    /**
     * Check multiple permissions (AND logic)
     * 
     * @param array $permissions
     * @param int $userId
     * @param array $context
     * @return bool
     */
    public function hasAllPermissions($permissions, $userId = null, $context = []) {
        foreach ($permissions as $permission) {
            if (!$this->hasPermission($permission, $userId, $context)) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Check multiple permissions (OR logic)
     * 
     * @param array $permissions
     * @param int $userId
     * @param array $context
     * @return bool
     */
    public function hasAnyPermission($permissions, $userId = null, $context = []) {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission, $userId, $context)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Get all permissions for a user
     * 
     * @param int $userId
     * @param bool $includeInherited
     * @return array
     */
    public function getUserPermissions($userId, $includeInherited = true) {
        $permissions = [];
        
        // 1. Get user-specific permissions
        $stmt = $this->conn->prepare("
            SELECT 
                p.*,
                up.allowed,
                'user_override' as source
            FROM user_permissions up
            JOIN permissions p ON up.permission_id = p.id
            WHERE up.user_id = ? AND p.is_active = 1
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            if ($row['allowed']) {
                $permissions[$row['id']] = $row;
            }
        }
        
        // 2. Get role-based permissions
        $stmt = $this->conn->prepare("
            SELECT DISTINCT
                p.*,
                'role' as source,
                r.name as role_name
            FROM user_roles ur
            JOIN roles r ON ur.role_id = r.id
            JOIN role_permissions rp ON r.id = rp.role_id
            JOIN permissions p ON rp.permission_id = p.id
            WHERE ur.user_id = ? 
            AND ur.is_active = 1 
            AND r.is_active = 1
            AND rp.is_active = 1 
            AND p.is_active = 1
            AND (rp.expires_at IS NULL OR rp.expires_at > NOW())
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            if (!isset($permissions[$row['id']])) {
                $permissions[$row['id']] = $row;
            }
        }
        
        // 3. Get inherited permissions from parent roles
        if ($includeInherited) {
            $inheritedPerms = $this->getInheritedPermissions($userId);
            foreach ($inheritedPerms as $perm) {
                if (!isset($permissions[$perm['id']])) {
                    $perm['source'] = 'inherited';
                    $permissions[$perm['id']] = $perm;
                }
            }
        }
        
        return array_values($permissions);
    }
    
    /**
     * Get user roles
     * 
     * @param int $userId
     * @return array
     */
    public function getUserRoles($userId) {
        $stmt = $this->conn->prepare("
            SELECT 
                r.*,
                ur.is_primary,
                ur.assigned_at,
                ur.expires_at
            FROM user_roles ur
            JOIN roles r ON ur.role_id = r.id
            WHERE ur.user_id = ? 
            AND ur.is_active = 1
            AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
            ORDER BY ur.is_primary DESC, r.level, r.name
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $roles = [];
        while ($row = $result->fetch_assoc()) {
            $roles[] = $row;
        }
        
        return $roles;
    }
    
    /**
     * Check if user is super admin
     * 
     * @param int $userId
     * @return bool
     */
    private function isSuperAdmin($userId) {
        // Check session first for performance
        if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $userId) {
            if (isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin']) {
                return true;
            }
        }
        
        // Check database
        $stmt = $this->conn->prepare("
            SELECT 1 
            FROM user_roles ur
            JOIN roles r ON ur.role_id = r.id
            WHERE ur.user_id = ? 
            AND r.name = 'Super Admin'
            AND ur.is_active = 1
            LIMIT 1
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->num_rows > 0;
    }
    
    /**
     * Check user-level permission override
     * 
     * @param int $userId
     * @param int $permissionId
     * @return bool|null
     */
    private function checkUserOverride($userId, $permissionId) {
        $stmt = $this->conn->prepare("
            SELECT allowed 
            FROM user_permissions 
            WHERE user_id = ? AND permission_id = ?
        ");
        $stmt->bind_param('ii', $userId, $permissionId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            return (bool)$row['allowed'];
        }
        
        return null;
    }
    
    /**
     * Check context-aware permission
     * 
     * @param int $userId
     * @param int $permissionId
     * @param array $context
     * @return bool|null
     */
    private function checkContextPermission($userId, $permissionId, $context) {
        // Get permission details
        $stmt = $this->conn->prepare("
            SELECT * FROM permissions WHERE id = ? AND requires_context = 1
        ");
        $stmt->bind_param('i', $permissionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $permission = $result->fetch_assoc();
        
        if (!$permission) {
            return null; // Not a context-aware permission
        }
        
        // Context validation logic
        // Example: edit_member_in_own_class
        if (strpos($permission['name'], 'in_own_class') !== false) {
            if (isset($context['class_id'])) {
                return $this->userBelongsToClass($userId, $context['class_id']);
            }
        }
        
        // Example: edit_member_in_own_church
        if (strpos($permission['name'], 'in_own_church') !== false) {
            if (isset($context['church_id'])) {
                return $this->userBelongsToChurch($userId, $context['church_id']);
            }
        }
        
        // Example: view_report_for_own_org
        if (strpos($permission['name'], 'for_own_org') !== false) {
            if (isset($context['organization_id'])) {
                return $this->userBelongsToOrganization($userId, $context['organization_id']);
            }
        }
        
        return null;
    }
    
    /**
     * Check role-based permission
     * 
     * @param int $userId
     * @param int $permissionId
     * @return bool
     */
    private function checkRolePermission($userId, $permissionId) {
        $stmt = $this->conn->prepare("
            SELECT 1
            FROM user_roles ur
            JOIN role_permissions rp ON ur.role_id = rp.role_id
            WHERE ur.user_id = ? 
            AND rp.permission_id = ?
            AND ur.is_active = 1
            AND rp.is_active = 1
            AND (rp.expires_at IS NULL OR rp.expires_at > NOW())
            AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
            LIMIT 1
        ");
        $stmt->bind_param('ii', $userId, $permissionId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->num_rows > 0;
    }
    
    /**
     * Get inherited permissions from parent roles
     * 
     * @param int $userId
     * @return array
     */
    private function getInheritedPermissions($userId) {
        $stmt = $this->conn->prepare("
            WITH RECURSIVE role_hierarchy AS (
                -- Get user's direct roles
                SELECT r.id, r.parent_id, r.name, 0 as depth
                FROM user_roles ur
                JOIN roles r ON ur.role_id = r.id
                WHERE ur.user_id = ? AND ur.is_active = 1
                
                UNION ALL
                
                -- Get parent roles recursively
                SELECT r.id, r.parent_id, r.name, rh.depth + 1
                FROM roles r
                JOIN role_hierarchy rh ON r.id = rh.parent_id
                WHERE rh.depth < 10
            )
            SELECT DISTINCT p.*
            FROM role_hierarchy rh
            JOIN role_permissions rp ON rh.id = rp.role_id
            JOIN permissions p ON rp.permission_id = p.id
            WHERE rp.is_active = 1 AND p.is_active = 1
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $permissions = [];
        while ($row = $result->fetch_assoc()) {
            $permissions[] = $row;
        }
        
        return $permissions;
    }
    
    /**
     * Get permission ID by name
     * 
     * @param string $name
     * @return int|null
     */
    private function getPermissionId($name) {
        $stmt = $this->conn->prepare("SELECT id FROM permissions WHERE name = ? AND is_active = 1");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        return $row ? $row['id'] : null;
    }
    
    /**
     * Context helper: Check if user belongs to class
     */
    private function userBelongsToClass($userId, $classId) {
        $stmt = $this->conn->prepare("
            SELECT 1 FROM members m
            WHERE m.user_id = ? AND m.bible_class_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('ii', $userId, $classId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->num_rows > 0;
    }
    
    /**
     * Context helper: Check if user belongs to church
     */
    private function userBelongsToChurch($userId, $churchId) {
        $stmt = $this->conn->prepare("
            SELECT 1 FROM users u
            WHERE u.id = ? AND u.church_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('ii', $userId, $churchId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->num_rows > 0;
    }
    
    /**
     * Context helper: Check if user belongs to organization
     */
    private function userBelongsToOrganization($userId, $organizationId) {
        $stmt = $this->conn->prepare("
            SELECT 1 FROM organization_membership om
            JOIN members m ON om.member_id = m.id
            WHERE m.user_id = ? AND om.organization_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('ii', $userId, $organizationId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->num_rows > 0;
    }
    
    /**
     * Cache permission check result
     */
    private function cacheResult($key, $result) {
        if ($this->cacheEnabled) {
            $this->cache[$key] = [
                'result' => $result,
                'time' => time()
            ];
        }
    }
    
    /**
     * Generate cache key
     */
    private function getCacheKey($userId, $permission, $context) {
        return md5($userId . '|' . $permission . '|' . json_encode($context));
    }
    
    /**
     * Log permission check
     */
    private function logPermissionCheck($userId, $permission, $result, $reason, $shouldLog) {
        if (!$shouldLog || !$this->auditLogger) {
            return;
        }
        
        $permissionId = is_numeric($permission) ? $permission : $this->getPermissionId($permission);
        
        $this->auditLogger->log(
            $userId,
            'check',
            'user',
            $userId,
            $permissionId,
            null,
            null,
            null,
            $result ? 'success' : 'failure',
            $reason
        );
    }
}
