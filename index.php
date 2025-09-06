<?php
session_start();
require_once __DIR__.'/controllers/DashboardController.php';
$controller = new DashboardController();
$controller->index();
?>
