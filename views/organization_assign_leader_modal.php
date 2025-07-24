<div class="modal fade" id="assignOrgLeaderModal" tabindex="-1" role="dialog" aria-labelledby="assignOrgLeaderModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="assignOrgLeaderModalLabel">Assign Organization Leader</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <form id="assignOrgLeaderForm" method="post">
        <div class="modal-body">
          <input type="hidden" name="org_id" id="modal-org-id">
          <input type="hidden" name="church_id" id="modal-church-id">
          <div class="form-group">
            <label for="org-leader-user-id">Select Leader</label>
            <select name="leader_user_id" id="org-leader-user-id" class="form-control" style="width:100%"></select>
            <small class="form-text text-muted">Only users with the Organizational Leader role are shown. Search by name, username, or email.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success">Assign Leader</button>
        </div>
      </form>
    </div>
  </div>
</div>
