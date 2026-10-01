<?php
$inAppUnreadMessages = 0;
$inAppUnreadNotifications = 0;
$inAppNotificationPreviews = [];
if (!empty($messageCanUse) && !empty($messageActorType) && !empty($messageActorId)) {
    try {
        require_once __DIR__ . '/../services/SystemNotificationService.php';
        $systemNotificationService = new SystemNotificationService($conn);
        $systemNotificationService->queueScheduledReminders();
        $systemNotificationService->dispatchPending(40);
        require_once __DIR__ . '/../services/InAppMessagingService.php';
        $messageMenuService = new InAppMessagingService(
            $conn,
            (string) $messageActorType,
            (int) $messageActorId
        );
        $inAppUnreadMessages = $messageMenuService->unreadMessageCount();
        $inAppUnreadNotifications = $messageMenuService->unreadNotificationCount();
        $inAppNotificationPreviews = $messageMenuService->listNotifications(6);
    } catch (Throwable $messageMenuException) {
        error_log('Message notification menu unavailable: ' . $messageMenuException->getMessage());
    }
}
?>
<?php if (!empty($messageCanUse)): ?>
<li class="nav-item">
  <a class="nav-link d-flex align-items-center" id="mfChatLauncher" href="<?= BASE_URL ?>/views/messages.php" title="Open Church Chat" aria-label="Church Chat: <?= (int) $inAppUnreadMessages ?> unread">
    <i class="fas fa-comments" aria-hidden="true"></i>
    <span class="d-none d-md-inline ml-1 font-weight-bold">Chat</span>
    <?php if ($inAppUnreadMessages > 0): ?><span class="badge badge-danger navbar-badge"><?= $inAppUnreadMessages > 99 ? '99+' : (int) $inAppUnreadMessages ?></span><?php endif; ?>
  </a>
</li>
<li class="nav-item dropdown">
  <a class="nav-link" data-toggle="dropdown" href="#" role="button" aria-haspopup="true" aria-expanded="false" aria-label="Notifications: <?= (int) $inAppUnreadNotifications ?> unread">
    <i class="far fa-bell"></i>
    <?php if ($inAppUnreadNotifications > 0): ?><span class="badge badge-warning navbar-badge"><?= $inAppUnreadNotifications > 99 ? '99+' : (int) $inAppUnreadNotifications ?></span><?php endif; ?>
  </a>
  <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right shadow notification-preview-menu" style="width:360px;max-width:calc(100vw - 24px);padding:0;overflow:hidden">
    <div class="d-flex justify-content-between align-items-center px-3 py-3 border-bottom bg-light">
      <strong>Notifications</strong>
      <span class="badge badge-warning"><?= number_format($inAppUnreadNotifications) ?> unread</span>
    </div>
    <div style="max-height:420px;overflow-y:auto">
      <?php if (!$inAppNotificationPreviews): ?>
        <div class="text-center text-muted px-3 py-4"><i class="far fa-bell fa-2x mb-2 d-block"></i>No notifications yet.</div>
      <?php endif; ?>
      <?php foreach ($inAppNotificationPreviews as $preview):
          $previewSeverity = in_array(($preview['severity'] ?? 'info'), ['info', 'success', 'warning', 'danger'], true)
              ? $preview['severity'] : 'info';
          $previewIcon = preg_match('/^fa[bsr]? fa-[a-z0-9-]+$/', (string) ($preview['icon'] ?? ''))
              ? $preview['icon'] : 'far fa-bell';
          $previewText = mb_strimwidth(trim(preg_replace('/\s+/', ' ', (string) ($preview['message'] ?? ''))), 0, 105, '…');
      ?>
        <a href="<?= BASE_URL ?>/views/notification_detail.php?id=<?= (int) $preview['id'] ?>" class="dropdown-item py-3 px-3 border-bottom" style="white-space:normal;<?= empty($preview['is_read']) ? 'background:#f4f8ff;' : '' ?>">
          <div class="d-flex align-items-start">
            <span class="rounded-circle bg-<?= htmlspecialchars($previewSeverity) ?> text-white d-inline-flex align-items-center justify-content-center mr-3" style="width:38px;height:38px;flex:0 0 38px"><i class="<?= htmlspecialchars($previewIcon) ?>"></i></span>
            <span class="flex-grow-1" style="min-width:0">
              <span class="d-flex justify-content-between align-items-start">
                <strong class="text-dark text-truncate pr-2" style="max-width:205px"><?= htmlspecialchars((string) $preview['title']) ?></strong>
                <?php if (empty($preview['is_read'])): ?><span class="badge badge-primary">New</span><?php endif; ?>
              </span>
              <span class="d-block text-muted small mt-1"><?= htmlspecialchars($previewText) ?></span>
              <span class="d-block text-muted mt-1" style="font-size:.72rem"><i class="far fa-clock mr-1"></i><?= htmlspecialchars(date('M j, g:i A', strtotime((string) $preview['created_at']))) ?></span>
            </span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
    <a href="<?= BASE_URL ?>/views/messages.php?tab=notifications" class="dropdown-item dropdown-footer text-primary font-weight-bold py-3">View all notifications <i class="fas fa-arrow-right ml-1"></i></a>
  </div>
</li>
<?php endif; ?>
