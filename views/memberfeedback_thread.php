<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Get thread id (top-level chat id)
$thread_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$thread_id) {
    header('Location: memberfeedback_list.php');
    exit;
}

// Fetch thread info (for header)
$stmt = $conn->prepare('SELECT * FROM member_feedback_thread WHERE id = ? AND feedback_id IS NULL');
$stmt->bind_param('i', $thread_id);
$stmt->execute();
$res = $stmt->get_result();
$thread = $res->fetch_assoc();
if (!$thread) {
    header('Location: memberfeedback_list.php');
    exit;
}

// Handle new message post
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $msg = trim($_POST['message'] ?? '');
    if ($msg === '') {
        $error = 'Message cannot be empty.';
    } else {
        // Determine sender type/id
        if (isset($_SESSION['member_id'])) {
            $sender_type = 'member';
            $sender_id = $_SESSION['member_id'];
        } else {
            $sender_type = 'user';
            $sender_id = $_SESSION['user_id'] ?? 0;
        }
        $stmt = $conn->prepare('INSERT INTO member_feedback_thread (feedback_id, recipient_type, recipient_id, sender_type, sender_id, message, sent_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $stmt->bind_param('isisss', $thread_id, $thread['recipient_type'], $thread['recipient_id'], $sender_type, $sender_id, $msg);
        if ($stmt->execute()) {
            header('Location: memberfeedback_thread.php?id=' . $thread_id);
            exit;
        } else {
            $error = 'Failed to send message.';
        }
    }
}

// Fetch all messages in thread (top-level + replies)
$stmt = $conn->prepare('SELECT * FROM member_feedback_thread WHERE id = ? OR feedback_id = ? ORDER BY sent_at ASC, id ASC');
$stmt->bind_param('ii', $thread_id, $thread_id);
$stmt->execute();
$messages = $stmt->get_result();

ob_start();
?>
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-lg">
                <div class="card-header bg-primary text-white">
                    <i class="fas fa-comments"></i>
                    Chat: <?= ucfirst($thread['sender_type']) ?> #<?= htmlspecialchars($thread['sender_id']) ?> to <?= ucfirst($thread['recipient_type']) ?> #<?= htmlspecialchars($thread['recipient_id']) ?>
                </div>
                <div class="card-body" id="chat-messages" style="background:#f7f7f9; min-height:300px; max-height:500px; overflow-y:auto;">
                    <?php if ($messages->num_rows > 0): ?>
                        <?php while($msg = $messages->fetch_assoc()): ?>
                            <div class="mb-3 d-flex <?= $msg['sender_type']==='user' ? 'justify-content-end' : '' ?>" data-message-id="<?= $msg['id'] ?>">
                                <div class="p-2 rounded shadow-sm <?= $msg['sender_type']==='user' ? 'bg-success text-white' : 'bg-light border' ?>" style="max-width:70%;">
                                    <div style="font-size:0.95em;">
                                        <?= nl2br(htmlspecialchars($msg['message'])) ?>
                                    </div>
                                    <div class="text-muted mt-1" style="font-size:0.8em;">
                                        <?= ucfirst($msg['sender_type']) ?> #<?= htmlspecialchars($msg['sender_id']) ?>
                                        <span class="ml-2"><?= date('M j, Y g:i A', strtotime($msg['sent_at'])) ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="alert alert-info" id="no-messages-alert">No messages yet. Start the conversation below.</div>
                    <?php endif; ?>
                    <div id="typing-indicator" class="mb-3 d-none">
                        <div class="d-flex">
                            <div class="p-2 rounded bg-light border" style="max-width:70%;">
                                <div class="typing-dots">
                                    <span></span><span></span><span></span>
                                </div>
                                <small class="text-muted">Typing...</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <div id="error-alert" class="alert alert-danger mb-2 d-none"></div>
                    <div id="success-alert" class="alert alert-success mb-2 d-none"></div>
                    <form id="message-form" class="d-flex align-items-end">
                        <textarea id="message-input" name="message" class="form-control mr-2" rows="2" placeholder="Type your message..." required></textarea>
                        <button type="submit" id="send-btn" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> <span class="btn-text">Send</span>
                        </button>
                    </form>
                </div>
            </div>
            <?php
    // Determine back link target
    $back_link = (isset($_SESSION['member_id']) && !isset($_SESSION['role_id']))
        ? 'memberfeedback_my.php'
        : 'memberfeedback_list.php';
?>
<a href="<?= $back_link ?>" class="btn btn-link mt-3">&larr; Back to Feedback List</a>
        </div>
    </div>
</div>
<?php $page_content = ob_get_clean(); require_once __DIR__.'/../includes/layout.php'; ?>

