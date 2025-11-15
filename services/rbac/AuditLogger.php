<?php
/**
 * Audit Logger
 * Handles all audit logging for RBAC activities
 * 
 * @package RBAC
 * @version 2.0
 */

class AuditLogger {
    private $conn;
    
    public function __construct($db_connection) {
        $this->conn = $db_connection;
    }
    
    /**
     * Log an audit entry
     * 
     * @param int $actorUserId User performing the action
     * @param string $action Action type (grant, revoke, deny, check, request, approve, reject, modify)
     * @param string $targetType Target type (role, user, permission, category)
     * @param int $targetId Target ID
     * @param int|null $permissionId Permission involved
     * @param int|null $roleId Role involved
     * @param string|null $oldValue Previous state (JSON)
     * @param string|null $newValue New state (JSON)
     * @param string $result Result (success, failure, pending)
     * @param string|null $reason Reason or description
     * @return bool
     */
    public function log(
        $actorUserId,
        $action,
        $targetType,
        $targetId,
        $permissionId = null,
        $roleId = null,
        $oldValue = null,
        $newValue = null,
        $result = 'success',
        $reason = null
    ) {
        try {
            // Get context information
            $context = $this->getContext();
            
            $stmt = $this->conn->prepare("
                INSERT INTO permission_audit_log_enhanced (
                    actor_user_id,
                    action,
                    target_type,
                    target_id,
                    permission_id,
                    role_id,
                    old_value,
                    new_value,
                    context,
                    result,
                    reason
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $contextJson = json_encode($context);
            
            $stmt->bind_param(
                'issiiisssss',
                $actorUserId,
                $action,
                $targetType,
                $targetId,
                $permissionId,
                $roleId,
                $oldValue,
                $newValue,
                $contextJson,
                $result,
                $reason
            );
            
            return $stmt->execute();
            
        } catch (Exception $e) {
            // Log error but don't throw - audit logging should not break the application
            error_log('Audit logging failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get audit logs with filters
     * 
     * @param array $filters
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getAuditLogs($filters = [], $limit = 100, $offset = 0) {
        $sql = "
            SELECT 
                pal.*,
                u.name as actor_name,
                u.email as actor_email,
                p.name as permission_name,
                r.name as role_name
            FROM permission_audit_log_enhanced pal
            LEFT JOIN users u ON pal.actor_user_id = u.id
            LEFT JOIN permissions p ON pal.permission_id = p.id
            LEFT JOIN roles r ON pal.role_id = r.id
            WHERE 1=1
        ";
        
        $params = [];
        $types = '';
        
        if (isset($filters['actor_user_id'])) {
            $sql .= " AND pal.actor_user_id = ?";
            $params[] = $filters['actor_user_id'];
            $types .= 'i';
        }
        
        if (isset($filters['action'])) {
            $sql .= " AND pal.action = ?";
            $params[] = $filters['action'];
            $types .= 's';
        }
        
        if (isset($filters['target_type'])) {
            $sql .= " AND pal.target_type = ?";
            $params[] = $filters['target_type'];
            $types .= 's';
        }
        
        if (isset($filters['target_id'])) {
            $sql .= " AND pal.target_id = ?";
            $params[] = $filters['target_id'];
            $types .= 'i';
        }
        
        if (isset($filters['result'])) {
            $sql .= " AND pal.result = ?";
            $params[] = $filters['result'];
            $types .= 's';
        }
        
        if (isset($filters['date_from'])) {
            $sql .= " AND pal.created_at >= ?";
            $params[] = $filters['date_from'];
            $types .= 's';
        }
        
        if (isset($filters['date_to'])) {
            $sql .= " AND pal.created_at <= ?";
            $params[] = $filters['date_to'];
            $types .= 's';
        }
        
        $sql .= " ORDER BY pal.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';
        
        $stmt = $this->conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $logs = [];
        while ($row = $result->fetch_assoc()) {
            // Decode JSON fields
            if ($row['old_value']) {
                $row['old_value'] = json_decode($row['old_value'], true);
            }
            if ($row['new_value']) {
                $row['new_value'] = json_decode($row['new_value'], true);
            }
            if ($row['context']) {
                $row['context'] = json_decode($row['context'], true);
            }
            $logs[] = $row;
        }
        
        return $logs;
    }
    
    /**
     * Get audit log statistics
     * 
     * @param array $filters
     * @return array
     */
    public function getStatistics($filters = []) {
        $sql = "
            SELECT 
                COUNT(*) as total_entries,
                COUNT(DISTINCT actor_user_id) as unique_users,
                SUM(CASE WHEN result = 'success' THEN 1 ELSE 0 END) as successful_actions,
                SUM(CASE WHEN result = 'failure' THEN 1 ELSE 0 END) as failed_actions,
                SUM(CASE WHEN action = 'check' THEN 1 ELSE 0 END) as permission_checks,
                SUM(CASE WHEN action = 'grant' THEN 1 ELSE 0 END) as grants,
                SUM(CASE WHEN action = 'revoke' THEN 1 ELSE 0 END) as revokes
            FROM permission_audit_log_enhanced
            WHERE 1=1
        ";
        
        $params = [];
        $types = '';
        
        if (isset($filters['date_from'])) {
            $sql .= " AND created_at >= ?";
            $params[] = $filters['date_from'];
            $types .= 's';
        }
        
        if (isset($filters['date_to'])) {
            $sql .= " AND created_at <= ?";
            $params[] = $filters['date_to'];
            $types .= 's';
        }
        
        $stmt = $this->conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_assoc();
    }
    
    /**
     * Get user activity summary
     * 
     * @param int $userId
     * @param int $days Number of days to look back
     * @return array
     */
    public function getUserActivity($userId, $days = 30) {
        $stmt = $this->conn->prepare("
            SELECT 
                action,
                target_type,
                COUNT(*) as count,
                MAX(created_at) as last_activity
            FROM permission_audit_log_enhanced
            WHERE actor_user_id = ?
            AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY action, target_type
            ORDER BY count DESC
        ");
        $stmt->bind_param('ii', $userId, $days);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $activity = [];
        while ($row = $result->fetch_assoc()) {
            $activity[] = $row;
        }
        
        return $activity;
    }
    
    /**
     * Get most active users
     * 
     * @param int $days Number of days to look back
     * @param int $limit Number of users to return
     * @return array
     */
    public function getMostActiveUsers($days = 7, $limit = 10) {
        $stmt = $this->conn->prepare("
            SELECT 
                u.id,
                u.name,
                u.email,
                COUNT(pal.id) as action_count,
                MAX(pal.created_at) as last_activity
            FROM permission_audit_log_enhanced pal
            JOIN users u ON pal.actor_user_id = u.id
            WHERE pal.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY u.id
            ORDER BY action_count DESC
            LIMIT ?
        ");
        $stmt->bind_param('ii', $days, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        
        return $users;
    }
    
    /**
     * Get permission usage statistics
     * 
     * @param int $days Number of days to look back
     * @param int $limit Number of permissions to return
     * @return array
     */
    public function getPermissionUsage($days = 7, $limit = 20) {
        $stmt = $this->conn->prepare("
            SELECT 
                p.id,
                p.name,
                pc.name as category_name,
                COUNT(pal.id) as check_count,
                SUM(CASE WHEN pal.result = 'success' THEN 1 ELSE 0 END) as successful_checks,
                SUM(CASE WHEN pal.result = 'failure' THEN 1 ELSE 0 END) as failed_checks,
                MAX(pal.created_at) as last_checked
            FROM permission_audit_log_enhanced pal
            JOIN permissions p ON pal.permission_id = p.id
            LEFT JOIN permission_categories pc ON p.category_id = pc.id
            WHERE pal.action = 'check'
            AND pal.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY p.id
            ORDER BY check_count DESC
            LIMIT ?
        ");
        $stmt->bind_param('ii', $days, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $usage = [];
        while ($row = $result->fetch_assoc()) {
            $usage[] = $row;
        }
        
        return $usage;
    }
    
    /**
     * Get failed permission checks
     * 
     * @param int $limit
     * @return array
     */
    public function getFailedChecks($limit = 50) {
        $stmt = $this->conn->prepare("
            SELECT 
                pal.*,
                u.name as actor_name,
                p.name as permission_name
            FROM permission_audit_log_enhanced pal
            JOIN users u ON pal.actor_user_id = u.id
            LEFT JOIN permissions p ON pal.permission_id = p.id
            WHERE pal.action = 'check' AND pal.result = 'failure'
            ORDER BY pal.created_at DESC
            LIMIT ?
        ");
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $failed = [];
        while ($row = $result->fetch_assoc()) {
            if ($row['context']) {
                $row['context'] = json_decode($row['context'], true);
            }
            $failed[] = $row;
        }
        
        return $failed;
    }
    
    /**
     * Clean up old audit logs
     * 
     * @param int $days Keep logs newer than this many days
     * @param bool $checksOnly Only delete permission checks, keep grants/revokes
     * @return int Number of deleted records
     */
    public function cleanup($days = 90, $checksOnly = true) {
        $sql = "
            DELETE FROM permission_audit_log_enhanced
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ";
        
        if ($checksOnly) {
            $sql .= " AND action = 'check'";
        }
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $days);
        $stmt->execute();
        
        $deletedCount = $stmt->affected_rows;
        
        // Log the cleanup
        $this->log(
            null,
            'modify',
            'permission',
            0,
            null,
            null,
            null,
            null,
            'success',
            "Cleaned up $deletedCount old audit logs"
        );
        
        return $deletedCount;
    }
    
    /**
     * Get request context (IP, user agent, etc.)
     * 
     * @return array
     */
    private function getContext() {
        return [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'session_id' => session_id() ?: null
        ];
    }
}
