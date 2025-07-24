<!-- Add Device Modal -->
<div class="modal fade" id="addDeviceModal" tabindex="-1" role="dialog" aria-labelledby="addDeviceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addDeviceModalLabel">
                    <i class="fas fa-plus"></i> Add New ZKTeco Device
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" id="addDeviceForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_device">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="add_device_name">Device Name *</label>
                                <input type="text" class="form-control" id="add_device_name" name="device_name" required>
                                <small class="form-text text-muted">e.g., "Main Entrance Scanner"</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="add_church_id">Church <?php echo $is_super_admin ? '*' : ''; ?></label>
                                <?php if ($is_super_admin): ?>
                                    <select class="form-control" id="add_church_id" name="church_id" required>
                                        <option value="">Select Church...</option>
                                        <?php foreach ($churches as $church): ?>
                                            <option value="<?php echo $church['id']; ?>">
                                                <?php echo htmlspecialchars($church['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="form-text text-muted">Select which church this device belongs to</small>
                                <?php else: ?>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($churches[0]['name'] ?? 'No Church'); ?>" readonly>
                                    <small class="form-text text-muted">Device will be assigned to your church</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="add_ip_address">IP Address *</label>
                                <input type="text" class="form-control" id="add_ip_address" name="ip_address" 
                                       pattern="^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$" required>
                                <small class="form-text text-muted">e.g., "192.168.1.100"</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="add_port">Port</label>
                                <input type="number" class="form-control" id="add_port" name="port" value="4370" min="1" max="65535">
                                <small class="form-text text-muted">Default: 4370</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="add_location">Location</label>
                                <input type="text" class="form-control" id="add_location" name="location">
                                <small class="form-text text-muted">Physical location</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Device
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Reset form when modal is closed
$('#addDeviceModal').on('hidden.bs.modal', function () {
    $('#addDeviceForm')[0].reset();
});

// Form validation and submission
$('#addDeviceForm').on('submit', function(e) {
    const deviceName = $('#add_device_name').val().trim();
    const ipAddress = $('#add_ip_address').val().trim();
    
    if (!deviceName || !ipAddress) {
        e.preventDefault();
        alert('Device name and IP address are required.');
        return false;
    }
    
    // IP address validation
    const ipPattern = /^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$/;
    if (!ipPattern.test(ipAddress)) {
        e.preventDefault();
        alert('Please enter a valid IP address (e.g., 192.168.1.100).');
        return false;
    }
    
    <?php if ($is_super_admin): ?>
    const churchId = $('#add_church_id').val();
    if (!churchId) {
        e.preventDefault();
        alert('Please select a church for this device.');
        return false;
    }
    <?php endif; ?>
    
    // If validation passes, allow form submission
    return true;
});
</script>
