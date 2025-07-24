<?php
// Canonical permission check for Bible Class Edit
require_once __DIR__.'/../helpers/permissions.php';
if (!has_permission('edit_bibleclass')) {
    http_response_code(403);
    include '../views/errors/403.php';
    exit;
}
// Bible Class Edit page: just include the shared form, passing ID for edit mode
$_GET['id'] = isset($_GET['id']) ? intval($_GET['id']) : null;
include 'bibleclass_form.php';
