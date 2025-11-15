<?php
/**
 * Permissions API
 * RESTful API for permission management
 * 
 * Endpoints:
 * GET    /api/rbac/permissions.php              - List all permissions
 * GET    /api/rbac/permissions.php?id={id}      - Get specific permission
 * POST   /api/rbac/permissions.php              - Create permission
 * PUT    /api/rbac/permissions.php?id={id}      - Update permission
 * DELETE /api/rbac/permissions.php?id={id}      - Delete permission
 * 
 * @package RBAC\API
 * @version 2.0
 */

require_once __DIR__ . '/BaseAPI.php';

class PermissionsAPI extends BaseAPI {
    private $permissionService;
    
    public function __construct() {
        parent::__construct();
        $this->permissionService = RBACServiceFactory::getPermissionService();
    }
    
    /**
     * Handle GET requests
     */
    protected function handleGet() {
        $id = $this->getParam('id');
        
        if ($id) {
            // Get specific permission
            $this->getPermission($id);
        } else {
            // List permissions
            $this->listPermissions();
        }
    }
    
    /**
     * Handle POST requests (Create)
     */
    protected function handlePost() {
        $this->requirePermission('manage_permissions');
        $this->createPermission();
    }
    
    /**
     * Handle PUT requests (Update)
     */
    protected function handlePut() {
        $this->requirePermission('manage_permissions');
        $id = $this->getRequiredParam('id');
        $this->updatePermission($id);
    }
    
    /**
     * Handle DELETE requests
     */
    protected function handleDelete() {
        $this->requirePermission('manage_permissions');
        $id = $this->getRequiredParam('id');
        $this->deletePermission($id);
    }
    
    /**
     * List all permissions with filters
     */
    private function listPermissions() {
        $startTime = microtime(true);
        
        $this->requirePermission('view_permission_list');
        
        // Get filters
        $filters = [];
        
        if ($categoryId = $this->getParam('category_id')) {
            $filters['category_id'] = $this->validateInt($categoryId, 'category_id');
        }
        
        if ($isActive = $this->getParam('is_active')) {
            $filters['is_active'] = $isActive === 'true' || $isActive === '1';
        }
        
        if ($permissionType = $this->getParam('permission_type')) {
            $filters['permission_type'] = $permissionType;
        }
        
        if ($search = $this->getParam('search')) {
            $filters['search'] = $search;
        }
        
        // Get grouping option
        $grouped = $this->getParam('grouped') === 'true';
        
        $queryStart = microtime(true);
        if ($grouped) {
            // Get permissions grouped by category
            $data = $this->permissionService->getPermissionsGroupedByCategory(
                $filters['is_active'] ?? true
            );
        } else {
            // Get flat list
            $data = $this->permissionService->getAllPermissions($filters);
        }
        $queryTime = microtime(true) - $queryStart;
        
        $totalTime = microtime(true) - $startTime;
        
        $this->sendSuccess([
            'permissions' => $data,
            'total' => count($data),
            'filters' => $filters,
            'performance' => [
                'query_time' => round($queryTime * 1000, 2) . 'ms',
                'total_time' => round($totalTime * 1000, 2) . 'ms'
            ]
        ]);
    }
    
    /**
     * Get specific permission
     */
    private function getPermission($id) {
        $this->requirePermission('view_permission_list');
        $id = $this->validateInt($id, 'id');
        
        $permission = $this->permissionService->getPermissionById($id);
        
        if (!$permission) {
            $this->sendError('Permission not found', 404);
        }
        
        // Get child permissions if any
        $children = $this->permissionService->getChildPermissions($id);
        $permission['children'] = $children;
        
        $this->sendSuccess(['permission' => $permission]);
    }
    
    /**
     * Create new permission
     */
    private function createPermission() {
        // Validate required fields
        $this->validateRequired(['name', 'description', 'category_id']);
        
        $data = [
            'name' => $this->getRequiredParam('name'),
            'description' => $this->getRequiredParam('description'),
            'category_id' => $this->validateInt($this->getRequiredParam('category_id'), 'category_id'),
            'parent_id' => $this->getParam('parent_id'),
            'permission_type' => $this->getParam('permission_type', 'action'),
            'is_system' => $this->getParam('is_system', false),
            'requires_context' => $this->getParam('requires_context', false),
            'sort_order' => $this->getParam('sort_order', 0),
            'is_active' => $this->getParam('is_active', true)
        ];
        
        try {
            $permissionId = $this->permissionService->createPermission($data, $this->userId);
            
            $this->logActivity('create_permission', [
                'permission_id' => $permissionId,
                'name' => $data['name']
            ]);
            
            $permission = $this->permissionService->getPermissionById($permissionId);
            
            $this->sendSuccess(
                ['permission' => $permission],
                'Permission created successfully',
                201
            );
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }
    
    /**
     * Update permission
     */
    private function updatePermission($id) {
        $id = $this->validateInt($id, 'id');
        
        // Get allowed update fields
        $allowedFields = [
            'name', 'description', 'category_id', 'parent_id',
            'permission_type', 'requires_context', 'sort_order', 'is_active'
        ];
        
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
            $this->permissionService->updatePermission($id, $data, $this->userId);
            
            $this->logActivity('update_permission', [
                'permission_id' => $id,
                'updated_fields' => array_keys($data)
            ]);
            
            $permission = $this->permissionService->getPermissionById($id);
            
            $this->sendSuccess(
                ['permission' => $permission],
                'Permission updated successfully'
            );
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }
    
    /**
     * Delete permission
     */
    private function deletePermission($id) {
        $id = $this->validateInt($id, 'id');
        $hardDelete = $this->getParam('hard_delete') === 'true';
        
        try {
            $this->permissionService->deletePermission($id, $this->userId, $hardDelete);
            
            $this->logActivity('delete_permission', [
                'permission_id' => $id,
                'hard_delete' => $hardDelete
            ]);
            
            $this->sendSuccess(
                [],
                $hardDelete ? 'Permission permanently deleted' : 'Permission deactivated'
            );
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }
}

// Process the request
$api = new PermissionsAPI();
$api->processRequest();
