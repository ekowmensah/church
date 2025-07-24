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

if (!$is_super_admin && !has_permission('view_member')) {
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
$can_add = $is_super_admin || has_permission('create_member');
$can_edit = $is_super_admin || has_permission('edit_member');
$can_delete = $is_super_admin || has_permission('delete_member');
$can_view = true; // Already validated above

// Modal accumulator for all modals in this file
$modal_html = '';

// Pagination setup
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$allowed_sizes = [20,40,60,80,100,'all'];
$page_size = isset($_GET['page_size']) && (in_array($_GET['page_size'], $allowed_sizes)) ? $_GET['page_size'] : 20;

// Count total active members
$total_members = $conn->query("SELECT COUNT(*) as cnt FROM members WHERE status = 'active'")->fetch_assoc()['cnt'];

if (is_numeric($page_size)) {
    $offset = ($page - 1) * intval($page_size);
    $total_pages = ceil($total_members / intval($page_size));
} else {
    // 'all' selected
    $offset = 0;
    $total_pages = 1;
    $page = 1;
}

// Fetch paginated members with class and church names
if ($page_size === 'all') {
    $members = $conn->query("SELECT m.*, c.name AS class_name, ch.name AS church_name FROM members m LEFT JOIN bible_classes c ON m.class_id = c.id LEFT JOIN churches ch ON m.church_id = ch.id WHERE m.status = 'active' ORDER BY m.last_name ASC, m.first_name ASC, m.middle_name ASC");
    $total_pages = 1;
    $page = 1;
} else {
    $members = $conn->query("SELECT m.*, c.name AS class_name, ch.name AS church_name FROM members m LEFT JOIN bible_classes c ON m.class_id = c.id LEFT JOIN churches ch ON m.church_id = ch.id WHERE m.status = 'active' ORDER BY m.last_name ASC, m.first_name ASC, m.middle_name ASC LIMIT $offset, $page_size");
}

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <?php if ($can_add): ?>
        <a href="member_form.php" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
            <i class="fas fa-plus fa-sm text-white-50"></i> Add Member
        </a>
        <a href="member_upload.php" class="d-none d-sm-inline-block btn btn-sm btn-success shadow-sm ml-2">
            <i class="fas fa-file-upload fa-sm text-white-50"></i> Bulk Upload
        </a>
        <a href="deleted_members_list.php" class="d-none d-sm-inline-block btn btn-sm btn-danger shadow-sm ml-2">
            <i class="fas fa-trash-alt fa-sm text-white-50"></i> Deleted Members
        </a>
    <?php endif; ?>
</div>
<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">Member List</h6>
    </div>
    <div class="card-body">
        <?php if (!empty($_SESSION['flash_success'])): ?>
            <div class="alert alert-success alert-dismissible fade show"> <?= htmlspecialchars($_SESSION['flash_success']) ?> <button type="button" class="close" data-dismiss="alert">&times;</button></div>
            <?php unset($_SESSION['flash_success']); ?>
        <?php elseif (!empty($_SESSION['flash_error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show"> <?= htmlspecialchars($_SESSION['flash_error']) ?> <button type="button" class="close" data-dismiss="alert">&times;</button></div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>
        <?php if (isset($_GET['added'])): ?>
            <div class="alert alert-success">Member added successfully!</div>
        <?php elseif (isset($_GET['updated'])): ?>
            <div class="alert alert-success">Member updated successfully!</div>
        <?php elseif (isset($_GET['deleted'])): ?>
            <div class="alert alert-success">Member deleted successfully!</div>
        <?php elseif (isset($_GET['deactivated'])): ?>
            <div class="alert alert-warning">Member de-activated successfully!</div>
        <?php endif; ?>
        <form method="get" class="form-inline mb-2">
            <label for="page_size" class="mr-2">Show</label>
            <select name="page_size" id="page_size" class="form-control form-control-sm mr-2" onchange="this.form.submit()">
                <?php foreach ([20,40,60,80,100] as $opt): ?>
                    <option value="<?= $opt ?>"<?= $page_size == $opt ? ' selected' : '' ?>><?= $opt ?></option>
                <?php endforeach; ?>
                <option value="all"<?= $page_size === 'all' ? ' selected' : '' ?>>All</option>
            </select>
            <label class="mr-2">entries</label>
            <?php
            // Preserve other GET params (except page_size)
            foreach ($_GET as $k => $v) {
                if ($k !== 'page_size' && $k !== 'submit') {
                    echo '<input type="hidden" name="'.htmlspecialchars($k).'" value="'.htmlspecialchars($v).'">';
                }
            }
            ?>
        </form>
        <div class="table-responsive">
            <table class="table table-bordered" id="memberTable" width="100%" cellspacing="0">
                <thead>
                    <tr>
                        <th>Photo</th>
                        <th>CRN</th>
                        <th>Full Name</th>
                        <th>Phone</th>
                        <th>Bible Class</th>
                        <th>Day Born</th>
                        <th>Gender</th>
                        <th>Status</th>
                        <?php if ($can_edit || $can_delete): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $members->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <?php
                                $photo_path = !empty($row['photo']) ? __DIR__.'/../uploads/members/' . $row['photo'] : '';
                                if (!empty($row['photo']) && file_exists($photo_path)) {
                                    $photo_url = BASE_URL . '/uploads/members/' . rawurlencode($row['photo']);
                                } else {
                                    $photo_url = BASE_URL . '/assets/img/undraw_profile.svg';
                                }
                                ?>
                                <img src="<?= $photo_url ?>" alt="photo" height="40" style="border-radius:50%">
                            </td>
                            <td><?=htmlspecialchars($row['crn'])?></td>
                            <td><?=htmlspecialchars(trim($row['last_name'].' '.$row['first_name'].' '.$row['middle_name']))?></td>
                            <td><?=htmlspecialchars($row['phone'])?></td>
                            <td><?=htmlspecialchars($row['class_name'])?></td>
                            <td><?=htmlspecialchars($row['day_born'])?></td>
                            <td><?=htmlspecialchars($row['gender'])?></td>
                            <td>
                                <?php if ($row['status'] == 'active'): ?>
                                    <span class="badge badge-success">Active</span>
                                    <?php if ($can_edit): ?>
                                        <a href="member_deactivate.php?id=<?= $row['id'] ?>" class="btn btn-xxs btn-warning align-baseline px-1 py-0" style="font-size:0.74rem;line-height:1.1;height:20px;vertical-align:baseline;margin-left:4px;" onclick="return confirm('De-activate this member? This will set their status to Pending.')" title="De-Activate"><i class="fas fa-user-slash"></i></a>
                                    <?php endif; ?>
                                <?php elseif ($row['status'] == 'pending' && !empty($row['deactivated_at'])): ?>
                                    <span class="badge badge-danger">De-Activated</span>
                                    <a href="member_activate.php?id=<?= $row['id'] ?>" class="btn btn-xs btn-outline-success ml-1 px-1 py-0 activate-member-btn" title="Activate member" onclick="return confirm('Activate this member?')"><i class="fas fa-user-check"></i></a>
                                <?php elseif ($row['status'] == 'pending'): ?>
                                    <span class="badge badge-warning">Pending</span>
                                    <button type="button" class="btn btn-xs btn-outline-info ml-1 px-1 py-0 resend-token-btn" data-member-id="<?= $row['id'] ?>" title="Resend registration link via SMS"><i class="fas fa-sms"></i></button>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Unknown</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($can_edit || $can_delete): ?>
                                <td class="text-nowrap">
                                    <a href="member_view.php?id=<?=$row['id']?>" class="btn btn-xs btn-primary px-1 py-0" title="View"><i class="fas fa-user"></i></a>
                                    <button type="button" class="btn btn-xs btn-warning px-1 py-0 ml-1" title="Send Message" data-toggle="modal" data-target="#sendMessageModal_<?=$row['id']?>"><i class="fas fa-envelope"></i></button>
                                    <?php
                                    $member_id = $row['id'];
                                    $member_name = trim($row['last_name'].' '.$row['first_name'].' '.$row['middle_name']);
                                    $member_phone = $row['phone'];
                                   
                                    include __DIR__.'/send_message_modal.php';
                                    $modal_html .= ob_get_clean();
                                    ?>
                                    <a href="health_list.php?member_id=<?=$row['id']?>" class="btn btn-xs btn-success px-1 py-0" title="Health"><i class="fas fa-notes-medical"></i></a>
                                    <?php if ($can_edit): ?>
                                        <a href="admin_member_edit.php?id=<?=$row['id']?>" class="btn btn-xs btn-info px-1 py-0" title="Edit"><i class="fas fa-edit"></i></a>
                                        <a href="member_health_records.php?id=<?=$row['id']?>" class="btn btn-xs btn-success px-1 py-0 ml-1" title="Health Records"><i class="fas fa-notes-medical"></i></a>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <!-- Pagination controls -->
        <nav aria-label="Member list pagination">
            <ul class="pagination justify-content-center">
                <?php if ($page > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=1">First</a></li>
                    <li class="page-item"><a class="page-link" href="?page=<?= $page-1 ?>">&laquo; Prev</a></li>
                <?php else: ?>
                    <li class="page-item disabled"><span class="page-link">First</span></li>
                    <li class="page-item disabled"><span class="page-link">&laquo; Prev</span></li>
                <?php endif; ?>
                <?php
                // Show up to 5 pages around current
                $start = max(1, $page-2);
                $end = min($total_pages, $page+2);
                for ($i = $start; $i <= $end; $i++):
                ?>
                    <li class="page-item<?= $i == $page ? ' active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <li class="page-item"><a class="page-link" href="?page=<?= $page+1 ?>">Next &raquo;</a></li>
                    <li class="page-item"><a class="page-link" href="?page=<?= $total_pages ?>">Last</a></li>
                <?php else: ?>
                    <li class="page-item disabled"><span class="page-link">Next &raquo;</span></li>
                    <li class="page-item disabled"><span class="page-link">Last</span></li>
                <?php endif; ?>
            </ul>
            <div class="text-center text-muted small mb-3">Page <?= $page ?> of <?= $total_pages ?> (<?= $total_members ?> members)</div>
        </nav>
    </div>
</div>
<!-- DataTables scripts -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap4.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script>
$(document).ready(function() {
    // DataTable for export only, disable paging/filtering
    try {
        $('#memberTable').DataTable({
            dom: 'Bfrtip',
            paging: false,
            searching: false,
            info: false,
            ordering: true,
            buttons: [
                'copy', 'csv', 'excel', 'pdf', 'print'
            ]
        });
    } catch (e) {
        console.error('DataTable init failed:', e);
    }
    // AJAX fetch total payments for each member
    $('.total-payments').each(function() {
        var span = $(this);
        var memberId = span.data('member-id');
        $.get('ajax_get_total_payments.php', {member_id: memberId}, function(res) {
            console.log('Payments for member', memberId, ':', res);
            if (res && typeof res.total !== 'undefined') {
                span.text('₵' + parseFloat(res.total).toLocaleString(undefined, {minimumFractionDigits: 2}));
            }
        }, 'json');
    });
});
</script>
<script>
$('.resend-token-btn').on('click', function() {
    var btn = $(this);
    var memberId = btn.data('member-id');
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
    $.post('ajax_resend_token_sms.php', {member_id: memberId}, function(res) {
        btn.prop('disabled', false).html('<i class="fas fa-sms"></i>');
        alert('SMS sent: ' + res);
    }).fail(function(xhr) {
        btn.prop('disabled', false).html('<i class="fas fa-sms"></i>');
        var msg = xhr.responseText ? xhr.responseText : 'Failed to send SMS';
        alert('Error: ' + msg);
    });
});
</script>
<style>
.btn-xs {
    padding: 0.14rem 0.34rem !important;
    font-size: 0.89rem !important;
    line-height: 1.15 !important;
    border-radius: 0.22rem !important;
}
#memberTable th, #memberTable td {
    font-size: 1.01rem !important;
    padding-top: 0.38rem !important;
    padding-bottom: 0.38rem !important;
    padding-left: 0.48rem !important;
    padding-right: 0.48rem !important;
}
#memberTable thead th {
    font-size: 0.98rem !important;
    font-weight: 600;
}
#memberTable {
    font-size: 1.01rem !important;
}
</style>
<script>
var BASE_URL = "<?= addslashes(BASE_URL) ?>";
</script>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
