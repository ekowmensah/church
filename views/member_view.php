<?php
require_once __DIR__.'/../includes/member_auth.php';
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!(isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1)) {
    if (function_exists('has_permission') && !has_permission('view_member_profile')) {
        die('No permission to view member details.');
    }
}
$id = intval($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT m.*, c.name AS class_name, ch.name AS church_name FROM members m LEFT JOIN bible_classes c ON m.class_id = c.id LEFT JOIN churches ch ON m.church_id = ch.id WHERE m.id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();
$member = $result->fetch_assoc();
if (!$member) { echo '<div class="alert alert-danger m-4">Member not found.</div>'; exit; }
$page_title = 'Member Profile';
ob_start();
?>
<div class="container py-4">
  <div class="row mb-4 align-items-center">
    <div class="col-md-2 text-center">
      <?php if (!empty($member['photo'])): ?>
        <img src="<?= BASE_URL ?>/uploads/members/<?=rawurlencode($member['photo'])?>" class="img-thumbnail mb-2" style="max-width:120px;max-height:120px;">
      <?php else: ?>
        <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center" style="width:120px;height:120px;font-size:2.5rem;"> <i class="fas fa-user"></i> </div>
      <?php endif; ?>
    </div>
    <div class="col-md-7">
      <h2><?=htmlspecialchars(trim($member['last_name'].' '.$member['first_name'].' '.$member['middle_name']))?></h2>
      <p class="mb-1"><strong>CRN:</strong> <?=htmlspecialchars($member['crn'])?></p>
      <p class="mb-1"><strong>Status:</strong> <span class="badge badge-<?=($member['status']==='active'?'success':($member['status']==='pending'?'warning':'secondary'))?> text-capitalize"><?=htmlspecialchars($member['status'])?></span></p>
      <p class="mb-1"><strong>Class:</strong> <?=htmlspecialchars($member['class_name'] ?? '-')?></p>
      <p class="mb-1"><strong>Church:</strong> <?=htmlspecialchars($member['church_name'] ?? '-')?></p>
      <p class="mb-1"><strong>Created At:</strong> <?=htmlspecialchars($member['created_at'])?></p>
    </div>
    <div class="col-md-3 text-right">
      <a href="member_list.php" class="btn btn-outline-secondary mb-2"><i class="fas fa-arrow-left"></i> Back to List</a>
      <div class="mt-2">
        <button class="btn btn-success mb-2" id="viewHealthBtn"><i class="fas fa-notes-medical"></i> Health Records</button>
      </div>
    </div>
  </div>
  <div class="row">
    <div class="col-12">
      <div class="card mb-3">
        <div class="card-header bg-primary text-white"><strong>Personal Information</strong></div>
        <div class="card-body">
          <div class="row">
            <div class="col-md-4">
              <p><strong>CRN:</strong> <?=htmlspecialchars($member['crn'])?></p>
              <p><strong>Surname:</strong> <?=htmlspecialchars($member['last_name'])?></p>
              <p><strong>First Name:</strong> <?=htmlspecialchars($member['first_name'])?></p>
              <p><strong>Other Name:</strong> <?=htmlspecialchars($member['middle_name'])?></p>
              <p><strong>Gender:</strong> <?=htmlspecialchars($member['gender'])?></p>
              <p><strong>Date of Birth:</strong> <?=htmlspecialchars($member['dob'])?></p>
              <p><strong>Day Born:</strong> <?=htmlspecialchars($member['day_born'])?></p>
              <p><strong>Place of Birth:</strong> <?=htmlspecialchars($member['place_of_birth'])?></p>
            </div>
            <div class="col-md-4">
              <p><strong>Location Address:</strong> <?=htmlspecialchars($member['address'])?></p>
              <p><strong>GPS Address:</strong> <?=htmlspecialchars($member['gps_address'])?></p>
              <p><strong>Marital Status:</strong> <?=htmlspecialchars($member['marital_status'])?></p>
              <p><strong>Home Town:</strong> <?=htmlspecialchars($member['home_town'])?></p>
              <p><strong>Region:</strong> <?=htmlspecialchars($member['region'])?></p>
              <p><strong>Phone:</strong> <?=htmlspecialchars($member['phone'])?></p>
              <p><strong>Telephone:</strong> <?=htmlspecialchars($member['telephone'])?></p>
              <p><strong>Email:</strong> <?=htmlspecialchars($member['email'])?></p>
            </div>
            <div class="col-md-4">
              <p><strong>Class:</strong> <?=htmlspecialchars($member['class_name'] ?? '-')?></p>
              <p><strong>Church:</strong> <?=htmlspecialchars($member['church_name'] ?? '-')?></p>
              <p><strong>Status:</strong> <span class="badge badge-<?=($member['status']==='active'?'success':($member['status']==='pending'?'warning':'secondary'))?> text-capitalize"><?=htmlspecialchars($member['status'])?></span></p>
              <p><strong>Membership Status:</strong> <?php
    $is_confirmed = (isset($member['confirmed']) && strtolower($member['confirmed']) === 'yes');
    $is_baptized = (isset($member['baptized']) && strtolower($member['baptized']) === 'yes');
    echo ($is_confirmed && $is_baptized) ? 'Full Member' : 'Cathcumen';
?></p>
              <p><strong>Date of Enrollment:</strong> <?=htmlspecialchars($member['date_of_enrollment'])?></p>
              <p><strong>Total Payments:</strong> <span class="total-payments badge badge-secondary" data-member-id="<?=$member['id']?>">₵0.00</span></p>
              <p><strong>Created At:</strong> <?=htmlspecialchars($member['created_at'])?></p>
            </div>
          </div>
        </div>
      </div>
      <div class="card mb-3">
        <div class="card-header bg-info text-white"><strong>Emergency Contacts</strong></div>
        <div class="card-body">
          <div class="row">
<?php
$contacts = [];
$ecq = $conn->prepare("SELECT name, mobile, relationship FROM member_emergency_contacts WHERE member_id = ?");
$ecq->bind_param('i', $member['id']);
$ecq->execute();
$ecq_res = $ecq->get_result();
while ($row = $ecq_res->fetch_assoc()) $contacts[] = $row;
$ecq->close();
if (count($contacts) === 0): ?>
  <div class="col-12"><span class="text-muted">No emergency contacts recorded.</span></div>
<?php else: ?>
  <?php foreach ($contacts as $i => $c): ?>
    <div class="col-md-6 mb-2">
      <p><strong>Contact <?=($i+1)?> Name:</strong> <?=htmlspecialchars($c['name'])?></p>
      <p><strong>Contact <?=($i+1)?> Mobile:</strong> <?=htmlspecialchars($c['mobile'])?></p>
      <p><strong>Contact <?=($i+1)?> Relationship:</strong> <?=htmlspecialchars($c['relationship'])?></p>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
          </div>
        </div>
      </div>
      <div class="card mb-3">
        <div class="card-header bg-secondary text-white"><strong>Spiritual & Professional Info</strong></div>
        <div class="card-body">
          <div class="row">
            <div class="col-md-4">
              <p><strong>Employment Status:</strong> <?=htmlspecialchars($member['employment_status'])?></p>
              <p><strong>Profession:</strong> <?=htmlspecialchars($member['profession'])?></p>
            </div>
            <div class="col-md-4">
              <p><strong>Baptized:</strong> <?=htmlspecialchars($member['baptized'])?></p>
              <p><strong>Date of Baptism:</strong> <?=htmlspecialchars($member['date_of_baptism'])?></p>
            </div>
            <div class="col-md-4">
              <p><strong>Confirmed:</strong> <?=htmlspecialchars($member['confirmed'])?></p>
              <p><strong>Date of Confirmation:</strong> <?=htmlspecialchars($member['date_of_confirmation'])?></p>
            </div>
          </div>
        </div>
      </div>
      <div class="card mb-3">
        <div class="card-header bg-dark text-white"><strong>Organizations</strong></div>
        <div class="card-body">
          <?php
          $orgs = $conn->query("SELECT o.name FROM organizations o JOIN member_organizations mo ON o.id = mo.organization_id WHERE mo.member_id = ".intval($member['id']));
          if ($orgs->num_rows > 0):
            echo '<ul class="mb-0">';
            while($o = $orgs->fetch_assoc()) echo '<li>'.htmlspecialchars($o['name']).'</li>';
            echo '</ul>';
          else:
            echo '<span class="text-muted">None</span>';
          endif;
          ?>
        </div>
      </div>
    </div>
  </div>

  <div class="card mt-4">
    <div class="card-header bg-secondary text-white font-weight-bold"><i class="fas fa-sms mr-2"></i>Registration & Transfer SMS Notifications</div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-bordered table-sm">
          <thead>
            <tr>
              <th>Type</th>
              <th>Date/Time</th>
              <th>Message</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php
          $sms_stmt = $conn->prepare("SELECT * FROM sms_logs WHERE phone = ? AND type IN ('registration','transfer') ORDER BY sent_at DESC");
          $sms_stmt->bind_param('s', $member['phone']);
          $sms_stmt->execute();
          $sms_res = $sms_stmt->get_result();
          while ($sms = $sms_res->fetch_assoc()): ?>
            <tr>
              <td><?=htmlspecialchars(ucfirst($sms['type']))?></td>
              <td><?=htmlspecialchars($sms['sent_at'])?></td>
              <td style="max-width:300px;overflow:auto;word-break:break-word;">
                <?=htmlspecialchars($sms['message'])?>
              </td>
              <td>
                <?php
                $status = $sms['status'] ?? '';
                if (stripos($status, 'fail') !== false) {
                  echo '<span class="badge badge-danger">Failed</span>';
                } elseif (stripos($status, 'sent') !== false || stripos($status, 'success') !== false) {
                  echo '<span class="badge badge-success">Sent</span>';
                } else {
                  echo '<span class="badge badge-secondary">' . htmlspecialchars($status) . '</span>';
                }
                ?>
              </td>
              <td>
                <?php if (isset($sms['status']) && stripos($sms['status'], 'fail') !== false): ?>
                  <button class="btn btn-sm btn-warning resend-sms-btn ml-2" data-log-id="<?=intval($sms['id'])?>">Resend</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>
<!-- Health Records Modal -->
<div class="modal fade" id="healthModal" tabindex="-1" role="dialog" aria-labelledby="healthModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="healthModalLabel">Health Records</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body" id="healthRecordsBody">
        <div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i> Loading...</div>
      </div>
    </div>
  </div>
</div>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script>
// Fetch total payments
$(function() {
  var span = $('.total-payments');
  var memberId = span.data('member-id');
  $.get('ajax_get_total_payments.php', {member_id: memberId}, function(res) {
    if (res && typeof res.total !== 'undefined') {
      span.text('₦' + parseFloat(res.total).toLocaleString(undefined, {minimumFractionDigits: 2}));
    }
  }, 'json');
});
// Health Records modal (robust binding)
$(document).on('click', '#viewHealthBtn', function() {
  var memberId = $('.total-payments').data('member-id');
  $('#healthRecordsBody').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i> Loading...</div>');
  $('#healthModal').modal('show');
  $.get('ajax_get_health_records.php', {member_id: memberId}, function(html) {
    $('#healthRecordsBody').html(html);
    if ($('#healthRecordsTable').length) {
      $('#healthRecordsTable').DataTable();
    }
  });
});
</script>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
?>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    var span = $('.total-payments');
    var memberId = span.data('member-id');
    $.get('ajax_get_total_payments.php', {member_id: memberId}, function(res) {
        if (res && typeof res.total !== 'undefined') {
            var total = parseFloat(res.total);
            span.text('₵' + total.toLocaleString(undefined, {minimumFractionDigits: 2}));
            if (total > 0) {
                span.removeClass('badge-secondary').addClass('badge-success');
            } else {
                span.removeClass('badge-success').addClass('badge-secondary');
            }
        }
    }, 'json');
});
</script>
