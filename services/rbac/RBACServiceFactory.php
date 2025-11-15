<?php
/**
 * RBAC Service Factory
 * Creates and wires up all RBAC services with proper dependencies
 * 
 * @package RBAC
 * @version 2.0
 */

// Include all service classes
require_once __DIR__ . '/PermissionService.php';
require_once __DIR__ . '/RoleService.php';
require_once __DIR__ . '/PermissionChecker.php';
require_once __DIR__ . '/AuditLogger.php';
require_once __DIR__ . '/RoleTemplateService.php';

class RBACServiceFactory {
    private static $instances = [];
    private static $conn = null;
    
    /**
     * Set database connection
     * 
     * @param mysqli $connection
     */
    public static function setConnection($connection) {
        self::$conn = $connection;
    }
    
    /**
     * Get Permission Service
     * 
     * @return PermissionService
     */
    public static function getPermissionService() {
        if (!isset(self::$instances['permission'])) {
            self::ensureConnection();
            $service = new PermissionService(self::$conn);
            $service->setAuditLogger(self::getAuditLogger());
            self::$instances['permission'] = $service;
        }
        return self::$instances['permission'];
    }
    
    /**
     * Get Role Service
     * 
     * @return RoleService
     */
    public static function getRoleService() {
        if (!isset(self::$instances['role'])) {
            self::ensureConnection();
            $service = new RoleService(self::$conn);
            $service->setAuditLogger(self::getAuditLogger());
            self::$instances['role'] = $service;
        }
        return self::$instances['role'];
    }
    
    /**
     * Get Permission Checker
     * 
     * @return PermissionChecker
     */
    public static function getPermissionChecker() {
        if (!isset(self::$instances['checker'])) {
            self::ensureConnection();
            $checker = new PermissionChecker(self::$conn);
            $checker->setAuditLogger(self::getAuditLogger());
            self::$instances['checker'] = $checker;
        }
        return self::$instances['checker'];
    }
    
    /**
     * Get Audit Logger
     * 
     * @return AuditLogger
     */
    public static function getAuditLogger() {
        if (!isset(self::$instances['audit'])) {
            self::ensureConnection();
            self::$instances['audit'] = new AuditLogger(self::$conn);
        }
        return self::$instances['audit'];
    }
    
    /**
     * Get Role Template Service
     * 
     * @return RoleTemplateService
     */
    public static function getRoleTemplateService() {
        if (!isset(self::$instances['template'])) {
            self::ensureConnection();
            $service = new RoleTemplateService(self::$conn);
            $service->setRoleService(self::getRoleService());
            $service->setAuditLogger(self::getAuditLogger());
            self::$instances['template'] = $service;
        }
        return self::$instances['template'];
    }
    
    /**
     * Clear all service instances (useful for testing)
     */
    public static function clearInstances() {
        self::$instances = [];
    }
    
    /**
     * Ensure database connection is set
     */
    private static function ensureConnection() {
        if (!self::$conn) {
            // Try to get connection from global scope
            if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
                self::$conn = $GLOBALS['conn'];
            } else {
                throw new Exception('Database connection not set. Call RBACServiceFactory::setConnection() first.');
            }
        }
    }
}
