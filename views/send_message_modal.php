<?php
// send_message_modal.php: Modal for sending a message to a member
if (!isset($member_id) || !isset($member_name) || !isset($member_phone)) {
    // These should be set by the including page
    exit;
}
?>
<?php ob_start(); ?>
<div class="modal fade" id="sendMessageModal_<?= $member_id ?>" tabindex="-1" role="dialog" aria-labelledby="sendMessageModalLabel_<?= $member_id ?>" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <form method="post" action="send_member_message.php" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="sendMessageModalLabel_<?= $member_id ?>">Send Message to <?= htmlspecialchars($member_name) ?></h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="member_id" value="<?= $member_id ?>">
        <input type="hidden" name="phone" value="<?= htmlspecialchars($member_phone) ?>">
        <div class="form-group">
          <label for="message_body_<?= $member_id ?>">Message</label>
          <textarea class="form-control" id="message_body_<?= $member_id ?>" name="message_body" rows="4" required placeholder="Type your message..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Send</button>
      </div>
    </form>
  </div>
</div>
