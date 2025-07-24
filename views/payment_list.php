<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_payment_list')) {
    http_response_code(403);
    if (file_exists(__DIR__.'/errors/403.php')) {
        include __DIR__.'/errors/403.php';
    } else if (file_exists(dirname(__DIR__).'/views/errors/403.php')) {
        include dirname(__DIR__).'/views/errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    }
    exit;
}

// Set permission flags for UI elements
$can_add = $is_super_admin || has_permission('create_payment');
$can_edit = $is_super_admin || has_permission('edit_payment');
$can_delete = $is_super_admin || has_permission('delete_payment');
$can_view = true; // Already validated above

// Fetch filter options
$bible_classes = $conn->query("SELECT id, name FROM bible_classes ORDER BY name ASC");
$organizations = $conn->query("SELECT id, name FROM organizations ORDER BY name ASC");
$genders = $conn->query("SELECT DISTINCT gender FROM members WHERE gender IS NOT NULL AND gender != ''");
$churches = $conn->query("SELECT id, name FROM churches ORDER BY name ASC");

// Get filter values
$filter_class = $_GET['class_id'] ?? '';
$filter_org = $_GET['organization_id'] ?? '';
$filter_gender = $_GET['gender'] ?? '';
$filter_church = $_GET['church_id'] ?? '';

// Build SQL with filters
$sql = "SELECT p.*, 
    m.crn, m.first_name, m.last_name, m.middle_name, 
    ss.srn, ss.first_name AS ss_first_name, ss.last_name AS ss_last_name, ss.middle_name AS ss_middle_name, 
    pt.name AS payment_type 
FROM payments p
    LEFT JOIN members m ON p.member_id = m.id
    LEFT JOIN sunday_school ss ON p.sundayschool_id = ss.id
    LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
    LEFT JOIN bible_classes bc ON m.class_id = bc.id
    LEFT JOIN member_organizations mo ON mo.member_id = m.id
    LEFT JOIN organizations o ON mo.organization_id = o.id
    WHERE 1";
$params = [];
$types = '';

if ($filter_class) {
    $sql .= " AND m.class_id = ?";
    $params[] = $filter_class;
    $types .= 'i';
}
if ($filter_org) {
    $sql .= " AND mo.organization_id = ?";
    $params[] = $filter_org;
    $types .= 'i';
}
if ($filter_gender) {
    $sql .= " AND m.gender = ?";
    $params[] = $filter_gender;
    $types .= 's';
}
if ($filter_church) {
    $sql .= " AND m.church_id = ?";
    $params[] = $filter_church;
    $types .= 'i';
}
$sql .= " GROUP BY p.id ORDER BY p.payment_date DESC, p.id DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$payments = $stmt->get_result();

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Payments</h1>
    <?php if ($can_add): ?>
<a href="payment_form.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Add Payment</a>
<?php endif; ?>
</div>
<?php if (isset($_GET['added'])): ?>
    <div class="alert alert-success">Payment added successfully!</div>
