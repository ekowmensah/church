<?php
$inAppUnreadMessages = 0;
$inAppUnreadNotifications = 0;
if (!empty($messageCanUse) && !empty($messageActorType) && !empty($messageActorId)) {
    try {
        require_once __DIR__ . '/../services/InAppMessagingService.php';
        $messageMenuService = new InAppMessagingService(
            $conn,
            (string) $messageActorType,
            (int) $messageActorId
        );
        $inAppUnreadMessages = $messageMenuService->unreadMessageCount();
        $inAppUnreadNotifications = $messageMenuService->unreadNotificationCount();
    } catch (Throwable $messageMenuException) {
        error_log('Message notification menu unavailable: ' . $messageMenuException->getMessage());
    }
}
?>
<?php if (!empty($messageCanUse)): ?>
<li class="nav-item">
  <a class="nav-link" href="<?= BASE_URL ?>/views/messages.php" aria-label="Messages: <?= (int) $inAppUnreadMessages ?> unread">
    <i class="far fa-envelope"></i>
    <?php if ($inAppUnreadMessages > 0): ?><span class="badge badge-danger navbar-badge"><?= $inAppUnreadMessages > 99 ? '99+' : (int) $inAppUnreadMessages ?></span><?php endif; ?>
  </a>
</li>
<li class="nav-item">
  <a class="nav-link" href="<?= BASE_URL ?>/views/messages.php?tab=notifications" aria-label="Notifications: <?= (int) $inAppUnreadNotifications ?> unread">
    <i class="far fa-bell"></i>
    <?php if ($inAppUnreadNotifications > 0): ?><span class="badge badge-warning navbar-badge"><?= $inAppUnreadNotifications > 99 ? '99+' : (int) $inAppUnreadNotifications ?></span><?php endif; ?>
  </a>
</li>
<?php endif; ?>
