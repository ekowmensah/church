<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

if (!is_logged_in() || (!(isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1) && !has_permission('manage_members'))) {
    http_response_code(403);
    die('You do not have permission to permanently delete members.');
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id > 0) {
    $warnings = [];
    $tables = [
        'payments',
        'attendance',
        'member_feedback',
        'member_organizations',
        'member_classes',
        // Add more as needed
    ];
    try {
        $conn->begin_transaction();
        $conn->query("SET FOREIGN_KEY_CHECKS=0");
        foreach ($tables as $table) {
            try {
                $conn->query("DELETE FROM $table WHERE member_id = $id");
            } catch (mysqli_sql_exception $e) {
                if (strpos($e->getMessage(), 'doesn\'t exist') !== false) {
                    $warnings[] = $table;
                } else {
                    $conn->query("SET FOREIGN_KEY_CHECKS=1");
                    $conn->rollback();
                    $msg = urlencode('Permanent delete failed: ' . $e->getMessage());
                    header('Location: deleted_members_list.php?error=' . $msg);
                    exit;
                }
            }
        }
        $conn->query("DELETE FROM members WHERE id = $id");
        $conn->query("DELETE FROM deleted_members WHERE id = $id");
        $conn->query("SET FOREIGN_KEY_CHECKS=1");
        $conn->commit();
        $msg = 'Member and all related records have been permanently deleted.';
        if (!empty($warnings)) {
            $msg .= ' (Some related tables missing: ' . implode(', ', $warnings) . ')';
        }
        $msg = urlencode($msg);
        header('Location: deleted_members_list.php?deleted=1&info=' . $msg);
        exit;
    } catch (mysqli_sql_exception $e) {
        $conn->query("SET FOREIGN_KEY_CHECKS=1");
        $conn->rollback();
        $msg = urlencode('Permanent delete failed (members): ' . $e->getMessage());
        header('Location: deleted_members_list.php?error=' . $msg);
        exit;
    }
}
header('Location: deleted_members_list.php?error=Invalid+member+ID');
exit;