<?php elseif (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Payment updated successfully!</div>
<?php elseif (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">Payment deleted successfully!</div>
<?php endif; ?>
<div class="card shadow mb-4 border-0 rounded-lg">
    <div class="card-header py-3 bg-white border-bottom-primary">
        <h6 class="m-0 font-weight-bold text-primary">Filter Payments</h6>
    </div>
    <div class="card-body">
        <form method="get" class="form-row align-items-end">
            <div class="form-group col-md-3 mb-3">
                <label for="church_id" class="font-weight-bold">Church</label>
                <select class="form-control custom-select" name="church_id" id="church_id">
                    <option value="">All</option>
                    <?php if ($churches && $churches->num_rows > 0): while($ch = $churches->fetch_assoc()): ?>
                        <option value="<?=$ch['id']?>" <?=($filter_church==$ch['id']?'selected':'')?>><?=htmlspecialchars($ch['name'])?></option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div class="form-group col-md-3 mb-3">
                <label for="class_id" class="font-weight-bold">Bible Class</label>
                <select class="form-control custom-select" name="class_id" id="class_id" <?= !$filter_church ? 'disabled' : '' ?>>
    <option value="">All</option>
    <?php if ($filter_church && $bible_classes && $bible_classes->num_rows > 0): while($cl = $bible_classes->fetch_assoc()): ?>
        <option value="<?=$cl['id']?>" <?=($filter_class==$cl['id']?'selected':'')?>><?=htmlspecialchars($cl['name'])?></option>
    <?php endwhile; endif; ?>
</select>
            </div>
            <div class="form-group col-md-4 mb-3">
                <label for="organization_id" class="font-weight-bold">Organization</label>
                <select class="form-control custom-select" name="organization_id" id="organization_id" <?= !$filter_church ? 'disabled' : '' ?>>
    <option value="">All</option>
    <?php if ($filter_church && $organizations && $organizations->num_rows > 0): while($org = $organizations->fetch_assoc()): ?>
        <option value="<?=$org['id']?>" <?=($filter_org==$org['id']?'selected':'')?>><?=htmlspecialchars($org['name'])?></option>
    <?php endwhile; endif; ?>
</select>
            </div>
            <div class="form-group col-md-3 mb-3">
                <label for="gender" class="font-weight-bold">Gender</label>
                <select class="form-control custom-select" name="gender" id="gender">
                    <option value="">All</option>
                    <?php if ($genders && $genders->num_rows > 0): while($g = $genders->fetch_assoc()): ?>
                        <option value="<?=htmlspecialchars($g['gender'])?>" <?=($filter_gender==$g['gender']?'selected':'')?>><?=htmlspecialchars($g['gender'])?></option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div class="form-group col-md-1 mb-3 d-flex flex-column align-items-stretch">
                <button type="submit" class="btn btn-primary btn-block mb-2">Apply</button>
                <a href="payment_list.php" class="btn btn-outline-secondary btn-block">Clear</a>
            </div>
        </form>
    </div>
</div>
<!-- Dynamic filter JS -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(function() {
    $('#church_id').on('change', function() {
    var churchId = $(this).val();
    // Clear selected class/org
    $('#class_id').val('');
    $('#organization_id').val('');
    // Enable/disable fields
    if (churchId) {
        $('#class_id, #organization_id').prop('disabled', false);
        // Bible Classes
        $.get('views/ajax_get_classes_by_church.php', {church_id: churchId}, function(data) {
            var html = '<option value="">All</option>' + data;
            $('#class_id').html(html);
        });
        // Organizations
        $.get('views/ajax_get_organizations_by_church.php', {church_id: churchId}, function(data) {
            var html = '<option value="">All</option>' + data;
            $('#organization_id').html(html);
        });
    } else {
        // Reset and disable
        $('#class_id, #organization_id').html('<option value="">All</option>').prop('disabled', true);
    }
});
// On page load, disable if no church
if (!$('#church_id').val()) {
    $('#class_id, #organization_id').prop('disabled', true);
}
// Remove any auto-trigger on page load. The fields will only update when church is changed.

});
</script>
<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">Payment List</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover" id="paymentTable" width="100%" cellspacing="0">
                <thead class="thead-light">
                    <tr>
                        <th>CRN</th>
                        <th>Full Name</th>
                        <th>Payment Type</th>
                        <th>Amount Paid</th>
                        <th>Payment Mode</th>
                        <th>Description</th>
                        <th>Date/Time</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while($row = $payments->fetch_assoc()): ?>
                    <tr>
                        <td>
    <?php if (!empty($row['member_id'])): ?>
        <?=htmlspecialchars($row['crn'])?>
    <?php elseif (!empty($row['sundayschool_id'])): ?>
        <?=htmlspecialchars($row['srn'])?>
    <?php else: ?>
        <span class="text-muted">N/A</span>
    <?php endif; ?>
</td>
<td>
    <?php if (!empty($row['member_id'])): ?>
        <?= htmlspecialchars(trim(($row['last_name'] ?? '').' '.($row['first_name'] ?? '').' '.($row['middle_name'] ?? ''))) ?>
    <?php elseif (!empty($row['sundayschool_id'])): ?>
        <?= htmlspecialchars(trim(($row['ss_last_name'] ?? '').' '.($row['ss_first_name'] ?? '').' '.($row['ss_middle_name'] ?? ''))) ?>
    <?php else: ?>
        <span class="text-muted">N/A</span>
    <?php endif; ?>
</td>
                        <td><?=htmlspecialchars($row['payment_type'])?></td>
                        <td>₵<?=number_format($row['amount'],2)?></td>
                        <td><?=isset($row['mode']) ? htmlspecialchars(ucfirst($row['mode'])) : '<span class="text-muted">Offline</span>'?></td>
                        <td><?=htmlspecialchars($row['description'] ?? '')?></td>
                        <td><?=htmlspecialchars($row['payment_date'])?></td>
                        <td>
    <a href="payment_edit.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-warning"><i class="fas fa-edit"></i> Edit</a>
    <a href="payment_delete.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this payment?');"><i class="fas fa-trash"></i> Delete</a>
    <?php
        $is_pending = !empty($row['reversal_requested_at']) && empty($row['reversal_approved_at']);
        $is_reversed = !empty($row['reversal_approved_at']) && empty($row['reversal_undone_at']);
        $can_approve = isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1;
        $can_undo = $can_approve;
    ?>
    <?php if ($is_pending): ?>
        <span class="badge badge-warning">Reversal Pending</span>
        <?php if ($can_approve): ?>
            <a href="payment_reverse.php?id=<?= $row['id'] ?>&action=approve" class="btn btn-sm btn-success" onclick="return confirm('Approve this payment reversal?');"><i class="fas fa-check"></i> Approve Reversal</a>
        <?php endif; ?>
    <?php elseif ($is_reversed): ?>
        <span class="badge badge-danger">Reversed</span>
        <?php if ($can_undo): ?>
            <a href="payment_reverse.php?id=<?= $row['id'] ?>&action=undo" class="btn btn-sm btn-info" onclick="return confirm('Undo this payment reversal?');"><i class="fas fa-undo"></i> Undo</a>
        <?php endif; ?>
    <?php elseif (empty($row['reversal_requested_at'])): ?>
        <a href="payment_reverse.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-secondary" onclick="return confirm('Request reversal for this payment?');"><i class="fas fa-undo"></i> Request Reversal</a>
    <?php endif; ?>
</td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php $page_content = ob_get_clean(); include '../includes/layout.php'; ?>
