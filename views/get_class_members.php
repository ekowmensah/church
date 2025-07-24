<?php
require_once __DIR__.'/../config/config.php';
$class_group_id = intval($_GET['class_group_id'] ?? 0);
if ($class_group_id) {
    $stmt = $conn->prepare("SELECT id, name FROM members WHERE class_id = ? ORDER BY name ASC");
    $stmt->bind_param('i', $class_group_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while($m = $res->fetch_assoc()) {
        echo '<option value="'.$m['id'].'">'.htmlspecialchars($m['name']).'</option>';
    }
}
