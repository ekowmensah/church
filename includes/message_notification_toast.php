<?php
$messageDashboardPage = in_array(
    basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')),
    ['user_dashboard.php', 'member_dashboard.php'],
    true
);
?>
<?php if ($messageDashboardPage && !empty($messageCanUse) && $inAppUnreadMessages > 0): ?>
<div aria-live="polite" aria-atomic="true" style="position:fixed;top:72px;right:18px;z-index:1085;min-width:290px;max-width:380px">
  <div class="toast bg-white shadow border-0" id="messageNotificationToast" data-delay="10000" role="status">
    <div class="toast-header bg-primary text-white">
      <i class="fas fa-envelope mr-2"></i>
      <strong class="mr-auto">New messages</strong>
      <button type="button" class="ml-2 mb-1 close text-white" data-dismiss="toast" aria-label="Close"><span aria-hidden="true">&times;</span></button>
    </div>
    <div class="toast-body">
      You have <strong><?= (int) $inAppUnreadMessages ?></strong> unread message<?= $inAppUnreadMessages === 1 ? '' : 's' ?>.
      <a class="btn btn-sm btn-primary ml-2" href="<?= BASE_URL ?>/views/messages.php">Open inbox</a>
    </div>
  </div>
</div>
<script>
window.addEventListener('load', function () {
  if (window.jQuery && jQuery.fn.toast) jQuery('#messageNotificationToast').toast('show');
});
</script>
<?php endif; ?>
