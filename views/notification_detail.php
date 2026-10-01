<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../services/InAppMessagingService.php';

require_login();
$isUserPortal = !empty($_SESSION['user_id']);
if (!$isUserPortal) {
    require_once __DIR__ . '/../includes/member_auth.php';
}
if ($isUserPortal && !is_super_admin() && !has_permission('use_in_app_messages')) {
    require_permission('use_in_app_messages');
}

$page_title = 'Notification Details';
$service = InAppMessagingService::fromSession($conn);
$notificationId = max(0, (int) ($_GET['id'] ?? $_POST['notification_id'] ?? 0));
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your form expired. Refresh the page and try again.';
    } else {
        try {
            $service->archiveNotification($notificationId);
            header('Location: messages.php?tab=notifications&message=' . rawurlencode('Notification archived.'));
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$notification = null;
try {
    $notification = $service->getNotification($notificationId, true);
} catch (Throwable $exception) {
    http_response_code(404);
    $error = $exception->getMessage();
}

function notification_safe_related_url(array $notification, bool $isUserPortal): string
{
    $url = trim((string) ($notification['action_url'] ?? ''));
    if ($url === '' || str_contains($url, '..') || str_starts_with($url, '//')
        || preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
        return '';
    }
    if (!$isUserPortal) {
        $memberSafe = ['views/member_profile.php', 'views/member_dashboard.php',
            'views/messages.php', 'views/make_payment.php', 'views/payment_history.php'];
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (!in_array(ltrim($path, '/'), $memberSafe, true)) {
            return '';
        }
    }
    return BASE_URL . '/' . ltrim($url, '/');
}

$severity = $notification && in_array(($notification['severity'] ?? 'info'), ['info', 'success', 'warning', 'danger'], true)
    ? $notification['severity'] : 'info';
$icon = $notification && preg_match('/^fa[bsr]? fa-[a-z0-9-]+$/', (string) ($notification['icon'] ?? ''))
    ? $notification['icon'] : 'far fa-bell';
$relatedUrl = $notification ? notification_safe_related_url($notification, $isUserPortal) : '';

ob_start();
?>
<div class="container-fluid py-3" style="max-width:980px">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div>
      <a href="messages.php?tab=notifications" class="text-muted small"><i class="fas fa-arrow-left mr-1"></i>Back to notifications</a>
      <h1 class="h3 mt-2 mb-1">Notification details</h1>
      <p class="text-muted mb-0">A complete, account-safe view of this notification.</p>
    </div>
  </div>
  <?php if ($error !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($notification): ?>
    <div class="card shadow-sm border-0 overflow-hidden">
      <div class="bg-<?= htmlspecialchars($severity) ?> text-white p-4">
        <div class="d-flex align-items-start">
          <span class="rounded-circle bg-white text-<?= htmlspecialchars($severity) ?> d-inline-flex align-items-center justify-content-center mr-3" style="width:52px;height:52px;flex:0 0 52px"><i class="<?= htmlspecialchars($icon) ?> fa-lg"></i></span>
          <div>
            <span class="badge badge-light text-uppercase mb-2"><?= htmlspecialchars((string) ($notification['category'] ?? 'system')) ?></span>
            <h2 class="h4 mb-1"><?= htmlspecialchars((string) $notification['title']) ?></h2>
            <small><?= htmlspecialchars(date('F j, Y \a\t g:i A', strtotime((string) $notification['created_at']))) ?></small>
          </div>
        </div>
      </div>
      <div class="card-body p-4">
        <div class="lead" style="font-size:1.05rem;white-space:pre-line"><?= htmlspecialchars((string) $notification['message']) ?></div>

        <?php if (!empty($notification['details'])): ?>
          <hr><h3 class="h6 text-uppercase text-muted mb-3">Related information</h3>
          <div class="row">
            <?php foreach ($notification['details'] as $label => $value): ?>
              <div class="col-md-6 mb-3"><div class="small text-muted"><?= htmlspecialchars((string) $label) ?></div><div class="font-weight-semibold"><?= htmlspecialchars((string) $value) ?></div></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <hr><h3 class="h6 text-uppercase text-muted mb-3">Delivery information</h3>
        <div class="row">
          <div class="col-md-6 mb-3"><div class="small text-muted">Notification type</div><div><?= htmlspecialchars(str_replace(['.', '_'], ' ', (string) ($notification['event_code'] ?: $notification['notification_type']))) ?></div></div>
          <div class="col-md-6 mb-3"><div class="small text-muted">Church</div><div><?= htmlspecialchars((string) ($notification['church_name'] ?: 'Your church')) ?></div></div>
          <?php if (!empty($notification['actor_name'])): ?><div class="col-md-6 mb-3"><div class="small text-muted">Action performed by</div><div><?= htmlspecialchars((string) $notification['actor_name']) ?></div></div><?php endif; ?>
          <div class="col-md-6 mb-3"><div class="small text-muted">Read</div><div><?= htmlspecialchars(date('F j, Y \a\t g:i A', strtotime((string) $notification['read_at']))) ?></div></div>
        </div>
        <div class="d-flex flex-wrap align-items-center mt-2">
          <?php if ($relatedUrl !== ''): ?><a href="<?= htmlspecialchars($relatedUrl) ?>" class="btn btn-primary mr-2"><i class="fas fa-external-link-alt mr-1"></i>Open related record</a><?php endif; ?>
          <form method="post" class="m-0" onsubmit="return confirm('Archive this notification?');">
            <?= csrf_input() ?><input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
            <button type="submit" class="btn btn-outline-secondary"><i class="fas fa-archive mr-1"></i>Archive</button>
          </form>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
