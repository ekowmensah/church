<?php
// zkteco_edit_device_modal.php
// Modal for editing ZKTeco device
?>
<div class="modal fade" id="editDeviceModal" tabindex="-1" role="dialog" aria-labelledby="editDeviceModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title" id="editDeviceModalLabel">Edit Device</h4>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" id="editDeviceForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_device">
                    <input type="hidden" name="device_id" id="edit_device_id">
                    
                    <div class="form-group">
                        <label for="edit_device_name">Device Name *</label>
                        <input type="text" class="form-control" id="edit_device_name" name="device_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_church_id">Church <?php echo $is_super_admin ? '*' : ''; ?></label>
                        <?php if ($is_super_admin): ?>
                            <select class="form-control" id="edit_church_id" name="church_id" required>
                                <option value="">Select Church...</option>
                                <?php foreach ($churches as $church): ?>
                                    <option value="<?php echo $church['id']; ?>">
                                        <?php echo htmlspecialchars($church['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">Select which church this device belongs to</small>
                        <?php else: ?>
                            <input type="text" class="form-control" id="edit_church_name" readonly>
                            <input type="hidden" id="edit_church_id" name="church_id">
                            <small class="form-text text-muted">Device church assignment</small>
                        <?php endif; ?>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group">
                                <label for="edit_ip_address">IP Address *</label>
                                <input type="text" class="form-control" id="edit_ip_address" name="ip_address" 
                                       pattern="^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="edit_port">Port</label>
                                <input type="number" class="form-control" id="edit_port" name="port" 
                                       value="4370" min="1" max="65535">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_location">Location</label>
                        <input type="text" class="form-control" id="edit_location" name="location">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Device</button>
                </div>
            </form>
        </div>
    </div>
</div>
