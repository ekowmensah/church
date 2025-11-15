<?php
/**
 * RBAC v2.0 - Backward Compatible Permission Helpers
 * 
 * This file provides backward-compatible wrappers for the new RBAC system
 * while maintaining the same function signatures as the old system.
 * 
 * @package RBAC
 * @version 2.0
 */

require_once __DIR__ . '/../services/rbac/RBACServiceFactory.php';

/**
 * Check if user has permission (v2.0 - backward compatible)
 * 
 * @param string $permission Permission name
 * @param int|null $user_id User ID (null = current session user)
 * @param array $context Additional context for context-aware permissions
 * @return bool
 */
function has_permission($permission, $user_id = null, $context = []) {
    try {
        // Initialize factory with global connection if not already set
        if (isset($GLOBALS['conn'])) {
            RBACServiceFactory::setConnection($GLOBALS['conn']);
        }
        
        $checker = RBACServiceFactory::getPermissionChecker();
        return $checker->hasPermission($permission, $user_id, $context, false);
        
    } catch (Exception $e) {
        error_log('Permission check failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Check if user has all permissions (AND logic)
 * 
 * @param array $permissions Array of permission names
 * @param int|null $user_id User ID
 * @param array $context Additional context
 * @return bool
 */
function has_all_permissions($permissions, $user_id = null, $context = []) {
    try {
        if (isset($GLOBALS['conn'])) {
            RBACServiceFactory::setConnection($GLOBALS['conn']);
        }
        
        $checker = RBACServiceFactory::getPermissionChecker();
        return $checker->hasAllPermissions($permissions, $user_id, $context);
        
    } catch (Exception $e) {
        error_log('Permission check failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Check if user has any permission (OR logic)
 * 
 * @param array $permissions Array of permission names
 * @param int|null $user_id User ID
 * @param array $context Additional context
 * @return bool
 */
function has_any_permission($permissions, $user_id = null, $context = []) {
    try {
        if (isset($GLOBALS['conn'])) {
            RBACServiceFactory::setConnection($GLOBALS['conn']);
        }
        
        $checker = RBACServiceFactory::getPermissionChecker();
        return $checker->hasAnyPermission($permissions, $user_id, $context);
        
    } catch (Exception $e) {
        error_log('Permission check failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get all permissions for current user
 * 
 * @param bool $includeInherited Include inherited permissions from parent roles
 * @return array
 */
function get_user_permissions($includeInherited = true) {
    try {
        $user_id = $_SESSION['user_id'] ?? null;
        if (!$user_id) {
            return [];
        }
        
        if (isset($GLOBALS['conn'])) {
            RBACServiceFactory::setConnection($GLOBALS['conn']);
        }
        
        $checker = RBACServiceFactory::getPermissionChecker();
        return $checker->getUserPermissions($user_id, $includeInherited);
        
    } catch (Exception $e) {
        error_log('Get user permissions failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get all roles for current user
 * 
 * @return array
 */
function get_user_roles() {
    try {
        $user_id = $_SESSION['user_id'] ?? null;
        if (!$user_id) {
            return [];
        }
        
        if (isset($GLOBALS['conn'])) {
            RBACServiceFactory::setConnection($GLOBALS['conn']);
        }
        
        $checker = RBACServiceFactory::getPermissionChecker();
        return $checker->getUserRoles($user_id);
        
    } catch (Exception $e) {
        error_log('Get user roles failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Clear permission cache for user
 * 
 * @param int|null $user_id User ID (null = current user)
 */
function clear_permission_cache($user_id = null) {
    try {
        if (isset($GLOBALS['conn'])) {
            RBACServiceFactory::setConnection($GLOBALS['conn']);
        }
        
        $checker = RBACServiceFactory::getPermissionChecker();
        $checker->clearCache($user_id);
        
    } catch (Exception $e) {
        error_log('Clear permission cache failed: ' . $e->getMessage());
    }
}

/**
 * Check permission and log the check (for debugging/auditing)
 * 
 * @param string $permission Permission name
 * @param int|null $user_id User ID
 * @param array $context Additional context
 * @return bool
 */
function check_and_log_permission($permission, $user_id = null, $context = []) {
    try {
        if (isset($GLOBALS['conn'])) {
            RBACServiceFactory::setConnection($GLOBALS['conn']);
        }
        
        $checker = RBACServiceFactory::getPermissionChecker();
        return $checker->hasPermission($permission, $user_id, $context, true);
        
    } catch (Exception $e) {
        error_log('Permission check failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Require permission or die with 403 error
 * 
 * @param string $permission Permission name
 * @param int|null $user_id User ID
 * @param array $context Additional context
 */
function require_permission($permission, $user_id = null, $context = []) {
    if (!has_permission($permission, $user_id, $context)) {
        http_response_code(403);
        
        // Try to load custom 403 page
        $error_pages = [
            __DIR__ . '/../views/errors/403.php',
            __DIR__ . '/../../views/errors/403.php',
            dirname(__DIR__) . '/views/errors/403.php'
        ];
        
        foreach ($error_pages as $page) {
            if (file_exists($page)) {
                include $page;
                exit;
            }
        }
        
        // Fallback error message
        echo '<div class="alert alert-danger">';
        echo '<h4>403 Forbidden</h4>';
        echo '<p>You do not have permission to access this resource.</p>';
        echo '<p>Required permission: <code>' . htmlspecialchars($permission) . '</code></p>';
        echo '</div>';
        exit;
    }
}

/**
 * Require any of the permissions or die with 403 error
 * 
 * @param array $permissions Array of permission names
 * @param int|null $user_id User ID
 * @param array $context Additional context
 */
function require_any_permission($permissions, $user_id = null, $context = []) {
    if (!has_any_permission($permissions, $user_id, $context)) {
        http_response_code(403);
        
        echo '<div class="alert alert-danger">';
        echo '<h4>403 Forbidden</h4>';
        echo '<p>You do not have permission to access this resource.</p>';
        echo '<p>Required permissions (any): <code>' . implode(', ', $permissions) . '</code></p>';
        echo '</div>';
        exit;
    }
}

/**
 * Check if current user is super admin
 * 
 * @return bool
 */
function is_super_admin() {
    $user_id = $_SESSION['user_id'] ?? null;
    if (!$user_id) {
        return false;
    }
    
    // Check session first
    if (isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin']) {
        return true;
    }
    
    // Check via permission system
    return has_permission('*') || has_role('Super Admin');
}

/**
 * Check if user has specific role
 * 
 * @param string $roleName Role name
 * @param int|null $user_id User ID
 * @return bool
 */
function has_role($roleName, $user_id = null) {
    try {
        $user_id = $user_id ?: ($_SESSION['user_id'] ?? null);
        if (!$user_id) {
            return false;
        }
        
        if (isset($GLOBALS['conn'])) {
            RBACServiceFactory::setConnection($GLOBALS['conn']);
        }
        
        $checker = RBACServiceFactory::getPermissionChecker();
        $roles = $checker->getUserRoles($user_id);
        
        foreach ($roles as $role) {
            if ($role['name'] === $roleName) {
                return true;
            }
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log('Role check failed: ' . $e->getMessage());
        return false;
    }
}
