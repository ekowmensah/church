<?php
require_once __DIR__ . '/../models/Role.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/audit_log.php';

class RoleController {
    private $conn;
    public function __construct($conn) {
        $this->conn = $conn;
    }

    // Create a new role with assigned permissions
    public function create($data) {
        $name = trim($data['name'] ?? '');
        $permissions = $data['permissions'] ?? [];
        if (!$name) return false;
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare('INSERT INTO roles (name) VALUES (?)');
            $stmt->bind_param('s', $name);
            $stmt->execute();
            $role_id = $this->conn->insert_id;
            if (!empty($permissions)) {
                $stmt2 = $this->conn->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
                foreach ($permissions as $pid) {
                    $pid = intval($pid);
                    $stmt2->bind_param('ii', $role_id, $pid);
                    $stmt2->execute();
                }
            }
            // Audit log
            $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
            write_audit_log('create', 'role', $role_id, json_encode(['name'=>$name, 'permissions'=>$permissions]), $user_id);
            $this->conn->commit();
            return $this->read($role_id);
        } catch (Exception $e) {
            $this->conn->rollback();
            return false;
        }
    }

    // Read a role and its permissions
    public function read($id) {
        $id = intval($id);
        $stmt = $this->conn->prepare('SELECT * FROM roles WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if (!$role = $result->fetch_assoc()) return null;
        $role['permissions'] = [];
        $result2 = $this->conn->query('SELECT permission_id FROM role_permissions WHERE role_id = '.$id);
        while ($row = $result2->fetch_assoc()) {
            $role['permissions'][] = $row['permission_id'];
        }
        return $role;
    }

    // Update a role's name and permissions
    public function update($id, $data) {
        $name = trim($data['name'] ?? '');
        $permissions = $data['permissions'] ?? [];
        if (!$name) return false;
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare('UPDATE roles SET name=? WHERE id=?');
            $stmt->bind_param('si', $name, $id);
            $stmt->execute();
            $this->conn->query('DELETE FROM role_permissions WHERE role_id = '.$id);
            if (!empty($permissions)) {
                $stmt2 = $this->conn->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
                foreach ($permissions as $pid) {
                    $pid = intval($pid);
                    $stmt2->bind_param('ii', $id, $pid);
                    $stmt2->execute();
                }
            }
            // Audit log
            $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
            write_audit_log('update', 'role', $id, json_encode(['name'=>$name, 'permissions'=>$permissions]), $user_id);
            $this->conn->commit();
            return $this->read($id);
        }  catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => $e->getMessage()]; // TEMP: Show error message for debugging
        }
    }

    // Delete a role and its permission assignments
    public function delete($id) {
        $this->conn->begin_transaction();
        try {
            $this->conn->query('DELETE FROM role_permissions WHERE role_id = '.$id);
            $stmt = $this->conn->prepare('DELETE FROM roles WHERE id=?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            // Audit log
            $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
            write_audit_log('delete', 'role', $id, '', $user_id);
            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            $this->conn->rollback();
            return false;
        }
    }

    // List all roles with their permissions
    public function list() {
        $roles = [];
        $result = $this->conn->query('SELECT * FROM roles ORDER BY name');
        while ($role = $result->fetch_assoc()) {
            $role['permissions'] = [];
            $result2 = $this->conn->query('SELECT permission_id FROM role_permissions WHERE role_id = '.$role['id']);
            while ($row = $result2->fetch_assoc()) {
                $role['permissions'][] = $row['permission_id'];
            }
            $roles[] = $role;
        }
        return $roles;
    }
}

?>
