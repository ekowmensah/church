<?php
require_once __DIR__ . '/../models/Health.php';
require_once __DIR__ . '/../config/config.php';

class HealthController {
    private $conn;
    public function __construct($conn) {
        $this->conn = $conn;
    }
    public function create($data) {}
    public function read($id) {}
    public function update($id, $data) {}
    public function delete($id) {}
    public function list() {}
}
?>
