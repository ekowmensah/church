<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/sms_templates.php';
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in() || !(isset($_SESSION['role_id']) && ($_SESSION['role_id'] == 1 || has_permission('send_bulk_sms')))) {
    http_response_code(403);
    die("Forbidden: You do not have permission to access this page.");
}
$churches = $conn->query('SELECT id, name FROM churches ORDER BY name');
$classes = $conn->query('SELECT id, name FROM bible_classes ORDER BY name');
$templates = $conn->query('SELECT * FROM sms_templates ORDER BY name');
ob_start();
?>
<div class="container-fluid py-4">
  <div class="row justify-content-center">
    <div class="col-lg-8 col-md-10">
      <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white d-flex align-items-center">
          <i class="fas fa-sms mr-2"></i>
          <h4 class="mb-0">Bulk SMS</h4>
        </div>
        <div class="card-body">
          <form id="bulkSmsForm" method="post" action="send_bulk_sms.php" autocomplete="off">
            <div class="form-row">
              <div class="form-group col-md-6">
                <label><strong>Recipient Type</strong></label>
                <select class="form-control" id="recipient_type" name="recipient_type" required>
                  <option value="">-- Select --</option>
                  <option value="class">Class</option>
                  <option value="church">Church</option>
                  <option value="all">All Members (by Church)</option>
                  <option value="custom">Custom List</option>
                </select>
                <small class="form-text text-muted">Choose who should receive this SMS.</small>
              </div>
              <div class="form-group col-md-6" id="classSelectGroup" style="display:none;">
                <label>Class(es)</label>
                <input type="text" class="form-control mb-1" id="classSearch" placeholder="Search classes...">
                <select class="form-control" name="class_ids[]" id="class_ids" multiple size="6">
                  <?php if ($classes) while($c = $classes->fetch_assoc()): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                  <?php endwhile; ?>
                </select>
                <span id="classCountBadge" class="badge badge-secondary mt-1" style="display:none;">0 selected</span>
              </div>
              <div class="form-group col-md-6" id="churchSelectGroup" style="display:none;">
                <label>Church(es)</label>
                <input type="text" class="form-control mb-1" id="churchSearch" placeholder="Search churches...">
                <select class="form-control" name="church_ids[]" id="church_ids" multiple size="6">
                  <?php if ($churches) while($ch = $churches->fetch_assoc()): ?>
                    <option value="<?= $ch['id'] ?>"><?= htmlspecialchars($ch['name']) ?></option>
                  <?php endwhile; ?>
                </select>
                <span id="churchCountBadge" class="badge badge-secondary mt-1" style="display:none;">0 selected</span>
              </div>
              <div class="form-group col-md-12" id="customListGroup" style="display:none;">
                <label>Custom Phone List <small class="text-muted">(comma or new-line separated)</small></label>
                <textarea class="form-control" name="custom_phones" id="custom_phones" rows="3" placeholder="e.g. 024XXXXXXX, 020XXXXXXX"></textarea>
              </div>
            </div>
            <hr>
            <div class="form-row">
              <div class="form-group col-md-6">
                <label>Message Template</label>
                <select class="form-control" name="template_id" id="template_id">
                  <option value="">-- No Template (Write Custom) --</option>
                  <?php if ($templates) while($tpl = $templates->fetch_assoc()): ?>
                    <option value="<?= $tpl['id'] ?>" data-body="<?= htmlspecialchars($tpl['body']) ?>"><?= htmlspecialchars($tpl['name']) ?></option>
                  <?php endwhile; ?>
                </select>
              </div>
              <div class="form-group col-md-6">
                <label>Message</label>
                <textarea class="form-control" name="message" id="message" rows="4" required></textarea>
                <small id="templateVarsHelp" class="form-text text-muted">
                  Available variables: <code>{name}</code>, <code>{first_name}</code>, <code>{last_name}</code>, <code>{other_name}</code>, <code>{crn}</code>, <code>{class}</code>, <code>{church}</code>, <code>{phone}</code>
                </small>
              </div>
            </div>
            <div class="text-right">
              <button type="submit" class="btn btn-primary btn-lg px-5"><i class="fas fa-paper-plane mr-2"></i>Send SMS</button>
            </div>
          </form>
          <div id="smsStatus" class="mt-3"></div>
        </div>
      </div>
      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <h5 class="card-title"><i class="fas fa-info-circle mr-2"></i>How Bulk SMS Works</h5>
          <ul class="list-unstyled mb-2">
            <li><i class="fas fa-check-circle text-success mr-1"></i> Choose recipients by class, church, all, or custom.</li>
            <li><i class="fas fa-check-circle text-success mr-1"></i> Use a saved template or write your own message.</li>
            <li><i class="fas fa-check-circle text-success mr-1"></i> Variables like <code>{name}</code>, <code>{crn}</code> in templates will be replaced per member.</li>
            <li><i class="fas fa-check-circle text-success mr-1"></i> You can review delivery status after sending.</li>
          </ul>
          <hr>
          <div class="small text-muted">
            <i class="fas fa-exclamation-triangle text-warning mr-1"></i> Make sure your SMS provider API key is set in <a href="sms_settings.php">SMS Settings</a>.<br>
            <i class="fas fa-info-circle mr-1"></i> For best results, keep messages under 160 characters.
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(function(){
  // Recipient type logic
  $('#recipient_type').change(function(){
    var val = $(this).val();
    $('#classSelectGroup, #churchSelectGroup, #customListGroup').hide();
    if(val === 'class') $('#classSelectGroup').show();
    if(val === 'church' || val === 'all') $('#churchSelectGroup').show();
    if(val === 'custom') $('#customListGroup').show();
  });
  // Search for class
  $('#classSearch').on('keyup', function() {
    var term = $(this).val().toLowerCase();
    $('#class_ids option').each(function() {
      if($(this).text().toLowerCase().indexOf(term) > -1) {
        $(this).show();
      } else {
        $(this).hide();
      }
    });
  });
  // Search for church
  $('#churchSearch').on('keyup', function() {
    var term = $(this).val().toLowerCase();
    $('#church_ids option').each(function() {
      if($(this).text().toLowerCase().indexOf(term) > -1) {
        $(this).show();
      } else {
        $(this).hide();
      }
    });
  });
  // Count badges
  $('#class_ids').on('change', function() {
    var count = $(this).val() ? $(this).val().length : 0;
    if(count > 0) {
      $('#classCountBadge').show().text(count + ' selected');
    } else {
      $('#classCountBadge').hide();
    }
  });
  $('#church_ids').on('change', function() {
    var count = $(this).val() ? $(this).val().length : 0;
    if(count > 0) {
      $('#churchCountBadge').show().text(count + ' selected');
    } else {
      $('#churchCountBadge').hide();
    }
  });
  // Template auto-fill
  $('#template_id').change(function(){
    var body = $('#template_id option:selected').data('body') || '';
    $('#message').val(body);
    var matches = body.match(/{(\w+)}/g);
    if(matches) {
      $('#templateVarsHelp').text('Variables: ' + matches.join(', '));
    } else {
      $('#templateVarsHelp').text('Available variables: {name}, {first_name}, {last_name}, {other_name}, {crn}, {class}, {church}, {phone}');
    }
  });
  // AJAX submit
  $('#bulkSmsForm').submit(function(e){
    e.preventDefault();
    var form = this;
    var formData = $(form).serialize();
    $('#smsStatus').html('<div class="alert alert-info">Sending...</div>');
    $.post('send_bulk_sms.php', formData, function(resp){
      $('#smsStatus').html('<div class="alert alert-success">'+resp+'</div>');
    }).fail(function(xhr){
      $('#smsStatus').html('<div class="alert alert-danger">'+(xhr.responseText||'Error sending SMS')+'</div>');
    });
  });
  // Trigger initial count
  $('#class_ids').trigger('change');
  $('#church_ids').trigger('change');
});
</script>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
