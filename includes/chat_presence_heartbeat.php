<?php
if (empty($_SESSION['user_id']) && empty($_SESSION['member_id'])) return;
require_once __DIR__ . '/../helpers/csrf.php';
?>
<script>
(function () {
  var endpoint = <?= json_encode(BASE_URL . '/views/ajax_chat_presence.php') ?>;
  var token = <?= json_encode(csrf_token()) ?>;
  function heartbeat(status) {
    var body = new URLSearchParams({csrf_token: token, status: status || (document.hidden ? 'away' : 'online')});
    fetch(endpoint, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString()})
      .then(function (response) { if (!response.ok) throw new Error('Presence heartbeat returned HTTP ' + response.status); return response.json(); })
      .then(function (data) { if (!data.success) throw new Error(data.message || 'Presence heartbeat failed'); })
      .catch(function (error) { if (window.console) console.warn('Church Chat:', error.message); });
  }
  heartbeat('online');
  window.setInterval(function () { heartbeat(); }, 60000);
  document.addEventListener('visibilitychange', function () { heartbeat(document.hidden ? 'away' : 'online'); });
})();
</script>
