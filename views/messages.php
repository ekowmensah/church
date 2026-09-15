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

$page_title = 'Messages';
$error = '';
$success = trim((string) ($_GET['message'] ?? ''));
$service = InAppMessagingService::fromSession($conn);
$available = $service->isAvailable();
$openThreadId = max(0, (int) ($_GET['thread'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your form expired. Refresh the page and try again.';
    } elseif (!$available) {
        $error = 'Messaging is unavailable until database Phase 0022 is installed.';
    } else {
        try {
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'start') {
                $recipient = (string) ($_POST['recipient'] ?? '');
                if (!preg_match('/^(user|member):(\d+)$/', $recipient, $matches)) {
                    throw new InvalidArgumentException('Choose a valid recipient.');
                }
                $openThreadId = $service->startConversation(
                    $matches[1],
                    (int) $matches[2],
                    (string) ($_POST['message_text'] ?? ''),
                    (string) ($_POST['subject'] ?? '')
                );
                header('Location: messages.php?thread=' . $openThreadId . '&message=' . rawurlencode('Message sent.'));
                exit;
            }
            if ($action === 'reply') {
                $openThreadId = (int) ($_POST['thread_id'] ?? 0);
                $service->reply($openThreadId, (string) ($_POST['message_text'] ?? ''));
                header('Location: messages.php?thread=' . $openThreadId . '&message=' . rawurlencode('Reply sent.'));
                exit;
            }
            if ($action === 'mark_thread_read') {
                $openThreadId = (int) ($_POST['thread_id'] ?? 0);
                $service->markThreadRead($openThreadId);
                header('Location: messages.php?thread=' . $openThreadId . '&message=' . rawurlencode('Conversation marked as read.'));
                exit;
            }
            if ($action === 'mark_notification_read') {
                $service->markNotificationRead((int) ($_POST['notification_id'] ?? 0));
                header('Location: messages.php?tab=notifications&message=' . rawurlencode('Notification marked as read.'));
                exit;
            }
            if ($action === 'mark_all_notifications_read') {
                $service->markAllNotificationsRead();
                header('Location: messages.php?tab=notifications&message=' . rawurlencode('All notifications marked as read.'));
                exit;
            }
            throw new InvalidArgumentException('Unknown messaging action.');
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$search = trim((string) ($_GET['recipient_search'] ?? ''));
$threads = $available ? $service->listThreads() : [];
$recipients = $available ? $service->searchRecipients($search) : [];
$notifications = $service->listNotifications();
$activeThread = null;
$messages = [];
if ($available && $openThreadId > 0) {
    try {
        $activeThread = $service->getThread($openThreadId);
        $messages = $service->getMessages($openThreadId);
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        $openThreadId = 0;
    }
}

function messaging_action_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) || str_starts_with($url, '//')) {
        return '';
    }
    return BASE_URL . '/' . ltrim($url, '/');
}

