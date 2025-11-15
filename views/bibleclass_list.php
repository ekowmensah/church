<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_bibleclass_list')) {
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
$can_add = $is_super_admin || has_permission('create_bibleclass');
$can_edit = $is_super_admin || has_permission('edit_bibleclass');
$can_delete = $is_super_admin || has_permission('delete_bibleclass');
$can_view = true; // Already validated above

// Fetch all bible classes with leader name
$bibleclasses = $conn->query("SELECT bc.*, u.name as leader_name, u.email as leader_email, u.id as leader_user_id, c.name as church_name FROM bible_classes bc LEFT JOIN users u ON bc.leader_id = u.id LEFT JOIN churches c ON bc.church_id = c.id");

// Ensure no output is sent before ob_start()
ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Bible Classes</h1>
    <?php if ($can_add): ?>
    <a href="bibleclass_form.php" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
    <i class="fas fa-plus fa-sm text-white-50"></i> Add Bible Class
    </a>
    <a href="bibleclass_upload.php" class="d-none d-sm-inline-block btn btn-sm btn-success shadow-sm ml-2">
    <i class="fas fa-file-upload fa-sm text-white-50"></i> Bulk Upload
    </a>
    <?php endif; ?>
</div>
<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">Bible Class List</h6>
    </div>
    <div class="card-body">
        <?php if (isset($_GET['added'])): ?>
            <div class="alert alert-success">Bible class added successfully!</div>
        <?php elseif (isset($_GET['updated'])): ?>
            <div class="alert alert-success">Bible class updated successfully!</div>
        <?php elseif (isset($_GET['deleted'])): ?>
            <div class="alert alert-success">Bible class deleted successfully!</div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-bordered" id="bibleclassTable" width="100%" cellspacing="0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Code</th>
                        <th>Leader</th>
                        <th>Church</th>
                        <?php if ($can_edit || $can_delete): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php while($row = $bibleclasses->fetch_assoc()): ?>
                    <tr>
                        <td><?=htmlspecialchars($row['name'])?></td>
                        <td><?=htmlspecialchars($row['code'])?></td>
                        <td>
    <?php if (empty($row['leader_id']) || $row['leader_id'] == 0): ?>
    <button class="btn btn-sm btn-outline-success assign-leader-btn" 
        data-class-id="<?= $row['id'] ?>" 
        data-church-id="<?= $row['church_id'] ?>"
        title="Assign Leader">
        <i class="fas fa-user-plus"></i> Assign
    </button>
<?php else: ?>
    <?php if (!empty($row['leader_name'])): ?>
        <?= htmlspecialchars($row['leader_name']) ?> <small class="text-muted">(<?= htmlspecialchars($row['leader_email']) ?>)</small>
    <?php else: ?>
        <span class="text-danger">Unknown (ID: <?= htmlspecialchars($row['leader_id']) ?>)</span>
    <?php endif; ?>
    <button class="btn btn-sm btn-outline-primary assign-leader-btn ml-2" 
        data-class-id="<?= $row['id'] ?>" 
        data-church-id="<?= $row['church_id'] ?>"
        title="Change Leader">
        <i class="fas fa-user-edit"></i> Change
    </button>
    <button class="btn btn-sm btn-outline-danger remove-leader-btn ml-1" 
        data-class-id="<?= $row['id'] ?>"
        title="Remove Leader">
        <i class="fas fa-user-times"></i>
    </button>
<?php endif; ?>
</td>
                        <td><?=htmlspecialchars($row['church_name'] ?? '')?></td>
                        <?php if ($can_edit || $can_delete): ?>
                        <td>
                            <?php if ($can_edit): ?>
                                <a href="bibleclass_form.php?id=<?=$row['id']?>" class="btn btn-sm btn-info" title="Edit"><i class="fas fa-edit"></i></a>
                            <?php endif; ?>
                            <?php if ($can_delete): ?>
                                <a href="bibleclass_delete.php?id=<?=$row['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this bible class?')" title="Delete"><i class="fas fa-trash"></i></a>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
// Move modal HTML to $modal_html for correct stacking
ob_start();
?>
<div class="modal fade" id="assignLeaderModal" tabindex="-1" role="dialog" aria-labelledby="assignLeaderModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="assignLeaderModalLabel">Assign Bible Class Leader</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <form id="assignLeaderForm" method="post">
        <div class="modal-body">
          <input type="hidden" name="class_id" id="modal-class-id">
          <div class="form-group">
            <label for="leader-user-id">Select User</label>
            <select class="form-control" id="leader-user-id" name="leader_user_id" style="width:100%" required></select>
            <small class="form-text text-muted">Only users with the Class Leader role in this church are shown. Search by name, username, or email.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success">Assign Leader</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php
$modal_html = ob_get_clean();
?>
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
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.full.min.js"></script>
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
<script>
$(document).ready(function() {
    // Remove Leader button click
    $('.remove-leader-btn').on('click', function() {
        var classId = $(this).data('class-id');
        if (confirm('Remove leader from this class?')) {
            $.post('bibleclass_remove_leader.php', {class_id: classId}, function(resp) {
                if (resp.success) {
                    location.reload();
                } else {
                    alert(resp.error || 'Failed to remove leader.');
                }
            }, 'json');
        }
    });
    var bcTable = $('#bibleclassTable').DataTable({
        dom: 'Bfrtip',
        buttons: [
            'copy', 'csv', 'excel', 'pdf', 'print'
        ]
    });

    function bindAssignLeaderBtns() {
        $('.assign-leader-btn').off('click').on('click', function() {
            var classId = $(this).data('class-id');
            var churchId = $(this).data('church-id');
            $('#modal-class-id').val(classId);
            // Init Select2 for member search
            $('#leader-user-id').val(null).trigger('change');
            $('#leader-user-id').select2({
                theme: 'bootstrap4',
                dropdownParent: $('#assignLeaderModal'),
                placeholder: 'Search for user...',
                minimumInputLength: 1,
                ajax: {
                    url: 'ajax_users_by_church.php',
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            q: params.term,
                            church_id: churchId,
                            class_id: classId
                        };
                    },
                    processResults: function(data) {
                        return {
                            results: data.results
                        };
                    },
                    cache: true
                }
            });
            $('#assignLeaderModal').modal('show');
        });
    }
    bindAssignLeaderBtns();
    bcTable.on('draw', function() {
        bindAssignLeaderBtns();
    });

    // Assign Leader form submit
    $('#assignLeaderForm').on('submit', function(e) {
        e.preventDefault();
        var form = $(this);
        $.post('bibleclass_assign_leader.php', form.serialize(), function(resp) {
            console.log('Assign Leader Response:', resp);
            if (resp.success) {
                location.reload();
            } else {
                alert('Assign Leader failed: ' + (resp.error || JSON.stringify(resp)));
            }
        }, 'json').fail(function(xhr, status, error) {
            alert('AJAX error: ' + error + '\n' + xhr.responseText);
            console.error('AJAX error:', status, error, xhr.responseText);
        });
    });
});
</script>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
