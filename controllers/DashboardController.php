<?php
// DashboardController: Routes to correct dashboard view based on user type/role
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

class DashboardController {
    public function index() {

        if (!is_logged_in()) {
            header('Location: login.php');
            exit;
        }
        // Allow access if user has either 'access_dashboard' OR 'access_admin_panel'
        if (has_permission('access_dashboard') || has_permission('access_admin_panel')) {
            include __DIR__.'/../views/user_dashboard.php';
        } else {
            include __DIR__.'/../views/member_dashboard.php';
        }
    }
}
