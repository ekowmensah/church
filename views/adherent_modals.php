<!-- Mark as Adherent Modal -->
<div class="modal fade" id="markAdherentModal" tabindex="-1" role="dialog" aria-labelledby="markAdherentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="markAdherentModalLabel">
                    <i class="fas fa-user-tag mr-2"></i>Mark Member as Adherent
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="markAdherentForm">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle mr-2"></i>
                        You are about to mark <strong id="adherentMemberName"></strong> as an adherent. 
                        Please provide a reason and confirm the date.
                    </div>
                    
                    <input type="hidden" id="adherentMemberId" name="member_id">
                    
                    <div class="form-group">
                        <label for="adherentReason" class="font-weight-bold">
                            <i class="fas fa-comment mr-1"></i>Reason for Adherent Status <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" id="adherentReason" name="reason" rows="4" 
                                  placeholder="Please provide a detailed reason for marking this member as an adherent..."
                                  required></textarea>
                        <small class="form-text text-muted">This information will be recorded for audit purposes.</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="adherentDate" class="font-weight-bold">
                            <i class="fas fa-calendar mr-1"></i>Date Became Adherent <span class="text-danger">*</span>
                        </label>
                        <input type="date" class="form-control" id="adherentDate" name="date_became_adherent" 
                               value="<?= date('Y-m-d') ?>" required>
                        <small class="form-text text-muted">The date when this member became an adherent.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times mr-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary" id="markAdherentBtn">
                        <i class="fas fa-user-tag mr-1"></i>Mark as Adherent
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Adherent History Modal -->
<div class="modal fade" id="adherentHistoryModal" tabindex="-1" role="dialog" aria-labelledby="adherentHistoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="adherentHistoryModalLabel">
                    <i class="fas fa-history mr-2"></i>Adherent History
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <h6 class="text-muted">Member: <span id="historyMemberName" class="text-dark font-weight-bold"></span></h6>
                </div>
                
                <div id="adherentHistoryContent">
                    <div class="text-center py-4">
                        <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
                        <p class="mt-2 text-muted">Loading adherent history...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    <i class="fas fa-times mr-1"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>
