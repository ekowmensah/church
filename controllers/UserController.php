<?php
// UserController: CRUD for users
class UserController {
    public function index() { /* List users */
        require_once __DIR__.'/../helpers/global_audit_log.php';
        log_activity('list', 'user');
    }
    public function create() { /* Show add user form */
        require_once __DIR__.'/../helpers/global_audit_log.php';
        log_activity('create', 'user');
    }
    public function store() { /* Handle new user POST */
        require_once __DIR__.'/../helpers/global_audit_log.php';
        log_activity('store', 'user');
    }
    public function edit($id) { /* Show edit user form */ }
    public function update($id) { /* Handle edit user POST */
        require_once __DIR__.'/../helpers/global_audit_log.php';
        log_activity('update', 'user', $id);
    }
    public function delete($id) { /* Handle delete user */
        require_once __DIR__.'/../helpers/global_audit_log.php';
        log_activity('delete', 'user', $id);
    }
}
