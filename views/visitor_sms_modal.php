<?php
// visitor_sms_modal.php
// Modal for sending SMS to single or bulk visitors
?>
<div class="modal fade" id="visitorSmsModal" tabindex="-1" role="dialog" aria-labelledby="visitorSmsModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="visitorSmsModalLabel">Send SMS to Visitor(s)</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <form id="visitorSmsForm">
        <div class="modal-body">
          <div class="form-group">
            <label>Recipients</label>
            <input type="text" class="form-control" id="visitorSmsRecipients" name="recipients" readonly>
            <input type="hidden" id="visitorSmsRecipientIds" name="recipient_ids">
          </div>
          <div class="form-group">
            <label for="visitorSmsMessage">Message</label>
            <textarea class="form-control" id="visitorSmsMessage" name="message" rows="4" required>WE WARMLY WELCOME YOU TO FREEMAN METHODIST CHURCH, KWESIMINTSIM.

We pray that the good Lord will meet you at the point of your spiritual and physical needs.

Enjoy your stay with us.</textarea>
          </div>
          <div id="visitorSmsFeedback" class="alert d-none"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Send SMS</button>
        </div>
      </form>
    </div>
  </div>
</div>
