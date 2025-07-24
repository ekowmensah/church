<?php
// Simple modal to prompt for email if missing for Paystack bulk payments
?>
<div class="modal fade" id="paystackEmailPromptModal" tabindex="-1" role="dialog" aria-labelledby="paystackEmailPromptLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header bg-warning text-dark">
        <h5 class="modal-title" id="paystackEmailPromptLabel"><i class="fas fa-envelope mr-2"></i>Email Required</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <p>To pay with Paystack, you must provide an email address. Please enter your email below:</p>
        <input type="email" class="form-control" id="paystackBulkEmailInput" placeholder="Enter your email" required>
        <div class="invalid-feedback mt-2" id="paystackBulkEmailError"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="paystackBulkEmailSubmitBtn">Continue</button>
      </div>
    </div>
  </div>
</div>
