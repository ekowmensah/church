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
$referer = $_SERVER['HTTP_REFERER'] ?? '';
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
        $archive_cols = [];
        $archive_result = $conn->query('SHOW COLUMNS FROM deleted_members');
        if ($archive_result) {
            while ($archive_col = $archive_result->fetch_assoc()) {
                $archive_cols[strtolower($archive_col['Field'])] = true;
            }
        }

        $insert_cols = [];
        $insert_vals = [];
        foreach ($member as $col => $value) {
            $normalized_col = strtolower($col);
            if ($normalized_col === 'deleted_at' || !isset($archive_cols[$normalized_col])) {
                continue;
            }
            $insert_cols[] = '`' . str_replace('`', '``', $col) . '`';
            $insert_vals[] = $value === null ? 'NULL' : "'" . $conn->real_escape_string((string) $value) . "'";
        }

        if (empty($insert_cols)) {
            $insert_cols[] = '`id`';
            $insert_vals[] = (int) $member['id'];
            $insert_cols[] = '`status`';
            $insert_vals[] = $member['status'] === null ? 'NULL' : "'" . $conn->real_escape_string((string) $member['status']) . "'";
        }

        $insert_cols[] = '`deleted_at`';
        $insert_vals[] = 'NOW()';

        $cols_sql = implode(',', $insert_cols);
        $vals_sql = implode(',', $insert_vals);
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
