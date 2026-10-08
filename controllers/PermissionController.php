<?php
require_once __DIR__ . '/../models/Permission.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/audit_log.php';

class PermissionController {
    private $conn;
    public function __construct($conn) {
        $this->conn = $conn;
    }

    private function normalizedName($value) {
        $name = strtolower(trim((string) $value));
        if ($name === '' || strlen($name) > 100 || !preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException('Use a lowercase permission name containing only letters, numbers, and underscores.');
        }
        return $name;
    }

    private function nameExists($name, $excludeId = 0) {
        $stmt = $this->conn->prepare('SELECT id FROM permissions WHERE name = ? AND id <> ? LIMIT 1');
        $stmt->bind_param('si', $name, $excludeId);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $exists;
    }

    public function create($data) {
        $name = $this->normalizedName($data['name'] ?? '');
        if ($this->nameExists($name)) {
            throw new InvalidArgumentException('That permission name already exists.');
        }
        // permission_code is the immutable identifier used by the
        // authorization engine. The display name may be renamed later.
        $stmt = $this->conn->prepare(
            "INSERT INTO permissions
                (name, permission_code, risk_level, is_active)
             VALUES (?, ?, 'standard', 1)"
        );
        $stmt->bind_param('ss', $name, $name);
        if ($stmt->execute()) {
            $id = $this->conn->insert_id;
            // Audit log
            $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
            write_audit_log('create', 'permission', $id, json_encode([
                'name' => $name,
                'permission_code' => $name,
                'risk_level' => 'standard',
            ]), $user_id);
            return $this->read($id);
        }
        return false;
    }
    public function read($id) {
        $stmt = $this->conn->prepare(
            'SELECT id, name, permission_code, risk_level, is_system, is_active
             FROM permissions WHERE id=?'
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    public function update($id, $data) {
        $existing = $this->read((int) $id);
        if (!$existing) return false;
        if ((int) $existing['is_system'] === 1) {
            throw new InvalidArgumentException('System permission names cannot be changed.');
        }
        $name = $this->normalizedName($data['name'] ?? '');
        if ($this->nameExists($name, (int) $id)) {
            throw new InvalidArgumentException('That permission name already exists.');
        }
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
        $existing = $this->read((int) $id);
        if (!$existing || (int) $existing['is_system'] === 1) {
            return false;
        }
        // Catalog identities and their audit trail are retained permanently.
        $stmt = $this->conn->prepare('UPDATE permissions SET is_active = 0 WHERE id=?');
        $stmt->bind_param('i', $id);
        $result = $stmt->execute();
        // Audit log
        if ($result) {
            $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
            write_audit_log('deactivate', 'permission', $id, json_encode([
                'permission_code' => $existing['permission_code'] ?? null,
            ]), $user_id);
        }
        return $result;
    }
    public function list() {
        $result = $this->conn->query(
            'SELECT id, name, permission_code, risk_level, is_system, is_active
             FROM permissions ORDER BY name'
        );
        $perms = [];
        while ($row = $result->fetch_assoc()) {
            $perms[] = $row;
        }
        return $perms;
    }
}

?>
