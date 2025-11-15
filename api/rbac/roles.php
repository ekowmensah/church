<?php
/**
 * Roles API
 * RESTful API for role management
 * 
 * Endpoints:
 * GET    /api/rbac/roles.php                    - List all roles
 * GET    /api/rbac/roles.php?id={id}            - Get specific role
 * GET    /api/rbac/roles.php?id={id}&permissions - Get role with permissions
 * POST   /api/rbac/roles.php                    - Create role
 * PUT    /api/rbac/roles.php?id={id}            - Update role
 * DELETE /api/rbac/roles.php?id={id}            - Delete role
 * POST   /api/rbac/roles.php?id={id}&grant      - Grant permission to role
 * POST   /api/rbac/roles.php?id={id}&revoke     - Revoke permission from role
 * POST   /api/rbac/roles.php?id={id}&sync       - Sync role permissions
 * 
 * @package RBAC\API
 * @version 2.0
 */

require_once __DIR__ . '/BaseAPI.php';

class RolesAPI extends BaseAPI {
    private $roleService;
    
    public function __construct() {
        parent::__construct();
        $this->roleService = RBACServiceFactory::getRoleService();
    }
    
    /**
     * Handle GET requests
     */
    protected function handleGet() {
        $id = $this->getParam('id');
        
        if ($id) {
            // Check if requesting permissions
            if (isset($_GET['permissions'])) {
                $this->getRolePermissions($id);
            } else {
                $this->getRole($id);
            }
        } else {
            // Check if requesting hierarchy
            if ($this->getParam('hierarchy') === 'true') {
                $this->getRoleHierarchy();
            } else {
                $this->listRoles();
            }
        }
    }
    
    /**
     * Handle POST requests
     */
    protected function handlePost() {
        $this->requirePermission('manage_roles');
        
        $id = $this->getParam('id');
        
        if ($id) {
            // Permission management
            if (isset($_GET['grant'])) {
                $this->grantPermission($id);
            } elseif (isset($_GET['revoke'])) {
                $this->revokePermission($id);
            } elseif (isset($_GET['sync'])) {
                $this->syncPermissions($id);
            } else {
                $this->sendError('Invalid action', 400);
            }
        } else {
            // Create role
            $this->createRole();
        }
    }
    
    /**
     * Handle PUT requests
     */
    protected function handlePut() {
        $this->requirePermission('manage_roles');
        $id = $this->getRequiredParam('id');
        $this->updateRole($id);
    }
    
    /**
     * Handle DELETE requests
     */
    protected function handleDelete() {
        $this->requirePermission('manage_roles');
        $id = $this->getRequiredParam('id');
        $this->deleteRole($id);
    }
    
    /**
     * List all roles
     */
    private function listRoles() {
        $this->requirePermission('view_role_list');
        
        $filters = [];
        
        if ($isActive = $this->getParam('is_active')) {
            $filters['is_active'] = $isActive === 'true' || $isActive === '1';
        }
        
        if ($isSystem = $this->getParam('is_system')) {
            $filters['is_system'] = $isSystem === 'true' || $isSystem === '1';
        }
        
        if ($level = $this->getParam('level')) {
            $filters['level'] = $this->validateInt($level, 'level');
        }
        
        $roles = $this->roleService->getAllRoles($filters);
        
        $this->sendSuccess([
            'roles' => $roles,
            'total' => count($roles),
            'filters' => $filters
        ]);
    }
    
    /**
     * Get role hierarchy tree
     */
    private function getRoleHierarchy() {
        $this->requirePermission('view_role_list');
        
        $tree = $this->roleService->getRoleTree();
        
        $this->sendSuccess(['hierarchy' => $tree]);
    }
    
    /**
     * Get specific role
     */
    private function getRole($id) {
        $this->requirePermission('view_role_list');
        $id = $this->validateInt($id, 'id');
        
        $role = $this->roleService->getRoleById($id);
        
        if (!$role) {
            $this->sendError('Role not found', 404);
        }
        
        $this->sendSuccess(['role' => $role]);
    }
    
    /**
     * Get role permissions
     */
    private function getRolePermissions($id) {
        $this->requirePermission('view_role_list');
        $id = $this->validateInt($id, 'id');
        
        $includeInherited = $this->getParam('include_inherited', 'true') === 'true';
        $permissions = $this->roleService->getRolePermissions($id, $includeInherited);
        
        $this->sendSuccess([
            'role_id' => $id,
            'permissions' => $permissions,
            'total' => count($permissions),
            'include_inherited' => $includeInherited
        ]);
    }
    
