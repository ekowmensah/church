<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Only allow logged-in users
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Canonical permission check with robust super admin bypass
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
if (!$is_super_admin && !has_permission('access_ajax_bulk_members')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

// Returns a preview table of members for bulk payment by class or organization
$type = $_GET['type'] ?? '';
$value = intval($_GET['value'] ?? 0);
$church_id = intval($_GET['church_id'] ?? 0);
if (!$type || !$value || !$church_id) {
    echo '<div class="alert alert-warning">Invalid filter.</div>';
    exit;
}
// Build the query filter
if ($type === 'id') {
    $where = "church_id = $church_id AND status = 'active' AND id = $value";
} else {
    $where = "church_id = $church_id AND status = 'active'";
    if ($type === 'class') {
        $where .= " AND class_id = $value";
    } elseif ($type === 'org') {
        // Try organization_id, fallback to org_id, else show warning
        $org_col = null;
        $cols = $conn->query("SHOW COLUMNS FROM members");
        while($col = $cols->fetch_assoc()) {
            if ($col['Field'] === 'organization_id') $org_col = 'organization_id';
            if ($col['Field'] === 'org_id') $org_col = 'org_id';
        }
        if ($org_col) {
            $where .= " AND $org_col = $value";
        } else {
            echo '<div class="alert alert-warning">Organization filtering is not available: no organization_id/org_id column in members table.</div>';
            exit;
        }
    }
}
$res = $conn->query("SELECT id, crn, first_name, last_name, gender, phone FROM members WHERE $where ORDER BY last_name, first_name");
if (!$res || $res->num_rows == 0) {
    echo '<div class="alert alert-info">No active members found for selection.</div>';
    exit;
}
// Fetch all payment types for per-member select
$all_payment_types = [];
$ptq = $conn->query('SELECT id, name FROM payment_types WHERE active=1 ORDER BY name ASC');
while($pt = $ptq->fetch_assoc()) {
    $all_payment_types[] = $pt;
}
if ($type === 'id') {
    // Only output the single <tr> for the member, or the full table if first=1 is set
    $m = $res->fetch_assoc();
    if ($m) {
        $is_first = isset($_GET['first']) && $_GET['first'] == '1';
        if ($is_first) {
            // Return full table structure
            echo '<table class="table table-bordered table-sm table-hover">';
            echo '<thead><tr><th>#</th><th>CRN</th><th>Name</th><th>Phone</th><th>Payment Type & Amounts</th><th></th></tr></thead><tbody>';
        }
        echo '<tr>';
        echo '<td></td>'; // Row number, to be filled by JS
        echo '<td>' . htmlspecialchars($m['crn']) . '</td>';
        // Move hidden input inside the Name cell
        echo '<td>' . htmlspecialchars($m['last_name'] . ' ' . $m['first_name']) . '<input type="hidden" name="member_ids[]" value="' . $m['id'] . '"></td>';
        echo '<td>' . htmlspecialchars($m['phone']) . '</td>';
        echo '<td>';
        echo '<div class="card bg-light rounded shadow-sm mb-0">';
        echo '<div class="card-body py-2 px-2">';
        echo '<div class="d-flex align-items-center mb-2">';
        echo '<label class="mb-0 mr-2 font-weight-bold" style="min-width:90px;">Type(s):</label>';
        echo '<div class="input-group payment-type-search-group mb-2" id="payment-type-search-group-'.$m['id'].'" data-member-id="'.$m['id'].'">';
        echo '<input type="text" class="form-control form-control-sm payment-type-search" id="payment-type-search-'.$m['id'].'" placeholder="Search payment types..." autocomplete="off" data-member-id="'.$m['id'].'">';
        echo '<div class="input-group-append">';
        echo '<button class="btn btn-outline-secondary btn-sm payment-type-search-btn" type="button"><i class="fa fa-search"></i></button>';
        echo '</div>';
        echo '</div>';
        echo '<div class="member-amount-fields" id="amount-fields-'.$m['id'].'"></div>';
        echo '</div>';
        echo '</div>';
        echo '</td>';
        echo '<td style="text-align:center; vertical-align:middle;">';
        echo '<button type="button" class="btn btn-link text-danger remove-member-btn" data-member-id="' . $m['id'] . '" title="Remove member"><i class="fa fa-trash"></i></button>';
        echo '</td>';
        echo '</tr>';
        if ($is_first) {
            echo '</tbody></table>';
        }
        // JS: After adding/removing rows, renumber the # column for all rows
    }
} else {
    // Output the full table as before
    echo '<table class="table table-bordered table-sm table-hover">';
    echo '<thead><tr><th>#</th><th>CRN</th><th>Name</th><th>Phone</th><th>Payment Type & Amounts</th></tr></thead><tbody>';
    $i = 1;
    while($m = $res->fetch_assoc()) {
        echo '<tr>';
        echo '<td>' . $i . '</td>';
        echo '<td>' . htmlspecialchars($m['crn']) . '</td>';
        echo '<td>' . htmlspecialchars($m['last_name'] . ' ' . $m['first_name']) . '</td>';
        echo '<td>' . htmlspecialchars($m['phone']) . '</td>';
        echo '<td>';
        echo '<div class="card bg-light rounded shadow-sm mb-0">';
        echo '<div class="card-body py-2 px-2">';
        echo '<div class="d-flex align-items-center mb-2">';
        echo '<label class="mb-0 mr-2 font-weight-bold" style="min-width:90px;">Type(s):</label>';
        echo '<div class="input-group payment-type-search-group mb-2" id="payment-type-search-group-'.$m['id'].'" data-member-id="'.$m['id'].'">';
        echo '<input type="text" class="form-control form-control-sm payment-type-search" id="payment-type-search-'.$m['id'].'" placeholder="Search payment types..." autocomplete="off" data-member-id="'.$m['id'].'">';
        echo '<div class="input-group-append">';
        echo '<button class="btn btn-outline-secondary btn-sm payment-type-search-btn" type="button"><i class="fa fa-search"></i></button>';
        echo '</div>';
        echo '</div>';
        echo '<div class="member-amount-fields" id="amount-fields-'.$m['id'].'"></div>';
        echo '</div>';
        echo '</div>';
        echo '</td>';
        echo '<td style="text-align:center; vertical-align:middle;">';
        echo '<button type="button" class="btn btn-link text-danger remove-member-btn" data-member-id="' . $m['id'] . '" title="Remove member"><i class="fa fa-trash"></i></button>';
        echo '</td>';
        echo '<input type="hidden" name="member_ids[]" value="' . $m['id'] . '">';
        echo '</tr>';
        $i++;
    }
    echo '</tbody></table>';
}
