<?php
// DashboardController: Routes to correct dashboard view based on user type/role
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

class DashboardController {
    public function index() {

        if (!is_logged_in()) {
            header('Location: login.php');
            exit;
        }
        // A linked member ID gives a back-office user contextual scope; it does
        // not turn that authenticated user into a member-portal session.
        if (!empty($_SESSION['user_id'])) {
            include __DIR__.'/../views/user_dashboard.php';
        } elseif (!empty($_SESSION['member_id'])) {
            include __DIR__.'/../views/member_dashboard.php';
        } else {
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
    }
}
