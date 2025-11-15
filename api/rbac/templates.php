<?php
/**
 * Role Templates API
 * RESTful API for role template management
 * 
 * Endpoints:
 * GET  /api/rbac/templates.php                    - List all templates
 * GET  /api/rbac/templates.php?id={id}            - Get specific template
 * GET  /api/rbac/templates.php?id={id}&usage      - Get template usage
 * POST /api/rbac/templates.php                    - Create template
 * POST /api/rbac/templates.php?id={id}&create_role - Create role from template
 * PUT  /api/rbac/templates.php?id={id}            - Update template
 * DELETE /api/rbac/templates.php?id={id}          - Delete template
 * 
 * @package RBAC\API
 * @version 2.0
 */

require_once __DIR__ . '/BaseAPI.php';

class TemplatesAPI extends BaseAPI {
    private $templateService;
    
    public function __construct() {
        parent::__construct();
        $this->templateService = RBACServiceFactory::getRoleTemplateService();
    }
    
    /**
     * Handle GET requests
     */
    protected function handleGet() {
        $id = $this->getParam('id');
        
        if ($id) {
            if (isset($_GET['usage'])) {
                $this->getTemplateUsage($id);
            } else {
                $this->getTemplate($id);
            }
        } else {
            $this->listTemplates();
        }
    }
    
    /**
     * Handle POST requests
     */
    protected function handlePost() {
        $id = $this->getParam('id');
        
        if ($id && isset($_GET['create_role'])) {
            // Create role from template
            $this->requirePermission('manage_roles');
            $this->createRoleFromTemplate($id);
        } else {
            // Create new template
            $this->requirePermission('manage_role_templates');
            $this->createTemplate();
        }
    }
    
    /**
     * Handle PUT requests
     */
    protected function handlePut() {
        $this->requirePermission('manage_role_templates');
        $id = $this->getRequiredParam('id');
        $this->updateTemplate($id);
    }
    
    /**
     * Handle DELETE requests
     */
    protected function handleDelete() {
        $this->requirePermission('manage_role_templates');
        $id = $this->getRequiredParam('id');
        $this->deleteTemplate($id);
    }
    
    /**
     * List all templates
     */
    private function listTemplates() {
        $this->requirePermission('view_role_templates');
        
        $category = $this->getParam('category');
        $templates = $this->templateService->getAllTemplates($category);
        
        $this->sendSuccess([
            'templates' => $templates,
            'total' => count($templates),
            'category' => $category
        ]);
    }
    
    /**
     * Get specific template
     */
    private function getTemplate($id) {
        $this->requirePermission('view_role_templates');
        $id = $this->validateInt($id, 'id');
        
        $template = $this->templateService->getTemplateById($id);
        
        if (!$template) {
            $this->sendError('Template not found', 404);
        }
        
        $this->sendSuccess(['template' => $template]);
    }
    
    /**
     * Get template usage statistics
     */
    private function getTemplateUsage($id) {
        $this->requirePermission('view_role_templates');
        $id = $this->validateInt($id, 'id');
        
        $usage = $this->templateService->getTemplateUsage($id);
        
        $this->sendSuccess([
            'template_id' => $id,
            'usage' => $usage,
            'total_uses' => count($usage)
        ]);
    }
    
    /**
     * Create new template
     */
    private function createTemplate() {
        $this->validateRequired(['name', 'description', 'template_data']);
        
        $templateData = $this->getRequiredParam('template_data');
        
        // Validate template_data structure
        if (!isset($templateData['permissions']) || !is_array($templateData['permissions'])) {
            $this->sendError('template_data must contain permissions array', 400);
        }
        
        $data = [
            'name' => $this->getRequiredParam('name'),
            'description' => $this->getRequiredParam('description'),
            'template_data' => $templateData,
            'category' => $this->getParam('category', 'custom'),
            'is_system' => $this->getParam('is_system', false)
        ];
        
        try {
            $templateId = $this->templateService->createTemplate($data, $this->userId);
            
            $this->logActivity('create_template', [
                'template_id' => $templateId,
                'name' => $data['name']
            ]);
            
            $template = $this->templateService->getTemplateById($templateId);
            
            $this->sendSuccess(
                ['template' => $template],
                'Template created successfully',
                201
            );
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }
    
    /**
     * Update template
     */
    private function updateTemplate($id) {
        $id = $this->validateInt($id, 'id');
        
        $allowedFields = ['name', 'description', 'template_data', 'category'];
        
        $data = [];
        foreach ($allowedFields as $field) {
            if (isset($this->requestData[$field])) {
                $data[$field] = $this->requestData[$field];
            }
        }
        
        if (empty($data)) {
            $this->sendError('No fields to update', 400);
        }
        
        // Validate template_data if provided
        if (isset($data['template_data'])) {
            if (!isset($data['template_data']['permissions']) || !is_array($data['template_data']['permissions'])) {
                $this->sendError('template_data must contain permissions array', 400);
            }
        }
        
        try {
            $this->templateService->updateTemplate($id, $data, $this->userId);
            
            $this->logActivity('update_template', [
                'template_id' => $id,
                'updated_fields' => array_keys($data)
            ]);
            
            $template = $this->templateService->getTemplateById($id);
            
            $this->sendSuccess(
                ['template' => $template],
                'Template updated successfully'
            );
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }
    
    /**
     * Delete template
     */
    private function deleteTemplate($id) {
        $id = $this->validateInt($id, 'id');
        
        try {
            $this->templateService->deleteTemplate($id, $this->userId);
            
            $this->logActivity('delete_template', [
                'template_id' => $id
            ]);
            
            $this->sendSuccess([], 'Template deleted successfully');
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }
    
    /**
     * Create role from template
     */
    private function createRoleFromTemplate($templateId) {
        $templateId = $this->validateInt($templateId, 'template_id');
        $roleName = $this->getParam('role_name'); // Optional custom name
        
        try {
            $roleId = $this->templateService->createRoleFromTemplate(
                $templateId,
                $roleName,
                $this->userId
            );
            
            $this->logActivity('create_role_from_template', [
                'template_id' => $templateId,
                'role_id' => $roleId,
                'role_name' => $roleName
            ]);
            
            // Get the created role
            $roleService = RBACServiceFactory::getRoleService();
            $role = $roleService->getRoleById($roleId);
            
            $this->sendSuccess(
                ['role' => $role],
                'Role created from template successfully',
                201
            );
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }
}

// Process the request
$api = new TemplatesAPI();
$api->processRequest();
