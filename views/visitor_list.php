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

if (!$is_super_admin && !has_permission('view_visitor_list')) {
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
$can_add = $is_super_admin || has_permission('create_visitor');
$can_edit = $is_super_admin || has_permission('edit_visitor');
$can_delete = $is_super_admin || has_permission('delete_visitor');
$can_view = true; // Already validated above

// Add church name lookup
$visitors = $conn->query("SELECT v.*, m.crn AS invited_crn, CONCAT(m.last_name, ' ', m.first_name, ' ', m.middle_name) AS invited_name FROM visitors v LEFT JOIN members m ON v.invited_by = m.id ORDER BY v.visit_date DESC, v.id DESC");
ob_start();
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/visitor_list_custom.css?v=2">
<style>
  .dataTables_filter input { border-radius: 20px; border: 1px solid #ced4da; }
</style>

<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap4.min.js"></script>
<script>
$(function(){
  $('#visitorTable').DataTable({
    responsive: false, // Disable responsive to prevent column collapse
    pageLength: 10,
    order: [[5, 'desc']], // Visit Date column index shifted by 1 due to new checkbox column
    language: { search: "<i class='fas fa-search mr-1'></i> Search:" }
  });
  $('[data-toggle="tooltip"]').tooltip();
});
</script>
<div class="container-fluid mt-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div class="d-flex align-items-center mb-2 mb-md-0">
      <h2 class="mb-0 mr-2"><i class="fas fa-user-friends mr-2"></i>Visitors</h2>
      <span class="badge badge-pill badge-info ml-2" style="font-size:1rem;"><i class="fas fa-users mr-1"></i> <?= $visitors ? $visitors->num_rows : 0 ?> Total</span>
    </div>
    <a href="visitor_form.php" class="btn btn-primary shadow-sm <?= !$can_add ? 'disabled' : '' ?>"><i class="fas fa-plus mr-1"></i> Add Visitor</a>
    <button type="button" class="btn btn-success ml-2" id="bulkSmsBtn"><i class="fas fa-sms mr-1"></i> Bulk SMS</button>
  </div>
  <div class="card shadow-sm">
    <div class="card-body">
      <div class="table-responsive">
        <table id="visitorTable" class="table table-hover table-striped table-bordered visitor-table" style="width:100%">
          <thead class="thead-light">
            <tr>
              <th><input type="checkbox" id="selectAllVisitors"></th>
              <th>Name</th>
              <th>Gender</th>
              <th>Phone</th>
              <th>Visit Date</th>
              <th>Invited By</th>
              <th>Purpose</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($visitors && $visitors->num_rows > 0): $i=1; while($v = $visitors->fetch_assoc()): ?>
              <tr class="visitor-table-row">
                <td class="text-center"><input type="checkbox" class="visitor-checkbox" value="<?= $v['id'] ?>" data-name="<?= htmlspecialchars($v['name']) ?>" data-phone="<?= htmlspecialchars($v['phone']) ?>" data-email="<?= htmlspecialchars($v['email']) ?>"></td>
                <td>
                  <span class="font-weight-bold"><?= htmlspecialchars($v['name']) ?></span><br>
                  <span class="text-muted small"> <?= htmlspecialchars($v['email']) ?> </span>
                </td>
                <td><?= $v['gender'] ? htmlspecialchars(ucfirst($v['gender'])) : '-' ?></td>
                <td><a href="tel:<?= htmlspecialchars($v['phone']) ?>" class="text-dark" data-toggle="tooltip" title="Call"><?= htmlspecialchars($v['phone']) ?></a></td>

                <td><?= htmlspecialchars($v['visit_date']) ?></td>
                <td><?php
                  if ($v['invited_name'] && $v['invited_crn']) {
                    echo htmlspecialchars($v['invited_name']) . '<br><span class="text-muted small">' . htmlspecialchars($v['invited_crn']) . '</span>';
                  } elseif ($v['invited_name']) {
                    echo htmlspecialchars($v['invited_name']);
                  } else {
                    echo '-';
                  }
                ?></td>
                <td class="purpose"><?= htmlspecialchars($v['purpose']) ?></td>

                <td class="visitor-action-btns text-nowrap">
                  <button type="button" class="btn btn-sm btn-outline-primary visitor-sms-btn" data-toggle="tooltip" title="Send SMS" data-id="<?= $v['id'] ?>" data-name="<?= htmlspecialchars($v['name']) ?>" data-phone="<?= htmlspecialchars($v['phone']) ?>" data-email="<?= htmlspecialchars($v['email']) ?>"><i class="fas fa-sms"></i></button>
                  <a href="convert_visitor.php?visitor_id=<?= $v['id'] ?>" class="btn btn-sm btn-outline-info" data-toggle="tooltip" title="Convert to Member"><i class="fas fa-user-plus"></i></a>
                  <a href="visitor_form.php?id=<?= $v['id'] ?>" class="btn btn-sm btn-outline-warning <?= !$can_edit ? 'disabled' : '' ?>" data-toggle="tooltip" title="Edit"><i class="fas fa-edit"></i></a>
                  <a href="visitor_delete.php?id=<?= $v['id'] ?>" class="btn btn-sm btn-outline-danger <?= !$can_delete ? 'disabled' : '' ?>" data-toggle="tooltip" title="Delete" onclick="return confirm('Delete this visitor?');"><i class="fas fa-trash"></i></a>
                </td>
              </tr>
            <?php endwhile; else: ?>
              <tr><td colspan="9" class="text-center">No visitors found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php
ob_start();
include 'visitor_sms_modal.php';
$modal_html = ob_get_clean();
$page_content = ob_get_clean();
include '../includes/layout.php';
?>

<script>
$(function() {
  // Select all checkboxes
  $('#selectAllVisitors').on('change', function() {
    $('.visitor-checkbox').prop('checked', $(this).prop('checked'));
  });

  // Open SMS modal for single visitor
  $('.visitor-sms-btn').on('click', function() {
    var name = $(this).data('name');
    var phone = $(this).data('phone');
    var email = $(this).data('email');
    var id = $(this).data('id');
    $('#visitorSmsRecipients').val(name + ' (' + phone + ')');
    $('#visitorSmsRecipientIds').val(id);
    $('#visitorSmsFeedback').removeClass('alert-success alert-danger').addClass('d-none').text('');
    $('#visitorSmsModal').modal('show');
  });

  // Bulk SMS button
  $('#bulkSmsBtn').on('click', function() {
    var checked = $('.visitor-checkbox:checked');
    if (checked.length === 0) {
      alert('Please select at least one visitor.');
      return;
    }
    var names = [];
    var ids = [];
    checked.each(function() {
      names.push($(this).data('name') + ' (' + $(this).data('phone') + ')');
      ids.push($(this).val());
    });
    $('#visitorSmsRecipients').val(names.join(', '));
    $('#visitorSmsRecipientIds').val(ids.join(','));
    $('#visitorSmsFeedback').removeClass('alert-success alert-danger').addClass('d-none').text('');
    $('#visitorSmsModal').modal('show');
  });

  // AJAX send SMS
  $('#visitorSmsForm').on('submit', function(e) {
    e.preventDefault();
    var ids = $('#visitorSmsRecipientIds').val();
    var message = $('#visitorSmsMessage').val();
    $.post('visitor_send_sms.php', { recipient_ids: ids, message: message }, function(resp) {
      var feedback = $('#visitorSmsFeedback');
      if (resp.success) {
        feedback.removeClass('d-none alert-danger').addClass('alert-success').text('SMS sent successfully!');
        setTimeout(function() { $('#visitorSmsModal').modal('hide'); }, 1500);
      } else {
        feedback.removeClass('d-none alert-success').addClass('alert-danger').text(resp.error || 'Failed to send SMS.');
      }
    }, 'json').fail(function() {
      $('#visitorSmsFeedback').removeClass('d-none alert-success').addClass('alert-danger').text('Failed to send SMS.');
    });
  });
});
</script>
