<?php
// Archive (soft-delete) member to deleted_members, never delete from members table
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

// Permission check
if (!is_logged_in() || (!(isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1) && !has_permission('delete_member'))) {
    http_response_code(403);
    die('You do not have permission to delete members.');
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id > 0) {
    // Only allow archiving of members with status 'pending' or 'de-activated'
    $reg_check = $conn->query("SELECT status FROM members WHERE id = $id");
    $reg_row = $reg_check ? $reg_check->fetch_assoc() : null;
    if (!$reg_row || !in_array($reg_row['status'], ['pending','de-activated'])) {
        // Abort: cannot archive active/registered member
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $msg = urlencode('Only pending or de-activated members can be deleted.');
        if (strpos($referer, 'register_member.php') !== false) {
            header('Location: register_member.php?error=' . $msg);
        } else {
            header('Location: member_list.php?error=' . $msg);
        }
        exit;
    }
    // Archive member to deleted_members, but NEVER delete from members table
    $member = $conn->query("SELECT * FROM members WHERE id = $id")->fetch_assoc();
    if ($member) {
        $cols = array_keys($member);
        $cols_sql = '`' . implode('`,`', $cols) . '`,deleted_at';
        $vals = array_map(function($v) use ($conn) { return $v === null ? 'NULL' : "'".$conn->real_escape_string($v)."'"; }, array_values($member));
        $vals_sql = implode(',', $vals) . ",NOW()";
        $ins = $conn->query("INSERT INTO deleted_members ($cols_sql) VALUES ($vals_sql)");
        if ($ins) {
            // Also set member status to 'deleted' in members table
            $update = $conn->query("UPDATE members SET status = 'deleted' WHERE id = $id");
            if (!$update) {
                $msg = urlencode('Member archived but failed to update status to deleted: ' . $conn->error);
                header('Location: member_list.php?error=' . $msg);
                exit;
            }
            $msg = urlencode('Member archived to deleted_members and deleted.');
            if (strpos($referer, 'register_member.php') !== false) {
                header('Location: register_member.php?info=' . $msg);
            } else {
                header('Location: member_list.php?info=' . $msg);
            }
            exit;
        } else {
            $msg = urlencode('Could not archive member to deleted_members.');
            header('Location: member_list.php?error=' . $msg);
            exit;
        }
    }
} // End if ($id > 0)

// No trailing code should ever run after above.

/*
BEST PRACTICES:
- All tables referencing members (payments, attendance, etc) should use ON DELETE RESTRICT or ON DELETE SET NULL (never ON DELETE CASCADE) to avoid accidental data loss.
- Always filter out status='deleted' in member list queries unless you want to audit deleted members.
- If you want to allow restore, add a 'restore' action to set status back to 'pending' or 'active'.
*/
