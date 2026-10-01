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

$page_title = 'Church Chat';
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
            if ($action === 'start_group') {
                $openThreadId = $service->startGroupConversation(
                    (string) ($_POST['group_scope'] ?? ''),
                    (string) ($_POST['message_text'] ?? '')
                );
                header('Location: messages.php?thread=' . $openThreadId . '&message=' . rawurlencode('Group message sent.'));
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
            if ($action === 'archive_notification') {
                $service->archiveNotification((int) ($_POST['notification_id'] ?? 0));
                header('Location: messages.php?tab=notifications&message=' . rawurlencode('Notification archived.'));
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
$groupChats = $available ? $service->listAvailableGroupChats() : [];
$onlineContacts = $available ? $service->onlineContacts() : [];
$chatPhotoUrl = static function (array $row, string $photoField = 'participant_photo', string $typeField = 'participant_photo_type'): string {
    $file = basename((string) ($row[$photoField] ?? ''));
    $folder = ($row[$typeField] ?? '') === 'user' ? 'users' : 'members';
    return $file !== '' && is_file(__DIR__ . '/../uploads/' . $folder . '/' . $file)
        ? BASE_URL . '/uploads/' . $folder . '/' . rawurlencode($file)
        : BASE_URL . '/assets/img/undraw_profile.svg';
};
$activeThread = null;
$messages = [];
$threadParticipants = [];
if ($available && $openThreadId > 0) {
    try {
        $activeThread = $service->getThread($openThreadId);
        $messages = $service->getMessages($openThreadId);
        $threadParticipants = $service->isGroupChatAvailable() ? $service->threadParticipants($openThreadId) : [];
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        $openThreadId = 0;
    }
}

ob_start();
?>
<style>
.church-chat-page{--chat-blue:#1877f2;--chat-blue-dark:#0b57d0;--chat-bg:#f4f7fb;--chat-border:#e5eaf1;--chat-text:#17202a}.chat-hero{background:linear-gradient(135deg,var(--chat-blue),var(--chat-blue-dark));color:#fff;border-radius:20px;padding:22px 26px;box-shadow:0 12px 30px rgba(24,119,242,.18)}.chat-hero p{color:rgba(255,255,255,.82)!important}.chat-stat{display:inline-flex;align-items:center;gap:.4rem;background:rgba(255,255,255,.16);color:#fff;border:1px solid rgba(255,255,255,.25);border-radius:999px;padding:.5rem .8rem;font-weight:600}.chat-tabs{border:0;gap:.4rem}.chat-tabs .nav-link{border:0!important;border-radius:999px;color:#667085;font-weight:600;padding:.65rem 1.1rem}.chat-tabs .nav-link.active{color:#fff;background:var(--chat-blue);box-shadow:0 6px 16px rgba(24,119,242,.2)}.chat-action-card{border:1px solid var(--chat-border);border-radius:16px;overflow:hidden}.chat-toolbar .btn{border-radius:999px;padding:.55rem 1rem;font-weight:600}.chat-workspace{background:#fff;border:1px solid var(--chat-border);border-radius:20px;overflow:hidden;box-shadow:0 12px 36px rgba(15,23,42,.08);min-height:680px}.chat-sidebar{height:680px;border:0!important;border-right:1px solid var(--chat-border)!important;border-radius:0!important;box-shadow:none!important}.chat-sidebar .card-header,.chat-main .card-header{background:#fff;border-color:var(--chat-border);padding:16px 18px}.chat-sidebar-scroll{max-height:430px;overflow-y:auto}.chat-thread-item{border:0!important;border-bottom:1px solid #f0f2f5!important;padding:12px 14px!important;color:var(--chat-text)!important}.chat-thread-item:hover{background:#f5f8fd!important}.chat-thread-item.active{background:#eaf3ff!important;color:var(--chat-text)!important;border-left:4px solid var(--chat-blue)!important}.chat-avatar{width:46px;height:46px;flex:0 0 46px;border-radius:50%;object-fit:cover;background:#e7f0ff}.chat-avatar-group{display:flex;align-items:center;justify-content:center;background:#e7f0ff;color:var(--chat-blue);font-size:1.05rem}.chat-person{min-width:0}.chat-person strong,.chat-preview{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.chat-preview{display:block;color:#7a8493;font-size:.78rem}.presence-dot{width:11px;height:11px;border:2px solid #fff;border-radius:50%;position:absolute;right:0;bottom:1px}.presence-online{background:#31a24c}.presence-away{background:#f7b928}.chat-main{height:680px;border:0!important;border-radius:0!important;box-shadow:none!important}.chat-main .card-body{background:var(--chat-bg);min-height:0!important;overflow:hidden}.chat-history{padding:18px;scroll-behavior:smooth}.chat-message-row{display:flex;gap:9px;align-items:flex-end}.chat-message-row.mine{justify-content:flex-end}.chat-message-avatar{width:32px;height:32px;border-radius:50%;object-fit:cover;flex:0 0 32px}.chat-bubble{max-width:76%;padding:10px 14px;border-radius:18px 18px 18px 5px;background:#fff;border:1px solid #e5e7eb;box-shadow:0 2px 6px rgba(15,23,42,.04)}.chat-message-row.mine .chat-bubble{background:var(--chat-blue);color:#fff;border:0;border-radius:18px 18px 5px 18px}.chat-compose{background:#fff;border-top:1px solid var(--chat-border);padding:12px;border-radius:16px}.chat-compose textarea{border:0!important;resize:none;box-shadow:none!important;background:#f0f2f5;border-radius:20px 0 0 20px;padding:12px 15px}.chat-compose .btn{width:48px;border-radius:0 20px 20px 0}.chat-empty-state{color:#7a8493}.chat-empty-state .empty-icon{width:76px;height:76px;margin:0 auto 15px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#e7f0ff;color:var(--chat-blue);font-size:2rem}.notification-modern{border:0!important;border-bottom:1px solid var(--chat-border)!important;padding:16px 18px}.notification-modern:hover{background:#fafcff}@media(max-width:991.98px){.chat-workspace,.chat-sidebar,.chat-main{height:auto;min-height:0}.chat-sidebar{border-right:0!important;border-bottom:1px solid var(--chat-border)!important}.chat-sidebar-scroll{max-height:320px}.chat-main .card-body{min-height:520px!important}}@media(max-width:575.98px){.chat-hero{border-radius:14px;padding:18px}.chat-workspace{border-radius:14px}.chat-bubble{max-width:88%}}
</style>
<div class="container-fluid py-3 church-chat-page">
  <div class="chat-hero d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
      <h1 class="h3 mb-1"><i class="fas fa-comments mr-2"></i>Church Chat</h1>
      <p class="text-muted mb-0">Chat privately or in your authorized church, Bible Class, organization and leadership groups.</p>
    </div>
    <div>
      <span class="chat-stat"><i class="fas fa-comment-dots"></i><?= number_format($available ? $service->unreadMessageCount() : 0) ?> unread</span>
      <span class="chat-stat"><i class="fas fa-bell"></i><?= number_format($service->unreadNotificationCount()) ?> notifications</span>
    </div>
  </div>

  <?php if ($success !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if (!$available): ?>
    <div class="alert alert-warning">Run database migration Phase 0022 before using the inbox.</div>
  <?php endif; ?>

  <ul class="nav nav-tabs chat-tabs mb-3" role="tablist">
    <li class="nav-item"><a class="nav-link <?= ($_GET['tab'] ?? '') !== 'notifications' ? 'active' : '' ?>" data-toggle="tab" href="#conversations">Conversations</a></li>
    <li class="nav-item"><a class="nav-link <?= ($_GET['tab'] ?? '') === 'notifications' ? 'active' : '' ?>" data-toggle="tab" href="#notifications">Notifications</a></li>
  </ul>

  <div class="tab-content">
    <div class="tab-pane fade <?= ($_GET['tab'] ?? '') !== 'notifications' ? 'show active' : '' ?>" id="conversations">
      <div class="chat-toolbar d-flex flex-wrap mb-3" style="gap:.5rem">
        <button class="btn btn-primary" type="button" data-toggle="collapse" data-target="#newDirectChat"><i class="fas fa-pen mr-1"></i>New message</button>
        <?php if ($service->isGroupChatAvailable() && $groupChats): ?><button class="btn btn-outline-primary" type="button" data-toggle="collapse" data-target="#newGroupChat"><i class="fas fa-users mr-1"></i>New group chat</button><?php endif; ?>
      </div>
      <div class="collapse <?= $search !== '' ? 'show' : '' ?>" id="newDirectChat">
      <div class="card chat-action-card shadow-sm mb-3">
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
      </div>

      <?php if ($service->isGroupChatAvailable() && $groupChats): ?>
      <div class="collapse" id="newGroupChat"><div class="card chat-action-card shadow-sm mb-3 border-primary">
        <div class="card-header bg-white"><strong><i class="fas fa-users mr-2 text-primary"></i>Start or open a group chat</strong></div>
        <div class="card-body">
          <form method="post">
            <?= csrf_input() ?><input type="hidden" name="action" value="start_group">
            <div class="form-row align-items-end">
              <div class="form-group col-md-5">
                <label for="group_scope">Your class, organization, group, or leadership channel</label>
                <select class="form-control" id="group_scope" name="group_scope" required>
                  <option value="">Choose a group</option>
                  <?php foreach ($groupChats as $group): ?>
                    <option value="<?= htmlspecialchars($group['key']) ?>"><?= htmlspecialchars($group['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group col-md-5">
                <label for="group_message_text">First message</label>
                <textarea class="form-control" id="group_message_text" name="message_text" rows="2" maxlength="5000" required></textarea>
              </div>
              <div class="form-group col-md-2"><button class="btn btn-primary btn-block" type="submit"><i class="fas fa-comments mr-1"></i>Open chat</button></div>
            </div>
          </form>
          <small class="text-muted"><i class="fas fa-shield-alt mr-1"></i>Only current members and authorized leaders of the selected group can participate.</small>
        </div>
      </div>
      </div>
      <?php endif; ?>

      <div class="row no-gutters chat-workspace">
        <div class="col-lg-4">
          <div class="card chat-sidebar h-100">
            <div class="card-header"><strong>Inbox</strong></div>
            <div class="list-group list-group-flush chat-sidebar-scroll">
              <?php if (!$threads): ?><div class="p-4 text-muted text-center">No conversations yet.</div><?php endif; ?>
              <?php foreach ($threads as $thread): ?>
                <a class="list-group-item list-group-item-action chat-thread-item <?= (int) $thread['id'] === $openThreadId ? 'active' : '' ?>" href="messages.php?thread=<?= (int) $thread['id'] ?>">
                  <div class="d-flex align-items-center">
                    <?php if (($thread['thread_type'] ?? '') === 'group'): ?><span class="chat-avatar chat-avatar-group mr-3"><i class="fas fa-users"></i></span><?php else: ?><span class="position-relative mr-3"><img class="chat-avatar" src="<?= htmlspecialchars($chatPhotoUrl($thread)) ?>" alt=""><?php if (($thread['presence_status'] ?? '') === 'online'): ?><i class="presence-dot presence-online"></i><?php elseif (($thread['presence_status'] ?? '') === 'away'): ?><i class="presence-dot presence-away"></i><?php endif; ?></span><?php endif; ?>
                    <span class="chat-person flex-grow-1"><span class="d-flex justify-content-between"><strong><?= htmlspecialchars($thread['display_name']) ?></strong>
                    <?php if ($thread['unread_count'] > 0): ?><span class="badge badge-danger badge-pill"><?= (int) $thread['unread_count'] ?></span><?php endif; ?>
                    </span><span class="chat-preview"><?= htmlspecialchars((string) ($thread['last_message'] ?: 'No messages yet')) ?></span><small class="text-muted"><?= htmlspecialchars(date('M j, g:i A', strtotime($thread['last_activity']))) ?></small></span>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
          <?php if ($service->isGroupChatAvailable()): ?>
          <div class="card border-0 rounded-0 mb-0">
            <div class="card-header d-flex justify-content-between"><strong><i class="fas fa-circle text-success mr-2" style="font-size:.55rem"></i>Online now</strong><span class="badge badge-success badge-pill"><?= count($onlineContacts) ?></span></div>
            <div class="list-group list-group-flush" style="max-height:280px;overflow:auto">
              <?php if (!$onlineContacts): ?><div class="p-3 text-muted small text-center">No authorized contacts are online right now.</div><?php endif; ?>
              <?php foreach ($onlineContacts as $contact): ?>
                <div class="list-group-item py-2 d-flex align-items-center border-0"><span class="position-relative mr-2"><img class="chat-avatar" style="width:36px;height:36px" src="<?= htmlspecialchars($chatPhotoUrl($contact)) ?>" alt=""><i class="presence-dot presence-online"></i></span><strong class="small text-truncate flex-grow-1"><?= htmlspecialchars($contact['display_name']) ?></strong><small class="text-success">online</small></div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
        <div class="col-lg-8">
          <div class="card chat-main h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <div class="d-flex align-items-center">
                <?php if ($activeThread && ($activeThread['thread_type'] ?? '') === 'group'): ?><span class="chat-avatar chat-avatar-group mr-3"><i class="fas fa-users"></i></span><?php elseif ($activeThread): ?><span class="position-relative mr-3"><img class="chat-avatar" src="<?= htmlspecialchars($chatPhotoUrl($activeThread)) ?>" alt=""><?php if (($activeThread['presence_status'] ?? '') === 'online'): ?><i class="presence-dot presence-online"></i><?php elseif (($activeThread['presence_status'] ?? '') === 'away'): ?><i class="presence-dot presence-away"></i><?php endif; ?></span><?php endif; ?>
                <div><strong><?= $activeThread ? htmlspecialchars($activeThread['display_name']) : 'Select a conversation' ?></strong>
                <?php if ($activeThread && $threadParticipants):
                    $onlineCount = count(array_filter($threadParticipants, static fn($person) => $person['presence_status'] === 'online'));
                ?><div class="small text-muted" id="chat-presence-summary"><span class="badge badge-success badge-pill mr-1"><?= $onlineCount ?></span>online · <?= count($threadParticipants) ?> participants</div><?php endif; ?>
                <?php if ($activeThread && !$threadParticipants): ?><div class="small text-muted"><?= htmlspecialchars(ucfirst((string) ($activeThread['presence_status'] ?? 'offline'))) ?></div><?php endif; ?>
                </div>
              </div>
              <?php if ($activeThread): ?>
                <form method="post" class="m-0">
                  <?= csrf_input() ?><input type="hidden" name="action" value="mark_thread_read"><input type="hidden" name="thread_id" value="<?= $openThreadId ?>">
                  <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="fas fa-check-double mr-1"></i>Mark read</button>
                </form>
              <?php endif; ?>
            </div>
            <div class="card-body d-flex flex-column" style="min-height:0">
              <?php if (!$activeThread): ?>
                <div class="m-auto text-center chat-empty-state"><div class="empty-icon"><i class="far fa-comments"></i></div><h5 class="text-dark">Your conversations</h5><p>Choose a chat from the left or start a new message.</p></div>
              <?php else: ?>
                <?php if (($activeThread['thread_type'] ?? '') === 'group' && $threadParticipants): ?>
                  <div class="d-flex flex-wrap mb-3 pb-2 border-bottom" id="chat-presence-list" style="gap:.4rem">
                    <?php foreach (array_slice($threadParticipants, 0, 16) as $person):
                      $presenceClass = $person['presence_status'] === 'online' ? 'success' : ($person['presence_status'] === 'away' ? 'warning' : 'secondary');
                    ?><span class="badge badge-light border p-2" title="<?= htmlspecialchars(ucfirst($person['presence_status'])) ?>"><i class="fas fa-circle text-<?= $presenceClass ?> mr-1" style="font-size:.55rem"></i><?= htmlspecialchars($person['display_name']) ?></span><?php endforeach; ?>
                    <?php if (count($threadParticipants) > 16): ?><span class="badge badge-light border p-2">+<?= count($threadParticipants) - 16 ?> more</span><?php endif; ?>
                  </div>
                <?php endif; ?>
                <div id="message-history" class="chat-history flex-grow-1 overflow-auto mb-3">
                  <?php foreach ($messages as $message): ?>
                    <div class="chat-message-row mb-3 <?= $message['is_mine'] ? 'mine' : '' ?>" data-message-id="<?= (int) $message['id'] ?>">
                      <?php if (!$message['is_mine']): ?><img class="chat-message-avatar" src="<?= htmlspecialchars($chatPhotoUrl($message, 'sender_photo', 'sender_photo_type')) ?>" alt=""><?php endif; ?>
                      <div class="chat-bubble">
                        <div class="small font-weight-bold mb-1"><?= htmlspecialchars($message['sender_name']) ?></div>
                        <div style="white-space:pre-wrap"><?= htmlspecialchars($message['message_text']) ?></div>
                        <div class="small mt-1 <?= $message['is_mine'] ? 'text-white-50' : 'text-muted' ?>"><?= htmlspecialchars(date('M j, g:i A', strtotime($message['created_at']))) ?></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
                <form method="post" class="chat-compose" id="chat-reply-form">
                  <?= csrf_input() ?><input type="hidden" name="action" value="reply"><input type="hidden" name="thread_id" value="<?= $openThreadId ?>">
                  <div class="input-group">
                    <textarea class="form-control" id="chat-reply-text" name="message_text" rows="2" maxlength="5000" required placeholder="Write a reply"></textarea>
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
          <?php foreach ($notifications as $notification):
              $severity = in_array(($notification['severity'] ?? 'info'), ['info','success','warning','danger'], true)
                  ? $notification['severity'] : 'info';
              $icon = preg_match('/^fa[bsr]? fa-[a-z0-9-]+$/', (string) ($notification['icon'] ?? ''))
                  ? $notification['icon'] : 'far fa-bell';
          ?>
            <div class="list-group-item notification-modern <?= !$notification['is_read'] ? 'border-left border-' . $severity : '' ?>" style="border-left-width:4px!important">
              <div class="d-flex">
                <span class="rounded-circle bg-<?= $severity ?> text-white d-inline-flex align-items-center justify-content-center mr-3" style="width:40px;height:40px;flex:0 0 40px"><i class="<?= htmlspecialchars($icon) ?>"></i></span>
                <div class="flex-grow-1 min-width-0">
                  <div class="d-flex justify-content-between flex-wrap"><strong><?= htmlspecialchars($notification['title']) ?></strong><small class="text-muted"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($notification['created_at']))) ?></small></div>
                  <span class="badge badge-light text-uppercase mb-2"><?= htmlspecialchars((string) ($notification['category'] ?? 'system')) ?></span>
                  <p class="mb-2"><?= htmlspecialchars(mb_strimwidth((string) $notification['message'], 0, 220, '…')) ?></p>
              <div class="d-flex align-items-center flex-wrap">
                <a class="btn btn-sm btn-outline-primary mr-2" href="notification_detail.php?id=<?= (int) $notification['id'] ?>">View details</a>
                <?php if (!$notification['is_read']): ?>
                  <form method="post" class="m-0"><?= csrf_input() ?><input type="hidden" name="action" value="mark_notification_read"><input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>"><button class="btn btn-sm btn-outline-secondary" type="submit">Mark read</button></form>
                <?php else: ?><span class="text-muted small"><i class="fas fa-check mr-1"></i>Read</span><?php endif; ?>
                <form method="post" class="m-0 ml-2"><?= csrf_input() ?><input type="hidden" name="action" value="archive_notification"><input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>"><button class="btn btn-sm btn-link text-muted" type="submit" title="Archive"><i class="fas fa-archive"></i></button></form>
              </div>
                </div>
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
  <?php if ($openThreadId > 0): ?>
  var chatToken = <?= json_encode(csrf_token()) ?>;
  var chatEndpoint = 'ajax_chat_drawer.php';
  var chatThreadId = <?= (int) $openThreadId ?>;
  var chatSyncing = false;
  function chatEscape(value) { var node = document.createElement('div'); node.textContent = value == null ? '' : String(value); return node.innerHTML; }
  function chatRequest(data) {
    data.csrf_token = chatToken;
    return fetch(chatEndpoint, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:new URLSearchParams(data).toString()})
      .then(function(response){return response.json().then(function(payload){if(!response.ok||!payload.success)throw new Error(payload.message||'Chat request failed.');return payload;});});
  }
  function appendNewMessages(rows) {
    if (!history) return;
    var lastNode = history.querySelector('[data-message-id]:last-of-type');
    var lastId = lastNode ? Number(lastNode.getAttribute('data-message-id')) : 0;
    var nearBottom = history.scrollHeight - history.scrollTop - history.clientHeight < 90;
    rows.forEach(function(message) {
      if (Number(message.id) <= lastId) return;
      var row = document.createElement('div');
      row.className = 'chat-message-row mb-3' + (message.is_mine ? ' mine' : '');
      row.setAttribute('data-message-id', message.id);
      row.innerHTML = (message.is_mine ? '' : '<img class="chat-message-avatar" src="'+chatEscape(message.sender_photo_url)+'" alt="">')
        + '<div class="chat-bubble"><div class="small font-weight-bold mb-1">'+chatEscape(message.sender_name)+'</div>'
        + '<div style="white-space:pre-wrap">'+chatEscape(message.message_text)+'</div>'
        + '<div class="small mt-1 '+(message.is_mine?'text-white-50':'text-muted')+'">'+chatEscape(message.created_at)+'</div></div>';
      history.appendChild(row);
      lastId = Number(message.id);
    });
    if (nearBottom) history.scrollTop = history.scrollHeight;
  }
  function syncConversation() {
    if (chatSyncing || document.hidden) return;
    chatSyncing = true;
    chatRequest({action:'thread',thread_id:chatThreadId}).then(function(data){appendNewMessages(data.messages||[]);}).catch(function(){}).finally(function(){chatSyncing=false;});
  }
  var replyForm = document.getElementById('chat-reply-form');
  if (replyForm) replyForm.addEventListener('submit', function(event) {
    event.preventDefault();
    var input = document.getElementById('chat-reply-text'), text = input.value.trim();
    if (!text) return;
    input.disabled = true;
    chatRequest({action:'reply',thread_id:chatThreadId,message_text:text}).then(function(data){input.value='';appendNewMessages(data.messages||[]);history.scrollTop=history.scrollHeight;}).catch(function(error){window.alert(error.message);}).finally(function(){input.disabled=false;input.focus();});
  });
  window.setInterval(syncConversation, 4000);
  <?php endif; ?>
  var presenceList = document.getElementById('chat-presence-list');
  var presenceSummary = document.getElementById('chat-presence-summary');
  <?php if ($openThreadId > 0): ?>
  if (presenceList && presenceSummary) {
    var refreshPresence = function () {
      var body = new URLSearchParams({csrf_token: <?= json_encode(csrf_token()) ?>, status: document.hidden ? 'away' : 'online', thread_id: <?= (int) $openThreadId ?>});
      fetch('ajax_chat_presence.php', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body.toString()})
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (data) {
          if (!data || !Array.isArray(data.participants)) return;
          var online = data.participants.filter(function (person) { return person.presence_status === 'online'; }).length;
          presenceSummary.textContent = online + ' online · ' + data.participants.length + ' participants';
          presenceList.textContent = '';
          data.participants.slice(0, 16).forEach(function (person) {
            var badge = document.createElement('span'); badge.className = 'badge badge-light border p-2';
            var dot = document.createElement('i');
            dot.className = 'fas fa-circle mr-1 text-' + (person.presence_status === 'online' ? 'success' : (person.presence_status === 'away' ? 'warning' : 'secondary'));
            dot.style.fontSize = '.55rem'; badge.appendChild(dot); badge.appendChild(document.createTextNode(person.display_name));
            presenceList.appendChild(badge);
          });
        }).catch(function () {});
    };
    window.setInterval(refreshPresence, 30000);
  }
  <?php endif; ?>
});
</script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
