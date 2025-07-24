<?php
// Permission create/edit form view (AJAX, modal-friendly)
$editing = isset($_GET['id']) && intval($_GET['id']) > 0;
$perm = ['name' => '', 'id' => 0];
if ($editing) {
    // For modal use, fetch via AJAX in the modal, but fallback for direct access
    require_once __DIR__.'/../config/config.php';
    $id = intval($_GET['id']);
    $res = $conn->query("SELECT * FROM permissions WHERE id = $id");
    if ($row = $res->fetch_assoc()) {
        $perm = $row;
    }
}
?>
<form id="permissionForm" autocomplete="off">
    <input type="hidden" name="id" value="<?= htmlspecialchars($perm['id']) ?>">
    <div class="form-group">
        <label for="permName">Permission Name <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="permName" name="name" value="<?= htmlspecialchars($perm['name']) ?>" maxlength="100" required>
    </div>
    <div id="permissionFormAlert"></div>
    <div class="form-group d-flex justify-content-between mt-4">
        <button type="submit" class="btn btn-success px-4" id="savePermBtn"><i class="fas fa-save mr-1"></i> Save</button>
        <button type="button" class="btn btn-secondary px-4" data-dismiss="modal"><i class="fas fa-times mr-1"></i> Cancel</button>
    </div>
</form>
<script>
document.getElementById('permissionForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const saveBtn = document.getElementById('savePermBtn');
    saveBtn.disabled = true;
    const form = e.target;
    const name = form.name.value.trim();
    const id = form.id.value;
    if (!name) {
        showPermAlert('Permission name is required.', 'danger');
        saveBtn.disabled = false;
        return;
    }
    const data = { name: name };
    let method = 'POST';
    let url = 'controllers/permission_api.php';
    if (id && parseInt(id) > 0) {
        data.id = id;
        method = 'PUT';
        url += '?id=' + encodeURIComponent(id);
    }
    fetch(url, {
        method: method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showPermAlert('Permission saved successfully.', 'success');
            setTimeout(() => {
                if (window.parent && window.parent.$) {
                    window.parent.$('#addPermissionModal, #editPermissionModal').modal('hide');
                    window.parent.loadPermissions && window.parent.loadPermissions();
                } else {
                    window.location.href = 'permission_list.php';
                }
            }, 1000);
        } else {
            showPermAlert(data.error || 'Failed to save permission.', 'danger');
        }
    })
    .catch(() => {
        showPermAlert('Error saving permission.', 'danger');
    })
    .finally(() => { saveBtn.disabled = false; });
});
function showPermAlert(msg, type) {
    const alertDiv = document.getElementById('permissionFormAlert');
    alertDiv.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show mt-3" role="alert">
        ${msg}
        <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
    </div>`;
    setTimeout(() => { alertDiv.innerHTML = ''; }, 5000);
}
</script>
