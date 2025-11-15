<?php
/**
 * Audit Logs API
 * RESTful API for viewing audit logs
 * 
 * Endpoints:
 * GET /api/rbac/audit.php                     - List audit logs
 * GET /api/rbac/audit.php?stats               - Get statistics
 * GET /api/rbac/audit.php?user={id}           - Get user activity
 * GET /api/rbac/audit.php?active_users        - Get most active users
 * GET /api/rbac/audit.php?permission_usage    - Get permission usage
 * GET /api/rbac/audit.php?failed_checks       - Get failed checks
 * 
 * @package RBAC\API
 * @version 2.0
 */

require_once __DIR__ . '/BaseAPI.php';

class AuditAPI extends BaseAPI {
    private $auditLogger;
    
    public function __construct() {
        parent::__construct();
        $this->auditLogger = RBACServiceFactory::getAuditLogger();
    }
    
    /**
     * Handle GET requests
     */
    protected function handleGet() {
        $this->requirePermission('view_audit_log');
        
        // Determine which endpoint
        if (isset($_GET['stats'])) {
            $this->getStatistics();
        } elseif (isset($_GET['user'])) {
            $this->getUserActivity();
        } elseif (isset($_GET['active_users'])) {
            $this->getActiveUsers();
        } elseif (isset($_GET['permission_usage'])) {
            $this->getPermissionUsage();
        } elseif (isset($_GET['failed_checks'])) {
            $this->getFailedChecks();
        } else {
            $this->listAuditLogs();
        }
    }
    
    /**
     * POST not allowed
     */
    protected function handlePost() {
        $this->sendError('Method not allowed', 405);
    }
    
    /**
     * PUT not allowed
     */
    protected function handlePut() {
        $this->sendError('Method not allowed', 405);
    }
    
    /**
     * DELETE not allowed (audit logs are immutable)
     */
    protected function handleDelete() {
        $this->sendError('Method not allowed - audit logs cannot be deleted', 405);
    }
    
    /**
     * List audit logs with filters
     */
    private function listAuditLogs() {
        // Get pagination
        $page = max(1, (int)$this->getParam('page', 1));
        $limit = min(100, max(1, (int)$this->getParam('limit', 50)));
        $offset = ($page - 1) * $limit;
        
        // Get filters
        $filters = [];
        
        if ($actorUserId = $this->getParam('actor_user_id')) {
            $filters['actor_user_id'] = $this->validateInt($actorUserId, 'actor_user_id');
        }
        
        if ($action = $this->getParam('action')) {
            $filters['action'] = $action;
        }
        
        if ($targetType = $this->getParam('target_type')) {
            $filters['target_type'] = $targetType;
        }
        
        if ($targetId = $this->getParam('target_id')) {
            $filters['target_id'] = $this->validateInt($targetId, 'target_id');
        }
        
        if ($result = $this->getParam('result')) {
            $filters['result'] = $result;
        }
        
        if ($dateFrom = $this->getParam('date_from')) {
            $filters['date_from'] = $dateFrom;
        }
        
        if ($dateTo = $this->getParam('date_to')) {
            $filters['date_to'] = $dateTo;
        }
        
        // Get logs
        $logs = $this->auditLogger->getAuditLogs($filters, $limit, $offset);
        
        // Get total count (approximate for performance)
        $total = count($logs) < $limit ? $offset + count($logs) : ($page + 1) * $limit;
        
        $this->sendPaginated($logs, $total, $page, $limit);
    }
    
    /**
     * Get audit statistics
     */
    private function getStatistics() {
        $filters = [];
        
        if ($dateFrom = $this->getParam('date_from')) {
            $filters['date_from'] = $dateFrom;
        }
        
        if ($dateTo = $this->getParam('date_to')) {
            $filters['date_to'] = $dateTo;
        }
        
        $stats = $this->auditLogger->getStatistics($filters);
        
        $this->sendSuccess([
            'statistics' => $stats,
            'filters' => $filters
        ]);
    }
    
    /**
     * Get user activity
     */
    private function getUserActivity() {
        $userId = $this->validateInt($this->getRequiredParam('user'), 'user_id');
        $days = min(365, max(1, (int)$this->getParam('days', 30)));
        
        $activity = $this->auditLogger->getUserActivity($userId, $days);
        
        $this->sendSuccess([
            'user_id' => $userId,
            'days' => $days,
            'activity' => $activity,
            'total_actions' => array_sum(array_column($activity, 'count'))
        ]);
    }
    
    /**
     * Get most active users
     */
    private function getActiveUsers() {
        $days = min(365, max(1, (int)$this->getParam('days', 7)));
        $limit = min(100, max(1, (int)$this->getParam('limit', 10)));
        
        $users = $this->auditLogger->getMostActiveUsers($days, $limit);
        
        $this->sendSuccess([
            'days' => $days,
            'limit' => $limit,
            'users' => $users,
            'total' => count($users)
        ]);
    }
    
    /**
     * Get permission usage statistics
     */
    private function getPermissionUsage() {
        $days = min(365, max(1, (int)$this->getParam('days', 7)));
        $limit = min(100, max(1, (int)$this->getParam('limit', 20)));
        
        $usage = $this->auditLogger->getPermissionUsage($days, $limit);
        
        $this->sendSuccess([
            'days' => $days,
            'limit' => $limit,
            'usage' => $usage,
            'total' => count($usage)
        ]);
    }
    
    /**
     * Get failed permission checks
     */
    private function getFailedChecks() {
        $limit = min(100, max(1, (int)$this->getParam('limit', 50)));
        
        $failed = $this->auditLogger->getFailedChecks($limit);
        
        $this->sendSuccess([
            'limit' => $limit,
            'failed_checks' => $failed,
            'total' => count($failed)
        ]);
    }
}

// Process the request
$api = new AuditAPI();
$api->processRequest();
