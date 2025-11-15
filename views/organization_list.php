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

if (!$is_super_admin && !has_permission('view_organization_list')) {
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
$can_add = $is_super_admin || has_permission('create_organization');
$can_edit = $is_super_admin || has_permission('edit_organization');
$can_delete = $is_super_admin || has_permission('delete_organization');
$can_view = true; // Already validated above

$result = $conn->query("SELECT o.*, c.name AS church_name FROM organizations o LEFT JOIN churches c ON o.church_id = c.id ORDER BY o.name ASC");
ob_start();
?>

<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
<style>
  .dataTables_filter input { border-radius: 20px; border: 1px solid #ced4da; }
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.full.min.js"></script>
<script>
$(function(){
  $('[data-toggle="tooltip"]').tooltip();
});
</script>
<div class="container-fluid mt-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div class="d-flex align-items-center mb-2 mb-md-0">
      <h2 class="mb-0 mr-2"><i class="fas fa-building mr-2"></i>Organizations</h2>
      <span class="badge badge-pill badge-info ml-2" style="font-size:1rem;"><i class="fas fa-building mr-1"></i> <?= $result ? $result->num_rows : 0 ?> Total</span>
    </div>
    <?php if ($can_add): ?>
    <a href="organization_form.php" class="btn btn-primary shadow-sm"><i class="fas fa-plus mr-1"></i> Add Organization</a>
    <?php endif; ?>
  </div>
  <div class="card shadow-sm">
    <div class="card-body">
      <div class="table-responsive">
        <table id="organizationTable" class="table table-hover table-striped table-bordered organization-table" style="width:100%">
          <thead class="thead-light">
            <tr>
              <th>Name</th>
              <th>Church</th>
              <th>Description</th>
              <th>Leader</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($result && $result->num_rows > 0): $i=1; while($row = $result->fetch_assoc()): ?>
              <tr class="organization-table-row">
                <td><?= htmlspecialchars($row['name']) ?></td>
                <td><?= htmlspecialchars($row['church_name'] ?? '-') ?></td>
                <td><?= htmlspecialchars($row['description']) ?></td>
                <td>
                  <?php if (empty($row['leader_id'])): ?>
                    <button class="btn btn-sm btn-outline-success assign-leader-btn" 
                        data-org-id="<?= $row['id'] ?>"
                        data-church-id="<?= $row['church_id'] ?>"
                        data-toggle="tooltip" title="Assign Leader">
                        <i class="fas fa-user-plus"></i> Assign
                    </button>
                  <?php else: ?>
                    <?php
                    // Fetch leader's name/email
                    $leader = null;
                    if ($row['leader_id']) {
                        $stmt = $conn->prepare('SELECT name, email FROM users WHERE id = ?');
                        $stmt->bind_param('i', $row['leader_id']);
                        $stmt->execute();
                        $stmt->bind_result($leader_name, $leader_email);
                        if ($stmt->fetch()) {
                            $leader = ['name' => $leader_name, 'email' => $leader_email];
                        }
                        $stmt->close();
                    }
                    ?>
                    <?php if ($leader): ?>
                      <?= htmlspecialchars($leader['name']) ?> <small class="text-muted">(<?= htmlspecialchars($leader['email']) ?>)</small><br>
                    <?php else: ?>
                      <span class="text-danger">Unknown (ID: <?= htmlspecialchars($row['leader_id']) ?>)</span><br>
                    <?php endif; ?>
                    <button class="btn btn-sm btn-outline-primary assign-leader-btn" 
                        data-org-id="<?= $row['id'] ?>"
                        data-church-id="<?= $row['church_id'] ?>"
                        data-toggle="tooltip" title="Change Leader">
                        <i class="fas fa-user-edit"></i> Change
                    </button>
                    <button class="btn btn-sm btn-outline-danger remove-leader-btn" 
                        data-org-id="<?= $row['id'] ?>"
                        data-toggle="tooltip" title="Remove Leader">
                        <i class="fas fa-user-times"></i>
                    </button>
                  <?php endif; ?>
                </td>
                <td class="organization-action-btns text-nowrap">
                  <?php if ($can_edit): ?>
                  <a href="organization_edit.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-warning" data-toggle="tooltip" title="Edit"><i class="fas fa-edit"></i></a>
                  <?php endif; ?>
                  <?php if ($can_delete): ?>
                  <a href="organization_delete.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-danger" data-toggle="tooltip" title="Delete" onclick="return confirm('Delete this organization?');"><i class="fas fa-trash"></i></a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endwhile; else: ?>
              <tr><td colspan="5" class="text-center">No organizations found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php
ob_start();
include 'organization_assign_leader_modal.php';
$modal_html = ob_get_clean();
$page_content = ob_get_clean();
include '../includes/layout.php';
?>

<script>
$(function() {
    // Initialize Select2 for leader assignment
    $('#org-leader-user-id').select2({
        theme: 'bootstrap4',
        dropdownParent: $('#assignOrgLeaderModal'),
        placeholder: 'Search for user...',
        minimumInputLength: 1,
        ajax: {
            url: 'ajax_users_by_organization.php',
            dataType: 'json',
            delay: 250,
            data: function(params) {
                var orgId = $('#modal-org-id').val();
                var churchId = $('.assign-leader-btn[data-org-id="' + orgId + '"]').data('church-id');
                return {
                    q: params.term,
                    org_id: orgId,
                    church_id: churchId
                };
            },
            processResults: function(data) {
                return { results: data.results || [] };
            },
            cache: true
        }
    });

    // Assign Leader button click
    $('.assign-leader-btn').on('click', function() {
        var orgId = $(this).data('org-id');
        var churchId = $(this).data('church-id');
        $('#modal-org-id').val(orgId);
        $('#modal-church-id').val(churchId);
        $('#org-leader-user-id').val(null).trigger('change');
        $('#assignOrgLeaderModal').modal('show');
    });

    // Assign Leader form submit
    $('#assignOrgLeaderForm').on('submit', function(e) {
        e.preventDefault();
        var form = $(this);
        $.post('organization_assign_leader.php', form.serialize(), function(resp) {
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

    // Remove Leader button click
    $('.remove-leader-btn').on('click', function() {
        var orgId = $(this).data('org-id');
        if (confirm('Remove leader from this organization?')) {
            $.post('organization_remove_leader.php', {org_id: orgId}, function(resp) {
                if (resp.success) {
                    location.reload();
                } else {
                    alert(resp.error || 'Failed to remove leader.');
                }
            }, 'json');
        }
    });
});
</script>
