<?php
/**
 * Role Template Service
 * Handles role template management and role creation from templates
 * 
 * @package RBAC
 * @version 2.0
 */

class RoleTemplateService {
    private $conn;
    private $roleService;
    private $auditLogger;
    
    public function __construct($db_connection) {
        $this->conn = $db_connection;
    }
    
    /**
     * Set role service (dependency injection)
     */
    public function setRoleService($roleService) {
        $this->roleService = $roleService;
    }
    
    /**
     * Set audit logger (dependency injection)
     */
    public function setAuditLogger($auditLogger) {
        $this->auditLogger = $auditLogger;
    }
    
    /**
     * Get all templates
     * 
     * @param string|null $category Filter by category
     * @return array
     */
    public function getAllTemplates($category = null) {
        $sql = "
            SELECT 
                rt.*,
                u.name as created_by_name,
                JSON_LENGTH(JSON_EXTRACT(rt.template_data, '$.permissions')) as permission_count
            FROM role_templates rt
            LEFT JOIN users u ON rt.created_by = u.id
            WHERE 1=1
        ";
        
        $params = [];
        $types = '';
        
        if ($category) {
            $sql .= " AND rt.category = ?";
            $params[] = $category;
            $types .= 's';
        }
        
        $sql .= " ORDER BY rt.category, rt.name";
        
        $stmt = $this->conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $templates = [];
        while ($row = $result->fetch_assoc()) {
            $row['template_data'] = json_decode($row['template_data'], true);
            $templates[] = $row;
        }
        
        return $templates;
    }
    
