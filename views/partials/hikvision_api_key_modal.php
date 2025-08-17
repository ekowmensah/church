<!-- API Key Modal -->
<div class="modal fade" id="apiKeyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="apiKeyForm">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-key"></i> Hikvision Sync API Key</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="manage_api_key">
                    <div class="form-group">
                        <label for="api_key">Current API Key</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="api_key" name="api_key" value="<?php echo htmlspecialchars(getCurrentApiKey()); ?>" readonly>
                            <div class="input-group-append">
                                <button class="btn btn-outline-secondary" type="button" onclick="copyApiKey()"><i class="fas fa-copy"></i></button>
                            </div>
                        </div>
                        <small class="form-text text-muted">Share this key with your local sync agent. Keep it secret!</small>
                    </div>
                    <button type="submit" name="rotate" value="1" class="btn btn-warning btn-block">
                        <i class="fas fa-sync-alt"></i> Generate/Rotate API Key
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function showApiKeyModal() {
    $('#apiKeyModal').modal('show');
}
function copyApiKey() {
    var copyText = document.getElementById('api_key');
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    document.execCommand('copy');
    alert('API Key copied to clipboard!');
}
</script>