<style>
.typing-dots {
    display: inline-block;
}
.typing-dots span {
    display: inline-block;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background-color: #999;
    margin: 0 1px;
    animation: typing 1.4s infinite ease-in-out;
}
.typing-dots span:nth-child(1) { animation-delay: -0.32s; }
.typing-dots span:nth-child(2) { animation-delay: -0.16s; }
@keyframes typing {
    0%, 80%, 100% { transform: scale(0); }
    40% { transform: scale(1); }
}
.message-fade-in {
    animation: fadeIn 0.3s ease-in;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
#chat-messages {
    scroll-behavior: smooth;
}
</style>

<script>
(function() {
    const threadId = <?= $thread_id ?>;
    let lastTimestamp = '<?= $messages->num_rows > 0 ? date('Y-m-d H:i:s') : '1970-01-01 00:00:00' ?>';
    let isPolling = false;
    let pollInterval;
    
    const chatMessages = document.getElementById('chat-messages');
    const messageForm = document.getElementById('message-form');
    const messageInput = document.getElementById('message-input');
    const sendBtn = document.getElementById('send-btn');
    const errorAlert = document.getElementById('error-alert');
    const successAlert = document.getElementById('success-alert');
    const noMessagesAlert = document.getElementById('no-messages-alert');
    
    // Auto-scroll to bottom
    function scrollToBottom() {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
    
    // Show/hide alerts
    function showAlert(element, message, duration = 3000) {
        element.textContent = message;
        element.classList.remove('d-none');
        setTimeout(() => element.classList.add('d-none'), duration);
    }
    
    // Add message to chat
    function addMessage(msg) {
        if (noMessagesAlert) {
            noMessagesAlert.remove();
        }
        
        const isUser = msg.sender_type === 'user';
        const messageDiv = document.createElement('div');
        messageDiv.className = `mb-3 d-flex message-fade-in ${isUser ? 'justify-content-end' : ''}`;
        messageDiv.setAttribute('data-message-id', msg.id);
        
        messageDiv.innerHTML = `
            <div class="p-2 rounded shadow-sm ${isUser ? 'bg-success text-white' : 'bg-light border'}" style="max-width:70%;">
                <div style="font-size:0.95em;">
                    ${msg.message.replace(/\n/g, '<br>')}
                </div>
                <div class="text-muted mt-1" style="font-size:0.8em;">
                    ${msg.sender_type.charAt(0).toUpperCase() + msg.sender_type.slice(1)} #${msg.sender_id}
                    <span class="ml-2">${msg.formatted_time}</span>
                </div>
            </div>
        `;
        
        chatMessages.appendChild(messageDiv);
        scrollToBottom();
    }
    
    // Poll for new messages
    function pollMessages() {
        if (isPolling) return;
        isPolling = true;
        
        fetch(`ajax_get_thread_messages.php?thread_id=${threadId}&last_timestamp=${encodeURIComponent(lastTimestamp)}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.has_new_messages) {
                    data.messages.forEach(addMessage);
                    lastTimestamp = data.latest_timestamp;
                }
            })
            .catch(error => console.error('Polling error:', error))
            .finally(() => {
                isPolling = false;
            });
    }
    
    // Send message via AJAX
    function sendMessage(message) {
        const btnText = sendBtn.querySelector('.btn-text');
        const originalText = btnText.textContent;
        btnText.textContent = 'Sending...';
        sendBtn.disabled = true;
        
        fetch('ajax_send_thread_message.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                thread_id: threadId,
                message: message
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                addMessage(data.message);
                lastTimestamp = data.message.sent_at;
                messageInput.value = '';
                showAlert(successAlert, 'Message sent!', 2000);
            } else {
                showAlert(errorAlert, data.error || 'Failed to send message');
            }
        })
        .catch(error => {
            console.error('Send error:', error);
            showAlert(errorAlert, 'Network error. Please try again.');
        })
        .finally(() => {
            btnText.textContent = originalText;
            sendBtn.disabled = false;
            messageInput.focus();
        });
    }
    
    // Form submission
    messageForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const message = messageInput.value.trim();
        if (message) {
            sendMessage(message);
        }
    });
    
    // Enter key to send (Shift+Enter for new line)
    messageInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            messageForm.dispatchEvent(new Event('submit'));
        }
    });
    
    // Start polling every 2 seconds
    pollInterval = setInterval(pollMessages, 2000);
    
    // Initial scroll to bottom
    scrollToBottom();
    
    // Focus message input
    messageInput.focus();
    
    // Cleanup on page unload
    window.addEventListener('beforeunload', function() {
        if (pollInterval) {
            clearInterval(pollInterval);
        }
    });
})();
</script>
