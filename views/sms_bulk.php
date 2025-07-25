<?php
require_once __DIR__.'/../config/config.php';
?>
<link rel="stylesheet" href="sms_bulk_custom.css">
<?php
require_once __DIR__.'/../includes/sms_templates.php';
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in() || !(isset($_SESSION['role_id']) && ($_SESSION['role_id'] == 1 || has_permission('send_bulk_sms')))) {
    http_response_code(403);
    die("Forbidden: You do not have permission to access this page.");
}
$organizations = $conn->query('SELECT id, name FROM organizations ORDER BY name');
$churches = $conn->query('SELECT id, name FROM churches ORDER BY name');
$classes = $conn->query('SELECT id, name FROM bible_classes ORDER BY name');
$templates = $conn->query('SELECT * FROM sms_templates ORDER BY name');
ob_start();
?>
<div class="bulk-sms-container mx-auto">
  <div class="bulk-sms-title">
    <span class="bulk-sms-icon"><i class="fas fa-sms"></i></span> Bulk SMS Sender
  </div>
  <form id="bulkSmsForm" method="post" action="send_bulk_sms.php" autocomplete="off">
    <div class="bulk-sms-section">
      <div class="bulk-sms-label"><i class="fas fa-users bulk-sms-icon"></i>Recipient Type</div>
      <select class="form-control bulk-sms-select" id="recipient_type" name="recipient_type" required>
        <option value="">-- Select --</option>
        <option value="class">Class</option>
        <option value="organization">Organization</option>
        <option value="all">All Members (By Church)</option>
        <option value="custom">Custom List</option>
      </select>
      <div class="bulk-sms-help">Choose who should receive this SMS.</div>
      <div id="classSelectGroup" style="display:none;">
        <div class="bulk-sms-label">Class(es)</div>
        
        <select class="form-control bulk-sms-select" name="class_ids[]" id="class_ids" multiple size="6">
          <?php if ($classes) while($c = $classes->fetch_assoc()): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
          <?php endwhile; ?>
        </select>
        <span id="classCountBadge" class="bulk-sms-badge" style="display:none;">0 selected</span>
      </div>
      <div id="organizationSelectGroup" style="display:none;">
        <div class="bulk-sms-label">Organization(s)</div>
        
        <select class="form-control bulk-sms-select" name="organization_ids[]" id="organization_ids" multiple size="6">
          <?php if ($organizations) while($org = $organizations->fetch_assoc()): ?>
            <option value="<?= $org['id'] ?>"><?= htmlspecialchars($org['name']) ?></option>
          <?php endwhile; ?>
        </select>
        <span id="organizationCountBadge" class="bulk-sms-badge" style="display:none;">0 selected</span>
      </div>
      <div id="churchSelectGroup" style="display:none;">
        <div class="bulk-sms-label">Church(es)</div>
        <select class="form-control bulk-sms-select" name="church_ids[]" id="church_ids" multiple size="6">
          <?php if ($churches) while($ch = $churches->fetch_assoc()): ?>
            <option value="<?= $ch['id'] ?>"><?= htmlspecialchars($ch['name']) ?></option>
          <?php endwhile; ?>
        </select>
        <span id="churchCountBadge" class="bulk-sms-badge" style="display:none;">0 selected</span>
      </div>
      <div id="customListGroup" style="display:none;">
        <div class="bulk-sms-label">Custom Phone List <small class="text-muted">(comma or new-line separated)</small></div>
        <textarea class="form-control bulk-sms-textarea" name="custom_phones" id="custom_phones" rows="3" placeholder="e.g. 024XXXXXXX, 020XXXXXXX"></textarea>
      </div>
    </div>
    <div class="bulk-sms-divider"></div>
    <div class="bulk-sms-section">
      <div class="bulk-sms-label"><i class="fas fa-envelope bulk-sms-icon"></i>Message Template</div>
      <select class="form-control bulk-sms-select" name="template_id" id="template_id">
        <option value="">-- No Template (Write Custom) --</option>
        <?php if ($templates) while($tpl = $templates->fetch_assoc()): ?>
          <option value="<?= $tpl['id'] ?>" data-body="<?= htmlspecialchars($tpl['body']) ?>"><?= htmlspecialchars($tpl['name']) ?></option>
        <?php endwhile; ?>
      </select>
      <div class="bulk-sms-label mt-3"><i class="fas fa-comment-dots bulk-sms-icon"></i>Message</div>
      <textarea class="form-control bulk-sms-textarea" name="message" id="message" rows="4" required placeholder="Type your SMS message here..."></textarea>
      <div id="templateVarsHelp" class="bulk-sms-help">
        Available variables: <code>{name}</code>, <code>{first_name}</code>, <code>{last_name}</code>, <code>{other_name}</code>, <code>{crn}</code>, <code>{class}</code>, <code>{church}</code>, <code>{phone}</code>
      </div>
      <div class="d-flex justify-content-end align-items-center mt-4">
        <button type="submit" class="bulk-sms-btn"><i class="fas fa-paper-plane mr-2"></i>Send SMS</button>
      </div>
    </div>
    <div id="smsStatus" class="mt-3"></div>
    <div class="bulk-sms-section mt-4">
      <div class="bulk-sms-label"><i class="fas fa-info-circle bulk-sms-icon"></i>How Bulk SMS Works</div>
      <ul class="mb-2">
        <li><i class="fas fa-check-circle text-success mr-1"></i> Choose recipients by class, church, all, or custom.</li>
        <li><i class="fas fa-check-circle text-success mr-1"></i> Use a saved template or write your own message.</li>
        <li><i class="fas fa-check-circle text-success mr-1"></i> Variables like <code>{name}</code>, <code>{crn}</code> in templates will be replaced per member.</li>
        <li><i class="fas fa-check-circle text-success mr-1"></i> You can review delivery status after sending.</li>
      </ul>
      <div class="bulk-sms-help">
        <i class="fas fa-exclamation-triangle text-warning mr-1"></i> Make sure your SMS provider API key is set in <a href="sms_settings.php">SMS Settings</a>.<br>
        <i class="fas fa-info-circle mr-1"></i> For best results, keep messages under 160 characters.
      </div>
    </div>
  </form>
</div>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="sms_bulk_select2.js"></script>
<script>
$(function(){
  // Recipient type logic
  $('#recipient_type').change(function(){
    var val = $(this).val();
    $('#classSelectGroup, #organizationSelectGroup, #churchSelectGroup, #customListGroup').hide();
    if(val === 'class') $('#classSelectGroup').show();
    if(val === 'organization') $('#organizationSelectGroup').show();
    if(val === 'all') $('#churchSelectGroup').show();
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
  // Search for organization
  $('#organizationSearch').on('keyup', function() {
    var term = $(this).val().toLowerCase();
    $('#organization_ids option').each(function() {
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
