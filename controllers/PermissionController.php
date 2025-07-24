<?php
require_once __DIR__ . '/../models/Permission.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/audit_log.php';

class PermissionController {
    private $conn;
    public function __construct($conn) {
        $this->conn = $conn;
    }
    public function create($data) {
        $name = trim($data['name'] ?? '');
        if (!$name) return false;
        $stmt = $this->conn->prepare('INSERT INTO permissions (name) VALUES (?)');
        $stmt->bind_param('s', $name);
        if ($stmt->execute()) {
            $id = $this->conn->insert_id;
            // Audit log
            $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
            write_audit_log('create', 'permission', $id, json_encode(['name'=>$name]), $user_id);
            return $this->read($id);
        }
        return false;
    }
    public function read($id) {
        $stmt = $this->conn->prepare('SELECT id, name FROM permissions WHERE id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    public function update($id, $data) {
        $name = trim($data['name'] ?? '');
        if (!$name) return false;
        $stmt = $this->conn->prepare('UPDATE permissions SET name=? WHERE id=?');
        $stmt->bind_param('si', $name, $id);
        if ($stmt->execute()) {
            // Audit log
            $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
            write_audit_log('update', 'permission', $id, json_encode(['name'=>$name]), $user_id);
            return $this->read($id);
        }
        return false;
    }
    public function delete($id) {
        $stmt = $this->conn->prepare('DELETE FROM permissions WHERE id=?');
        $stmt->bind_param('i', $id);
        $result = $stmt->execute();
        // Audit log
        if ($result) {
            $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
            write_audit_log('delete', 'permission', $id, '', $user_id);
        }
        return $result;
    }
    public function list() {
        $result = $this->conn->query('SELECT id, name FROM permissions ORDER BY name');
        $perms = [];
        while ($row = $result->fetch_assoc()) {
            $perms[] = $row;
        }
        return $perms;
    }
}

?>
