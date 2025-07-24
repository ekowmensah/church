<?php
require_once __DIR__ . '/../models/Member.php';
require_once __DIR__ . '/../config/config.php';

class MemberController {
    private $conn;
    public function __construct($conn) {
        $this->conn = $conn;
    }
    public function create($data) {
        // Implement insert logic
        $result = null; // your existing insert logic here
        require_once __DIR__.'/../helpers/global_audit_log.php';
        log_activity('create', 'member', $data['id'] ?? null, json_encode($data));
        return $result;
    }
    public function read($id) {
        // Implement select logic
    }
    public function update($id, $data) {
        // Implement update logic
        $result = null; // your existing update logic here
        require_once __DIR__.'/../helpers/global_audit_log.php';
        log_activity('update', 'member', $id, json_encode($data));
        return $result;
    }
    public function delete($id) {
        // Implement delete logic
        $result = null; // your existing delete logic here
        require_once __DIR__.'/../helpers/global_audit_log.php';
        log_activity('delete', 'member', $id);
        return $result;
    }
    public function list() {
        // Implement list all logic
        $result = null; // your existing list logic here
        require_once __DIR__.'/../helpers/global_audit_log.php';
        log_activity('list', 'member');
        return $result;
    }
}
?>
