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
 * DELETE /api/rbac/roles.php?id={id}            - Deactivate custom role
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
            $this->requirePermission('create_role');
            $this->createRole();
        }
    }
    
    /**
     * Handle PUT requests
     */
    protected function handlePut() {
        $this->requirePermission('manage_roles');
        $this->requirePermission('edit_role');
        $id = $this->getRequiredParam('id');
        $this->updateRole($id);
    }
    
    /**
     * Handle DELETE requests
     */
    protected function handleDelete() {
        $this->requirePermission('manage_roles');
        $this->requirePermission('delete_role');
        $id = $this->getRequiredParam('id');
        $this->deleteRole($id);
    }
    
    /**
     * List all roles
     */
    private function listRoles() {
        if (!has_permission('view_role_list', $this->userId)
            && !has_permission('manage_roles', $this->userId)) {
            $this->sendError('Forbidden', 403);
        }
        
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
        if (!has_permission('view_role_list', $this->userId)
            && !has_permission('manage_roles', $this->userId)) {
            $this->sendError('Forbidden', 403);
        }
        
        $tree = $this->roleService->getRoleTree();
        
        $this->sendSuccess(['hierarchy' => $tree]);
    }
    
    /**
     * Get specific role
     */
    private function getRole($id) {
        if (!has_permission('view_role_list', $this->userId)
            && !has_permission('manage_roles', $this->userId)) {
            $this->sendError('Forbidden', 403);
        }
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
        if (!has_permission('view_role_list', $this->userId)
            && !has_permission('manage_roles', $this->userId)) {
            $this->sendError('Forbidden', 403);
        }
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
            // System roles are created by reviewed migrations, never by a
            // browser payload (including a Super Administrator payload).
            'is_system' => false,
            'is_active' => $this->getParam('is_active', true)
        ];
        $this->assertParentRoleDelegable($data['parent_id']);
        
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
        $this->assertRoleMutationAllowed($id);
        
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
        if (array_key_exists('parent_id', $data)) {
            $this->assertParentRoleDelegable($data['parent_id']);
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
     * Deactivate a custom role while retaining audit evidence.
     */
    private function deleteRole($id) {
        $id = $this->validateInt($id, 'id');
        $this->assertRoleMutationAllowed($id);
        $hardDelete = false;
        
        try {
            $this->roleService->deleteRole($id, $this->userId, $hardDelete);
            
            $this->logActivity('delete_role', [
                'role_id' => $id,
                'hard_delete' => false
            ]);
            
            $this->sendSuccess(
                [],
                'Role deactivated'
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
        $this->assertRoleMutationAllowed($roleId);
        $permissionId = $this->validateInt($this->getRequiredParam('permission_id'), 'permission_id');
        $this->assertPermissionChangesDelegable([$permissionId]);
        
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
        $this->assertRoleMutationAllowed($roleId);
        $permissionId = $this->validateInt($this->getRequiredParam('permission_id'), 'permission_id');
        $this->assertPermissionChangesDelegable([$permissionId]);
        
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
        $this->assertRoleMutationAllowed($roleId);
        $permissionIds = $this->getRequiredParam('permission_ids');
        
        if (!is_array($permissionIds)) {
            $this->sendError('permission_ids must be an array', 400);
        }
        
        // Validate all permission IDs
        foreach ($permissionIds as $permId) {
            $this->validateInt($permId, 'permission_id');
        }
        $currentPermissionIds = array_map(
            'intval',
            array_column($this->roleService->getRolePermissions($roleId, false), 'id')
        );
        $requestedPermissionIds = array_values(array_unique(array_map('intval', $permissionIds)));
        $permissionIds = $requestedPermissionIds;
        $changedPermissionIds = array_values(array_unique(array_merge(
            array_diff($requestedPermissionIds, $currentPermissionIds),
            array_diff($currentPermissionIds, $requestedPermissionIds)
        )));
        $this->assertPermissionChangesDelegable($changedPermissionIds);
        
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

    private function assertRoleMutationAllowed($roleId) {
        $role = $this->roleService->getRoleById((int) $roleId);
        if (!$role) {
            $this->sendError('Role not found', 404);
        }
        if ((int) $role['is_system'] === 1 && !is_super_admin()) {
            $this->sendError('Only a Super Administrator may change a protected system role.', 403);
        }
    }

    /**
     * A delegated role administrator may only add or remove capabilities that
     * are already effective for their own active account. This prevents the
     * role editor from becoming a privilege-escalation path.
     */
    private function assertPermissionChangesDelegable(array $permissionIds) {
        if (empty($permissionIds) || is_super_admin()) return;

        $permissionIds = array_values(array_unique(array_map('intval', $permissionIds)));
        $placeholders = implode(',', array_fill(0, count($permissionIds), '?'));
        $types = str_repeat('i', count($permissionIds));
        $stmt = $this->conn->prepare(
            "SELECT id, COALESCE(NULLIF(permission_code, ''), name) AS capability
               FROM permissions
              WHERE id IN ($placeholders) AND is_active = 1"
        );
        $stmt->bind_param($types, ...$permissionIds);
        $stmt->execute();
        $result = $stmt->get_result();
        $capabilities = [];
        while ($row = $result->fetch_assoc()) {
            $capabilities[(int) $row['id']] = $row['capability'];
        }
        $stmt->close();

        foreach ($permissionIds as $permissionId) {
            $capability = $capabilities[$permissionId] ?? null;
            if (!$capability || !has_permission($capability, $this->userId)) {
                $this->sendError(
                    'You cannot delegate or revoke a capability that is not effective for your own account.',
                    403
                );
            }
        }
    }

    private function assertParentRoleDelegable($parentRoleId) {
        $parentRoleId = (int) ($parentRoleId ?? 0);
        if ($parentRoleId < 1 || is_super_admin()) return;
        $permissionIds = array_map(
            'intval',
            array_column($this->roleService->getRolePermissions($parentRoleId, true), 'id')
        );
        $this->assertPermissionChangesDelegable($permissionIds);
    }
}

// Process the request
$api = new RolesAPI();
$api->processRequest();