    /**
     * Get template by ID
     * 
     * @param int $templateId
     * @return array|null
     */
    public function getTemplateById($templateId) {
        $stmt = $this->conn->prepare("
            SELECT 
                rt.*,
                u.name as created_by_name
            FROM role_templates rt
            LEFT JOIN users u ON rt.created_by = u.id
            WHERE rt.id = ?
        ");
        $stmt->bind_param('i', $templateId);
        $stmt->execute();
        $result = $stmt->get_result();
        $template = $result->fetch_assoc();
        
        if ($template) {
            $template['template_data'] = json_decode($template['template_data'], true);
        }
        
        return $template;
    }
    
    /**
     * Get template by name
     * 
     * @param string $name
     * @return array|null
     */
    public function getTemplateByName($name) {
        $stmt = $this->conn->prepare("
            SELECT * FROM role_templates WHERE name = ?
        ");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $result = $stmt->get_result();
        $template = $result->fetch_assoc();
        
        if ($template) {
            $template['template_data'] = json_decode($template['template_data'], true);
        }
        
        return $template;
    }
    
    /**
     * Create role from template
     * 
     * @param int $templateId
     * @param string $roleName Custom role name (optional)
     * @param int $createdBy User ID
     * @return int Role ID
     */
    public function createRoleFromTemplate($templateId, $roleName = null, $createdBy = null) {
        $template = $this->getTemplateById($templateId);
        if (!$template) {
            throw new Exception('Template not found');
        }
        
        if (!$this->roleService) {
            throw new Exception('RoleService not set');
        }
        
        $this->conn->begin_transaction();
        
        try {
            // Create role
            $roleData = [
                'name' => $roleName ?: $template['name'],
                'description' => $template['template_data']['description'] ?? $template['description']
            ];
            
            $roleId = $this->roleService->createRole($roleData, $createdBy);
            
            // Get permission IDs from template
            $permissionNames = $template['template_data']['permissions'] ?? [];
            $permissionIds = $this->getPermissionIdsByNames($permissionNames);
            
            // Assign permissions to role
            foreach ($permissionIds as $permissionId) {
                $this->roleService->grantPermission($roleId, $permissionId, $createdBy);
            }
            
            // Track template usage
            $stmt = $this->conn->prepare("
                INSERT INTO role_template_usage (template_id, role_id, created_by)
                VALUES (?, ?, ?)
            ");
            $stmt->bind_param('iii', $templateId, $roleId, $createdBy);
            $stmt->execute();
            
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
                    json_encode(['template_id' => $templateId, 'template_name' => $template['name']]),
                    'success',
                    'Role created from template'
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
     * Create custom template
     * 
     * @param array $data
     * @param int $createdBy User ID
     * @return int Template ID
     */
    public function createTemplate($data, $createdBy = null) {
        // Validate required fields
        if (empty($data['name'])) {
            throw new Exception('Template name is required');
        }
        
        if (empty($data['template_data']['permissions'])) {
            throw new Exception('Template must have at least one permission');
        }
        
        // Check for duplicate name
        if ($this->templateExists($data['name'])) {
            throw new Exception('Template with this name already exists');
        }
        
        $this->conn->begin_transaction();
        
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO role_templates (
                    name,
                    description,
                    template_data,
                    category,
                    is_system,
                    created_by
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $templateDataJson = json_encode($data['template_data']);
            $category = $data['category'] ?? 'custom';
            $isSystem = isset($data['is_system']) ? (int)$data['is_system'] : 0;
            
            $stmt->bind_param(
                'ssssii',
                $data['name'],
                $data['description'],
                $templateDataJson,
                $category,
                $isSystem,
                $createdBy
            );
            
            $stmt->execute();
            $templateId = $this->conn->insert_id;
            
            // Log audit
            if ($this->auditLogger && $createdBy) {
                $this->auditLogger->log(
                    $createdBy,
                    'grant',
                    'role',
                    $templateId,
                    null,
                    null,
                    null,
                    json_encode($data),
                    'success',
                    'Role template created'
                );
            }
            
            $this->conn->commit();
            return $templateId;
            
        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }
    
    /**
     * Update template
     * 
     * @param int $templateId
     * @param array $data
     * @param int $updatedBy User ID
     * @return bool
     */
    public function updateTemplate($templateId, $data, $updatedBy = null) {
        $template = $this->getTemplateById($templateId);
        if (!$template) {
            throw new Exception('Template not found');
        }
        
        // Prevent updating system templates
        if ($template['is_system'] && !isset($data['allow_system_update'])) {
            throw new Exception('Cannot update system template');
        }
        
        $this->conn->begin_transaction();
        
        try {
            $updates = [];
            $params = [];
            $types = '';
            
            if (isset($data['name'])) {
                if ($this->templateExists($data['name'], $templateId)) {
                    throw new Exception('Template with this name already exists');
                }
                $updates[] = "name = ?";
                $params[] = $data['name'];
                $types .= 's';
            }
            
            if (isset($data['description'])) {
                $updates[] = "description = ?";
                $params[] = $data['description'];
                $types .= 's';
            }
            
            if (isset($data['template_data'])) {
                $updates[] = "template_data = ?";
                $params[] = json_encode($data['template_data']);
                $types .= 's';
            }
            
            if (isset($data['category'])) {
                $updates[] = "category = ?";
                $params[] = $data['category'];
                $types .= 's';
            }
            
            if (empty($updates)) {
                throw new Exception('No fields to update');
            }
            
            $sql = "UPDATE role_templates SET " . implode(', ', $updates) . " WHERE id = ?";
            $params[] = $templateId;
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
                    $templateId,
                    null,
                    null,
                    json_encode($template),
                    json_encode($data),
                    'success',
                    'Role template updated'
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
     * Delete template
     * 
     * @param int $templateId
     * @param int $deletedBy User ID
     * @return bool
     */
    public function deleteTemplate($templateId, $deletedBy = null) {
        $template = $this->getTemplateById($templateId);
        if (!$template) {
            throw new Exception('Template not found');
        }
        
        // Prevent deleting system templates
        if ($template['is_system']) {
            throw new Exception('Cannot delete system template');
        }
        
        $this->conn->begin_transaction();
        
        try {
            $stmt = $this->conn->prepare("DELETE FROM role_templates WHERE id = ?");
            $stmt->bind_param('i', $templateId);
            $stmt->execute();
            
            // Log audit
            if ($this->auditLogger && $deletedBy) {
                $this->auditLogger->log(
                    $deletedBy,
                    'revoke',
                    'role',
                    $templateId,
                    null,
                    null,
                    json_encode($template),
                    null,
                    'success',
                    'Role template deleted'
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
     * Get template usage statistics
     * 
     * @param int $templateId
     * @return array
     */
    public function getTemplateUsage($templateId) {
        $stmt = $this->conn->prepare("
            SELECT 
                rtu.*,
                r.name as role_name,
                u.name as created_by_name
            FROM role_template_usage rtu
            JOIN roles r ON rtu.role_id = r.id
            LEFT JOIN users u ON rtu.created_by = u.id
            WHERE rtu.template_id = ?
            ORDER BY rtu.created_at DESC
        ");
        $stmt->bind_param('i', $templateId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $usage = [];
        while ($row = $result->fetch_assoc()) {
            $usage[] = $row;
        }
        
        return $usage;
    }
    
    /**
     * Get most used templates
     * 
     * @param int $limit
     * @return array
     */
    public function getMostUsedTemplates($limit = 10) {
        $stmt = $this->conn->prepare("
            SELECT 
                rt.*,
                COUNT(rtu.id) as usage_count,
                MAX(rtu.created_at) as last_used
            FROM role_templates rt
            LEFT JOIN role_template_usage rtu ON rt.id = rtu.template_id
            GROUP BY rt.id
            ORDER BY usage_count DESC, rt.name
            LIMIT ?
        ");
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $templates = [];
        while ($row = $result->fetch_assoc()) {
            $row['template_data'] = json_decode($row['template_data'], true);
            $templates[] = $row;
        }
        
        return $templates;
    }
    
    /**
     * Check if template exists
     * 
     * @param string $name
     * @param int $excludeId Exclude this ID from check
     * @return bool
     */
    private function templateExists($name, $excludeId = null) {
        $sql = "SELECT COUNT(*) as count FROM role_templates WHERE name = ?";
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
     * Get permission IDs by names
     * 
     * @param array $names
     * @return array
     */
    private function getPermissionIdsByNames($names) {
        if (empty($names)) {
            return [];
        }
        
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $sql = "SELECT id FROM permissions WHERE name IN ($placeholders) AND is_active = 1";
        
        $stmt = $this->conn->prepare($sql);
        $types = str_repeat('s', count($names));
        $stmt->bind_param($types, ...$names);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = $row['id'];
        }
        
        return $ids;
    }
}
