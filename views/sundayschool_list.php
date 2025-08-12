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

if (!$is_super_admin && !has_permission('view_sunday_school_list')) {
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
$can_add = $is_super_admin || has_permission('create_sunday_school');
$can_edit = $is_super_admin || has_permission('edit_sunday_school');
$can_delete = $is_super_admin || has_permission('delete_sunday_school');
$can_view = true; // Already validated above

// Pagination setup
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$allowed_sizes = [20,40,60,80,100,'all'];
$page_size = isset($_GET['page_size']) && in_array($_GET['page_size'], $allowed_sizes) ? $_GET['page_size'] : 20;
// Count total records
$total_records = $conn->query("SELECT COUNT(*) as cnt FROM sunday_school")->fetch_assoc()['cnt'];
if (is_numeric($page_size)) {
    $offset = ($page - 1) * intval($page_size);
    $total_pages = ceil($total_records / intval($page_size));
    $sql = "SELECT * FROM sunday_school ORDER BY last_name, first_name, srn LIMIT $offset, $page_size";
} else {
    $offset = 0;
    $total_pages = 1;
    $page = 1;
    $sql = "SELECT * FROM sunday_school ORDER BY last_name, first_name, srn";
}
$sundayschool = $conn->query($sql);

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Sunday School</h1>
    <?php if ($can_add): ?>
    <a href="sundayschool_form.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Add Child</a>
    <?php endif; ?>
</div>
<div class="card shadow mb-4">
    <div class="card-body">
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
            foreach ($_GET as $k => $v) {
                if ($k !== 'page_size' && $k !== 'submit') {
                    echo '<input type="hidden" name="'.htmlspecialchars($k).'" value="'.htmlspecialchars($v).'">';
                }
            }
            ?>
        </form>
        <div class="table-responsive">
            <table id="sundayschoolTable" class="table table-bordered table-hover" width="100%">
                <thead class="thead-light">
                    <tr>
                        <th>Photo</th>
                        <th>SRN</th>
                        <th>Full Name</th>
                        <th>DoB</th>
                        <th>Contact</th>
                        <th>School</th>
                        <th>Father</th>
                        <th>Mother</th>
                        <th>Status</th>
                <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while($row = $sundayschool->fetch_assoc()): ?>
                    <tr>
                        <td><?php if($row['photo']): ?><img src="<?=BASE_URL?>/uploads/sundayschool/<?=rawurlencode($row['photo'])?>" style="height:40px;width:40px;object-fit:cover;border-radius:8px;"/><?php endif; ?></td>
                        <td><?=htmlspecialchars($row['srn'])?></td>
                        <td><?=htmlspecialchars(trim($row['last_name'].' '.$row['middle_name'].' '.$row['first_name']))?></td>
                        <td><?php
                            $dob = $row['dob'];
                            $age = '';
                            if ($dob && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
                                $dob_dt = new DateTime($dob);
                                $now = new DateTime();
                                $years = $now->diff($dob_dt)->y;
                                $age = $years.' yrs';
                            }
                        ?>
                        <?=htmlspecialchars($dob)?><?php if($age): ?> <span class="text-muted small">(<?=$age?>)</span><?php endif; ?></td>
                        <td><?=htmlspecialchars($row['contact'])?></td>
                        <td><?=htmlspecialchars($row['school_attend'])?></td>
                        <td><?=htmlspecialchars($row['father_name'])?><br><small><?=htmlspecialchars($row['father_contact'])?></small></td>
                        <td><?=htmlspecialchars($row['mother_name'])?><br><small><?=htmlspecialchars($row['mother_contact'])?></small></td>
                        <td>
                            <?php if (!empty($row['transferred_at'])): ?>
                                <span class="badge badge-success">Transferred</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Active</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?=BASE_URL?>/views/sundayschool_view.php?id=<?=$row['id']?>" class="btn btn-sm btn-info"><i class="fa fa-eye"></i></a>
                            <?php if ($can_edit): ?>
                            <a href="<?=BASE_URL?>/views/sundayschool_form.php?id=<?=$row['id']?>" class="btn btn-sm btn-warning"><i class="fa fa-edit"></i></a>
                            <?php endif; ?>
                            <?php if ($can_delete): ?>
                            <a href="<?=BASE_URL?>/views/sundayschool_delete.php?id=<?=$row['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this record?');"><i class="fa fa-trash"></i></a>
                            <?php endif; ?>
                            <a href="<?=BASE_URL?>/views/payment_form.php" class="btn btn-sm btn-success"><i class="fa fa-coins"></i> Payment</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <!-- Pagination controls -->
        <nav aria-label="Sunday School pagination">
            <ul class="pagination justify-content-center">
                <?php if ($page > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=1<?= isset($page_size) ? '&page_size='.$page_size : '' ?>">First</a></li>
                    <li class="page-item"><a class="page-link" href="?page=<?= $page-1 ?><?= isset($page_size) ? '&page_size='.$page_size : '' ?>">&laquo; Prev</a></li>
                <?php else: ?>
                    <li class="page-item disabled"><span class="page-link">First</span></li>
                    <li class="page-item disabled"><span class="page-link">&laquo; Prev</span></li>
                <?php endif; ?>
                <?php
                $start = max(1, $page-2);
                $end = min($total_pages, $page+2);
                for ($i = $start; $i <= $end; $i++):
                ?>
                    <li class="page-item<?= $i == $page ? ' active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?><?= isset($page_size) ? '&page_size='.$page_size : '' ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <li class="page-item"><a class="page-link" href="?page=<?= $page+1 ?><?= isset($page_size) ? '&page_size='.$page_size : '' ?>">Next &raquo;</a></li>
                    <li class="page-item"><a class="page-link" href="?page=<?= $total_pages ?><?= isset($page_size) ? '&page_size='.$page_size : '' ?>">Last</a></li>
                <?php else: ?>
                    <li class="page-item disabled"><span class="page-link">Next &raquo;</span></li>
                    <li class="page-item disabled"><span class="page-link">Last</span></li>
                <?php endif; ?>
            </ul>
            <div class="text-center text-muted small mb-3">Page <?= $page ?> of <?= $total_pages ?> (<?= $total_records ?> records)</div>
        </nav>
    </div>
</div>
<?php $page_content = ob_get_clean(); include '../includes/layout.php'; ?>

<!-- DataTables Buttons dependencies (JS & CSS) -->
<link rel="stylesheet" href="<?= BASE_URL ?>/AdminLTE/plugins/datatables-buttons/css/buttons.bootstrap4.min.css">
<script src="<?= BASE_URL ?>/AdminLTE/plugins/datatables-buttons/js/dataTables.buttons.min.js"></script>
<script src="<?= BASE_URL ?>/AdminLTE/plugins/datatables-buttons/js/buttons.bootstrap4.min.js"></script>
<script src="<?= BASE_URL ?>/AdminLTE/plugins/datatables-buttons/js/buttons.html5.min.js"></script>
<script src="<?= BASE_URL ?>/AdminLTE/plugins/datatables-buttons/js/buttons.print.min.js"></script>
<script src="<?= BASE_URL ?>/AdminLTE/plugins/datatables-buttons/js/buttons.colVis.min.js"></script>

<script>
    var BASE_URL = "<?= addslashes(BASE_URL) ?>";
</script>
<script>
$(document).ready(function() {
    // DataTable for export only, disable paging/filtering
    if (!$.fn.DataTable.isDataTable('#sundayschoolTable')) {
        try {
            $('#sundayschoolTable').DataTable({
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
    }
});
</script>
<!-- <script src="<?= BASE_URL ?>/assets/js/sundayschool_list.js"></script> -->