ob_start();
?>
<div class="container-fluid py-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h3 mb-1"><i class="fas fa-envelope mr-2 text-primary"></i>Messages</h1>
      <p class="text-muted mb-0">Private in-app conversations and account notifications.</p>
    </div>
    <div>
      <span class="badge badge-primary p-2"><?= number_format($available ? $service->unreadMessageCount() : 0) ?> unread messages</span>
      <span class="badge badge-warning p-2"><?= number_format($service->unreadNotificationCount()) ?> unread notifications</span>
    </div>
  </div>

  <?php if ($success !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if (!$available): ?>
    <div class="alert alert-warning">Run database migration Phase 0022 before using the inbox.</div>
  <?php endif; ?>

  <ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item"><a class="nav-link <?= ($_GET['tab'] ?? '') !== 'notifications' ? 'active' : '' ?>" data-toggle="tab" href="#conversations">Conversations</a></li>
    <li class="nav-item"><a class="nav-link <?= ($_GET['tab'] ?? '') === 'notifications' ? 'active' : '' ?>" data-toggle="tab" href="#notifications">Notifications</a></li>
  </ul>

  <div class="tab-content">
    <div class="tab-pane fade <?= ($_GET['tab'] ?? '') !== 'notifications' ? 'show active' : '' ?>" id="conversations">
      <div class="card shadow-sm mb-3">
        <div class="card-header"><strong>Start a conversation</strong></div>
        <div class="card-body">
          <form method="get" class="form-row align-items-end mb-3">
            <div class="form-group col-md-8 mb-md-0">
              <label for="recipient_search"><?= $isUserPortal ? 'Find a member by CRN or name' : 'Find a church administrator' ?></label>
              <input class="form-control" id="recipient_search" name="recipient_search" value="<?= htmlspecialchars($search) ?>" placeholder="Search recipient">
            </div>
            <div class="col-md-4"><button class="btn btn-outline-primary btn-block" type="submit"><i class="fas fa-search mr-1"></i>Search</button></div>
          </form>
          <form method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="start">
            <div class="form-row">
              <div class="form-group col-md-5">
                <label for="recipient">Recipient</label>
                <select class="form-control select2" id="recipient" name="recipient" required>
                  <option value="">Choose recipient</option>
                  <?php foreach ($recipients as $recipient): ?>
                    <option value="<?= htmlspecialchars($recipient['recipient_type'] . ':' . $recipient['id']) ?>">
                      <?= htmlspecialchars($recipient['display_name']) ?><?= $recipient['reference'] ? ' — ' . htmlspecialchars($recipient['reference']) : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group col-md-7">
                <label for="subject">Subject <span class="text-muted">(optional)</span></label>
                <input class="form-control" id="subject" name="subject" maxlength="180">
              </div>
            </div>
            <div class="form-group">
              <label for="new_message_text">Message</label>
              <textarea class="form-control" id="new_message_text" name="message_text" rows="3" maxlength="5000" required></textarea>
            </div>
            <button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane mr-1"></i>Send message</button>
          </form>
        </div>
      </div>

      <div class="row">
        <div class="col-lg-4 mb-3">
          <div class="card shadow-sm h-100">
            <div class="card-header"><strong>Inbox</strong></div>
            <div class="list-group list-group-flush">
              <?php if (!$threads): ?><div class="p-4 text-muted text-center">No conversations yet.</div><?php endif; ?>
              <?php foreach ($threads as $thread): ?>
                <a class="list-group-item list-group-item-action <?= (int) $thread['id'] === $openThreadId ? 'active' : '' ?>" href="messages.php?thread=<?= (int) $thread['id'] ?>">
                  <div class="d-flex justify-content-between">
                    <strong><?= htmlspecialchars($thread['display_name']) ?></strong>
                    <?php if ($thread['unread_count'] > 0): ?><span class="badge badge-danger badge-pill"><?= (int) $thread['unread_count'] ?></span><?php endif; ?>
                  </div>
                  <small><?= htmlspecialchars(date('M j, Y g:i A', strtotime($thread['last_activity']))) ?></small>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="col-lg-8 mb-3">
          <div class="card shadow-sm h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <strong><?= $activeThread ? htmlspecialchars($activeThread['display_name']) : 'Conversation' ?></strong>
              <?php if ($activeThread): ?>
                <form method="post" class="m-0">
                  <?= csrf_input() ?><input type="hidden" name="action" value="mark_thread_read"><input type="hidden" name="thread_id" value="<?= $openThreadId ?>">
                  <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="fas fa-check-double mr-1"></i>Mark read</button>
                </form>
              <?php endif; ?>
            </div>
            <div class="card-body d-flex flex-column" style="min-height:420px">
              <?php if (!$activeThread): ?>
                <div class="m-auto text-center text-muted"><i class="far fa-comments fa-3x mb-3"></i><p>Choose a conversation to read or reply.</p></div>
              <?php else: ?>
                <div id="message-history" class="flex-grow-1 overflow-auto mb-3" style="max-height:520px">
                  <?php foreach ($messages as $message): ?>
                    <div class="d-flex mb-3 <?= $message['is_mine'] ? 'justify-content-end' : 'justify-content-start' ?>">
                      <div class="p-3 rounded <?= $message['is_mine'] ? 'bg-primary text-white' : 'bg-light border' ?>" style="max-width:82%">
                        <div class="small font-weight-bold mb-1"><?= htmlspecialchars($message['sender_name']) ?></div>
                        <div style="white-space:pre-wrap"><?= htmlspecialchars($message['message_text']) ?></div>
                        <div class="small mt-1 <?= $message['is_mine'] ? 'text-white-50' : 'text-muted' ?>"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($message['created_at']))) ?></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
                <form method="post">
                  <?= csrf_input() ?><input type="hidden" name="action" value="reply"><input type="hidden" name="thread_id" value="<?= $openThreadId ?>">
                  <div class="input-group">
                    <textarea class="form-control" name="message_text" rows="2" maxlength="5000" required placeholder="Write a reply"></textarea>
                    <div class="input-group-append"><button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane"></i><span class="sr-only">Send reply</span></button></div>
                  </div>
                </form>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="tab-pane fade <?= ($_GET['tab'] ?? '') === 'notifications' ? 'show active' : '' ?>" id="notifications">
      <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
          <strong>Account notifications</strong>
          <form method="post" class="m-0"><?= csrf_input() ?><input type="hidden" name="action" value="mark_all_notifications_read"><button class="btn btn-sm btn-outline-secondary" type="submit">Mark all read</button></form>
        </div>
        <div class="list-group list-group-flush">
          <?php if (!$notifications): ?><div class="p-4 text-muted text-center">No notifications.</div><?php endif; ?>
          <?php foreach ($notifications as $notification): $actionUrl = messaging_action_url((string) ($notification['action_url'] ?? '')); ?>
            <div class="list-group-item <?= !$notification['is_read'] ? 'list-group-item-warning' : '' ?>">
              <div class="d-flex justify-content-between"><strong><?= htmlspecialchars($notification['title']) ?></strong><small><?= htmlspecialchars(date('M j, Y g:i A', strtotime($notification['created_at']))) ?></small></div>
              <p class="mb-2"><?= nl2br(htmlspecialchars($notification['message'])) ?></p>
              <div class="d-flex align-items-center">
                <?php if ($actionUrl !== ''): ?><a class="btn btn-sm btn-outline-primary mr-2" href="<?= htmlspecialchars($actionUrl) ?>">Open</a><?php endif; ?>
                <?php if (!$notification['is_read']): ?>
                  <form method="post" class="m-0"><?= csrf_input() ?><input type="hidden" name="action" value="mark_notification_read"><input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>"><button class="btn btn-sm btn-outline-secondary" type="submit">Mark read</button></form>
                <?php else: ?><span class="text-muted small"><i class="fas fa-check mr-1"></i>Read</span><?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var history = document.getElementById('message-history');
  if (history) history.scrollTop = history.scrollHeight;
  if (window.jQuery && jQuery.fn.select2) jQuery('#recipient').select2({ width: '100%' });
});
</script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
