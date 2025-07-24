<?php
// Seeder to reset and insert the new, comprehensive permissions list
require_once __DIR__.'/../config/config.php';

// Canonical permissions list generated from permissions_canonical.csv
$permissions = [
    'view_member',
    'edit_member',
    'delete_member',
    'view_member_profile',
    'edit_member_profile',
    'register_member',
    'complete_registration',
    'view_payment',
    'edit_payment',
    'delete_payment',
    'make_payment',
    'bulk_payment',
    'view_paymenttype',
    'edit_paymenttype',
    'delete_paymenttype',
    'view_attendance',
    'mark_attendance',
    'view_attendance_detail',
    'view_bibleclass',
    'edit_bibleclass',
    'delete_bibleclass',
    'assign_bibleclass_leader',
    'remove_bibleclass_leader',
    'view_church',
    'edit_church',
    'delete_church',
    'view_classgroup',
    'edit_classgroup',
    'delete_classgroup',
    'view_organization',
    'edit_organization',
    'delete_organization',
    'view_visitor',
    'edit_visitor',
    'convert_visitor',
    'view_role',
    'edit_role',
    'view_permission',
    'edit_permission',
    'view_user',
    'edit_user',
    'access_user_dashboard',
    'access_dashboard',
    'view_profile',
    'edit_profile',
    'send_bulk_sms',
    'view_sms_logs',
    'sms_provider_settings',
    'manage_sms_templates',
    'view_health',
    'edit_health',
    'view_health_detail',
    'view_transfer',
    'edit_transfer',
    'view_event',
    'edit_event',
    'view_event_registration',
    'edit_event_registration',
    'view_event_type',
    'edit_event_type',
    'view_feedback',
    'edit_feedback',
    'view_member_feedback',
    'edit_member_feedback',
    'view_audit',
    'edit_audit',
    'view_user_audit',
    'edit_user_audit',
    'view_event_calendar',
    'access_event_calendar_debug',
    'api_manage_permissions',
    'api_manage_roles',
    'access_dashboard'
];

// Remove all old permissions
$conn->query('DELETE FROM role_permissions');
$conn->query('DELETE FROM permissions');
$conn->query('ALTER TABLE permissions AUTO_INCREMENT = 1');

// Insert new permissions
foreach ($permissions as $perm) {
    $stmt = $conn->prepare("INSERT INTO permissions (name) VALUES (?)");
    $stmt->bind_param('s', $perm);
    $stmt->execute();
}
echo "Permissions table reseeded with new comprehensive list (".count($permissions)." permissions).\n";