    /**
     * Create new role
     */
    private function createRole() {
        $this->validateRequired(['name', 'description']);
        
        $data = [
            'name' => $this->getRequiredParam('name'),
            'description' => $this->getRequiredParam('description'),
            'parent_id' => $this->getParam('parent_id'),
            'is_system' => $this->getParam('is_system', false),
            'is_active' => $this->getParam('is_active', true)
        ];
        
        try {
            $roleId = $this->roleService->createRole($data, $this->userId);
            
            $this->logActivity('create_role', [
                'role_id' => $roleId,
                'name' => $data['name']
            ]);
            
            $role = $this->roleService->getRoleById($roleId);
            
            $this->sendSuccess(
                ['role' => $role],
                'Role created successfully',
                201
            );
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }
    
    /**
     * Update role
     */
    private function updateRole($id) {
        $id = $this->validateInt($id, 'id');
        
        $allowedFields = ['name', 'description', 'parent_id', 'is_active'];
        
        $data = [];
        foreach ($allowedFields as $field) {
            if (isset($this->requestData[$field])) {
                $data[$field] = $this->requestData[$field];
            }
        }
        
        if (empty($data)) {
            $this->sendError('No fields to update', 400);
        }
        
        try {
            $this->roleService->updateRole($id, $data, $this->userId);
            
            $this->logActivity('update_role', [
                'role_id' => $id,
                'updated_fields' => array_keys($data)
            ]);
            
            $role = $this->roleService->getRoleById($id);
            
            $this->sendSuccess(
                ['role' => $role],
                'Role updated successfully'
            );
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }
    
    /**
     * Delete role
     */
    private function deleteRole($id) {
        $id = $this->validateInt($id, 'id');
        $hardDelete = $this->getParam('hard_delete') === 'true';
        
        try {
            $this->roleService->deleteRole($id, $this->userId, $hardDelete);
            
            $this->logActivity('delete_role', [
                'role_id' => $id,
                'hard_delete' => $hardDelete
            ]);
            
            $this->sendSuccess(
                [],
                $hardDelete ? 'Role permanently deleted' : 'Role deactivated'
            );
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }
    
    /**
     * Grant permission to role
     */
    private function grantPermission($roleId) {
        $roleId = $this->validateInt($roleId, 'role_id');
        $permissionId = $this->validateInt($this->getRequiredParam('permission_id'), 'permission_id');
        
        $options = [];
        if ($expiresAt = $this->getParam('expires_at')) {
            $options['expires_at'] = $expiresAt;
        }
        if ($conditions = $this->getParam('conditions')) {
            $options['conditions'] = $conditions;
        }
        
        try {
            $this->roleService->grantPermission($roleId, $permissionId, $this->userId, $options);
            
            $this->logActivity('grant_permission', [
                'role_id' => $roleId,
                'permission_id' => $permissionId
            ]);
            
            $this->sendSuccess([], 'Permission granted to role');
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }
    
    /**
     * Revoke permission from role
     */
    private function revokePermission($roleId) {
        $roleId = $this->validateInt($roleId, 'role_id');
        $permissionId = $this->validateInt($this->getRequiredParam('permission_id'), 'permission_id');
        
        try {
            $this->roleService->revokePermission($roleId, $permissionId, $this->userId);
            
            $this->logActivity('revoke_permission', [
                'role_id' => $roleId,
                'permission_id' => $permissionId
            ]);
            
            $this->sendSuccess([], 'Permission revoked from role');
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }
    
    /**
     * Sync role permissions (replace all)
     */
    private function syncPermissions($roleId) {
        $roleId = $this->validateInt($roleId, 'role_id');
        $permissionIds = $this->getRequiredParam('permission_ids');
        
        if (!is_array($permissionIds)) {
            $this->sendError('permission_ids must be an array', 400);
        }
        
        // Validate all permission IDs
        foreach ($permissionIds as $permId) {
            $this->validateInt($permId, 'permission_id');
        }
        
        try {
            $this->roleService->syncPermissions($roleId, $permissionIds, $this->userId);
            
            $this->logActivity('sync_permissions', [
                'role_id' => $roleId,
                'permission_count' => count($permissionIds)
            ]);
            
            $this->sendSuccess(
                ['synced_count' => count($permissionIds)],
                'Role permissions synced successfully'
            );
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }
}

// Process the request
$api = new RolesAPI();
$api->processRequest();
