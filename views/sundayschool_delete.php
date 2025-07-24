<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/admin_auth.php';
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id) {
    $stmt = $conn->prepare('DELETE FROM sunday_school WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
}
header('Location: sundayschool_list.php');
exit;
