<?php
/**
 * Base API Class
 * Provides common functionality for all RBAC API endpoints
 * 
 * @package RBAC\API
 * @version 2.0
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../services/rbac/RBACServiceFactory.php';
require_once __DIR__ . '/../../helpers/permissions_v2.php';
require_once __DIR__ . '/../../helpers/csrf.php';

abstract class BaseAPI {
    protected $conn;
    protected $requestMethod;
    protected $requestData;
    protected $userId;
    protected $response;
    
    public function __construct() {
        // Set headers
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
        
        // Handle preflight requests
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
        
        // Initialize
        global $conn;
        $this->conn = $conn;
        RBACServiceFactory::setConnection($conn);
        
        $this->requestMethod = $_SERVER['REQUEST_METHOD'];
        $this->requestData = $this->getRequestData();
        $this->response = ['success' => false];
        
        // Authenticate
        if (!$this->authenticate()) {
            $this->sendError('Unauthorized', 401);
            exit;
        }

        if ($this->requestMethod !== 'GET' && !$this->validCsrfToken()) {
            $this->sendError('Your session token expired. Refresh the page and try again.', 419);
            exit;
        }
    }
    
    /**
     * Process the API request
     */
    public function processRequest() {
        try {
            switch ($this->requestMethod) {
                case 'GET':
                    $this->handleGet();
                    break;
                case 'POST':
                    $this->handlePost();
                    break;
                case 'PUT':
                    $this->handlePut();
                    break;
                case 'DELETE':
                    $this->handleDelete();
                    break;
                default:
                    $this->sendError('Method not allowed', 405);
            }
        } catch (Throwable $e) {
            error_log('RBAC API failure: ' . $e->getMessage());
            $this->sendError('The access-control operation could not be completed.', 500);
        }
    }
    
    /**
     * Authenticate the request
     */
    protected function authenticate() {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Check if user is logged in
        if (!isset($_SESSION['user_id'])) {
            return false;
        }
        
        $this->userId = (int) $_SESSION['user_id'];
        $stmt = $this->conn->prepare("SELECT 1 FROM users WHERE id = ? AND status = 'active' LIMIT 1");
        $stmt->bind_param('i', $this->userId);
        $stmt->execute();
        $active = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $active;
    }

    /**
     * Validate either a JSON/form token or the same-origin X-CSRF-Token header.
     */
    protected function validCsrfToken() {
        $token = $this->requestData['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        return csrf_is_valid($token);
    }
    
    /**
     * Check if user has required permission
     */
    protected function requirePermission($permission) {
        if (!has_permission($permission, $this->userId)) {
            $this->sendError('Permission denied: ' . $permission, 403);
            exit;
        }
    }
    
    /**
     * Get request data
     */
    protected function getRequestData() {
        $data = [];
        
        // Always include GET parameters (for query strings like ?id=6&sync)
        $data = $_GET;
        
        // POST/PUT/DELETE body - merge with GET parameters
        if (in_array($this->requestMethod, ['POST', 'PUT', 'DELETE'])) {
            $input = file_get_contents('php://input');
            $jsonData = json_decode($input, true);
            
            if ($jsonData) {
                // Merge JSON body with GET parameters (body takes precedence)
                $data = array_merge($data, $jsonData);
            } else {
                // Merge POST data with GET parameters
                $data = array_merge($data, $_POST);
            }
        }
        
        return $data;
    }
    
    /**
     * Get query parameter
     */
    protected function getParam($key, $default = null) {
        return $this->requestData[$key] ?? $default;
    }
    
    /**
     * Get required parameter
     */
    protected function getRequiredParam($key) {
        if (!isset($this->requestData[$key])) {
            $this->sendError("Missing required parameter: $key", 400);
            exit;
        }
        return $this->requestData[$key];
    }
    
    /**
     * Validate integer parameter
     */
    protected function validateInt($value, $paramName) {
        if (!is_numeric($value) || $value < 0) {
            $this->sendError("Invalid $paramName: must be a positive integer", 400);
            exit;
        }
        return (int)$value;
    }
    
    /**
     * Validate required fields
     */
    protected function validateRequired($fields) {
        $missing = [];
        foreach ($fields as $field) {
            if (!isset($this->requestData[$field]) || empty($this->requestData[$field])) {
                $missing[] = $field;
            }
        }
        
        if (!empty($missing)) {
            $this->sendError('Missing required fields: ' . implode(', ', $missing), 400);
            exit;
        }
    }
    
    /**
     * Send success response
     */
    protected function sendSuccess($data = [], $message = null, $code = 200) {
        $response = [
            'success' => true,
            'data' => $data
        ];
        
        if ($message) {
            $response['message'] = $message;
        }
        
        http_response_code($code);
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * Send error response
     */
    protected function sendError($message, $code = 400, $details = null) {
        $response = [
            'success' => false,
            'error' => $message
        ];
        
        if ($details) {
            $response['details'] = $details;
        }
        
        http_response_code($code);
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * Send paginated response
     */
    protected function sendPaginated($data, $total, $page, $limit) {
        $totalPages = ceil($total / $limit);
        
        $response = [
            'success' => true,
            'data' => $data,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1
            ]
        ];
        
        http_response_code(200);
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * Log API activity
     */
    protected function logActivity($action, $details = null) {
        $auditLogger = RBACServiceFactory::getAuditLogger();
        $auditLogger->log(
            $this->userId,
            'modify',
            'api',
            0,
            null,
            null,
            null,
            json_encode([
                'endpoint' => $_SERVER['REQUEST_URI'],
                'method' => $this->requestMethod,
                'action' => $action,
                'details' => $details
            ]),
            'success',
            "API: $action"
        );
    }
    
    // Abstract methods to be implemented by child classes
    abstract protected function handleGet();
    abstract protected function handlePost();
    abstract protected function handlePut();
    abstract protected function handleDelete();
}
