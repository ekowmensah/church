<?php
// Navigation partial for SMS features
?>
<ul class="nav flex-column mb-4">
  <?php if (function_exists('has_permission') && has_permission('bulk_sms')): ?>
    <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>/views/sms_bulk.php"><i class="fa fa-paper-plane"></i> Bulk SMS</a></li>
  <?php endif; ?>
  <?php if (function_exists('has_permission') && has_permission('sms_templates')): ?>
    <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>/views/sms_templates.php"><i class="fa fa-cogs"></i> SMS Templates</a></li>
  <?php endif; ?>
  <?php if (function_exists('has_permission') && has_permission('sms_provider_settings')): ?>
    <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>/views/sms_settings.php"><i class="fa fa-sliders-h"></i> SMS Provider Settings</a></li>
  <?php endif; ?>
</ul>
