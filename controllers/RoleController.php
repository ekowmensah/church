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
        $description = trim($data['description'] ?? '');
        if (!$name) return false;
        // Duplicate name check
        $stmt = $this->conn->prepare('SELECT id FROM roles WHERE LOWER(name) = LOWER(?)');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->close();
            return ['error' => 'Role name already exists.'];
        }
        $stmt->close();
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare('INSERT INTO roles (name, description) VALUES (?, ?)');
            $stmt->bind_param('ss', $name, $description);
            $stmt->execute();
            $role_id = $this->conn->insert_id;
            // Audit log
            $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
            write_audit_log('create', 'role', $role_id, json_encode(['name'=>$name, 'description'=>$description]), $user_id);
            $this->conn->commit();
            return $this->read($role_id);
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("ROLE CREATE ERROR: " . $e->getMessage());
            return ['error' => $e->getMessage()];
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
        $description = trim($data['description'] ?? '');
        if (!$name) return false;
        // Duplicate name check
        $stmt = $this->conn->prepare('SELECT id FROM roles WHERE LOWER(name) = LOWER(?) AND id != ?');
        $stmt->bind_param('si', $name, $id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->close();
            return ['error' => 'Role name already exists.'];
        }
        $stmt->close();
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare('UPDATE roles SET name=?, description=? WHERE id=?');
            $stmt->bind_param('ssi', $name, $description, $id);
            $stmt->execute();
            // Audit log
            $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
            write_audit_log('update', 'role', $id, json_encode(['name'=>$name, 'description'=>$description]), $user_id);
            $this->conn->commit();
            return $this->read($id);
        }  catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => $e->getMessage()]; // TEMP: Show error message for debugging
        }
    }

    // Deactivate a role while preserving catalog and grant evidence.
    public function delete($id) {
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare(
                'UPDATE roles role
                 SET role.is_active = 0
                 WHERE role.id = ? AND role.is_system = 0
                   AND NOT EXISTS (
                       SELECT 1 FROM user_roles assignment
                       JOIN users account ON account.id = assignment.user_id
                       WHERE assignment.role_id = role.id
                         AND assignment.is_active = 1
                         AND account.status = \'active\'
                         AND (assignment.expires_at IS NULL OR assignment.expires_at > NOW())
                   )'
            );
            $stmt->bind_param('i', $id);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                throw new RuntimeException('Protected or assigned roles cannot be deactivated.');
            }
            // Audit log
            $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
            write_audit_log('deactivate', 'role', $id, '', $user_id);
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
