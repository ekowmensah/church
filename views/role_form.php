<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!is_super_admin() && !has_permission('manage_roles')) {
    http_response_code(403);
    require __DIR__ . '/errors/403.php';
    exit;
}

$roleId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$editing = $roleId > 0;
$requiredOperation = $editing ? 'edit_role' : 'create_role';
if (!is_super_admin() && !has_permission($requiredOperation)) {
    http_response_code(403);
    require __DIR__ . '/errors/403.php';
    exit;
}
$role = ['id' => 0, 'name' => '', 'description' => '', 'parent_id' => null, 'is_active' => 1, 'is_system' => 0];
$errors = [];

if ($editing) {
    $stmt = $conn->prepare('SELECT id, name, description, parent_id, is_active, is_system FROM roles WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $roleId);
    $stmt->execute();
    $loadedRole = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$loadedRole) {
        $errors[] = 'Role not found.';
    } elseif ((int) $loadedRole['is_system'] === 1) {
        $errors[] = 'Protected system roles are migration-managed. Their direct permissions may be reviewed from Access Roles by a Super Administrator.';
    } else {
        $role = $loadedRole;
    }
}

$parentRoles = [];
$parentResult = $conn->query("SELECT id, name FROM roles WHERE is_active = 1 AND is_system = 0 ORDER BY name");
while ($parent = $parentResult->fetch_assoc()) {
    if ((int) $parent['id'] !== $roleId) {
        $parentRoles[] = $parent;
    }
}
$basePath = rtrim((string) (parse_url(BASE_URL, PHP_URL_PATH) ?: ''), '/');

ob_start();
?>
<style>
.role-editor{max-width:900px;margin:0 auto}.role-editor-hero{border:0;border-radius:1rem;background:linear-gradient(135deg,#173f5f,#236b45);color:#fff}.role-editor-card{border:0;border-radius:1rem;box-shadow:0 .35rem 1.4rem rgba(23,63,95,.08)}.governance-note{border-left:4px solid #236b45;background:#f0f8f4;border-radius:.6rem;padding:1rem;color:#355747}
</style>
<div class="role-editor">
    <section class="card role-editor-hero shadow-sm mb-4"><div class="card-body p-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center"><div><div class="small text-uppercase font-weight-bold mb-2" style="letter-spacing:.12em">Access governance</div><h1 class="h3 font-weight-bold mb-1"><?= $editing ? 'Edit custom role' : 'Create custom role' ?></h1><p class="mb-0 text-white-50">Define responsibility and inheritance here; assign direct permissions from the Access Roles workspace.</p></div><a href="role_list.php" class="btn btn-outline-light mt-3 mt-md-0"><i class="fas fa-arrow-left mr-2"></i>Access Roles</a></div></section>
    <div id="roleFormAlert" aria-live="polite"></div>
    <?php if ($errors): ?><div class="alert alert-danger"><strong>Unable to edit this role.</strong><ul class="mb-0 mt-2"><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if (!$errors): ?>
    <section class="card role-editor-card mb-4"><div class="card-body p-4">
        <form id="roleForm" novalidate>
            <div class="form-group"><label for="roleName">Role name <span class="text-danger">*</span></label><input id="roleName" class="form-control form-control-lg" maxlength="100" required value="<?= htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8') ?>"><small class="form-text text-muted">Use a stable responsibility name such as Finance Reviewer or Bible Class Coordinator.</small></div>
            <div class="form-group"><label for="roleDescription">Business purpose <span class="text-danger">*</span></label><textarea id="roleDescription" class="form-control" rows="3" maxlength="500" required><?= htmlspecialchars((string) $role['description'], ENT_QUOTES, 'UTF-8') ?></textarea><small class="form-text text-muted">Describe who should receive this role and what responsibility it represents.</small></div>
            <div class="row"><div class="col-md-7 form-group"><label for="roleParent">Parent role</label><select id="roleParent" class="form-control"><option value="">No inherited role</option><?php foreach ($parentRoles as $parent): ?><option value="<?= (int) $parent['id'] ?>" <?= (int) ($role['parent_id'] ?? 0) === (int) $parent['id'] ? 'selected' : '' ?>><?= htmlspecialchars($parent['name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><small class="form-text text-muted">The child receives the parent’s effective permissions without duplicating direct grants.</small></div><div class="col-md-5 form-group"><label class="d-block">Lifecycle</label><div class="custom-control custom-switch mt-2"><input id="roleActive" type="checkbox" class="custom-control-input" <?= (int) $role['is_active'] === 1 ? 'checked' : '' ?>><label class="custom-control-label" for="roleActive">Role is active</label></div></div></div>
            <div class="governance-note mb-4"><i class="fas fa-shield-alt mr-2"></i>System roles cannot be created or edited in the browser. Changes here are audited and role hierarchy cycles are rejected server-side.</div>
            <div class="d-flex justify-content-end"><a href="role_list.php" class="btn btn-outline-secondary mr-2">Cancel</a><button id="saveRole" class="btn btn-primary" type="submit"><i class="fas fa-save mr-2"></i>Save role</button></div>
        </form>
    </div></section>
    <?php endif; ?>
</div>
<?php if (!$errors): ?>
<script>
(() => {
    'use strict';
    const editing = <?= json_encode($editing) ?>;
    const roleId = <?= json_encode($roleId) ?>;
    const apiUrl = <?= json_encode($basePath . '/api/rbac/roles.php') ?>;
    const listUrl = <?= json_encode($basePath . '/views/role_list.php') ?>;
    const csrfToken = <?= json_encode(csrf_token()) ?>;
    const byId = id => document.getElementById(id);
    function showAlert(message, type = 'danger') { const alert = document.createElement('div'); alert.className = `alert alert-${type}`; alert.setAttribute('role', 'alert'); alert.textContent = message; byId('roleFormAlert').replaceChildren(alert); }
    async function submitRole(event) {
        event.preventDefault(); const form = byId('roleForm'); if (!form.reportValidity()) return;
        const data = { name: byId('roleName').value.trim(), description: byId('roleDescription').value.trim(), parent_id: byId('roleParent').value ? Number(byId('roleParent').value) : null, is_active: byId('roleActive').checked, csrf_token: csrfToken };
        const button = byId('saveRole'); button.disabled = true;
        try { const response = await fetch(editing ? `${apiUrl}?id=${encodeURIComponent(roleId)}` : apiUrl, { method: editing ? 'PUT' : 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken }, body: JSON.stringify(data) }); const raw = await response.text(); let payload; try { payload = JSON.parse(raw); } catch (_) { throw new Error(response.ok ? 'The server returned an invalid response.' : `Request failed (${response.status}).`); } if (!response.ok || payload.success === false) throw new Error(payload.message || payload.error || 'Role could not be saved.'); showAlert('Role saved successfully.', 'success'); window.setTimeout(() => { window.location.href = listUrl; }, 700); }
        catch (error) { showAlert(error.message); }
        finally { button.disabled = false; }
    }
    byId('roleForm').addEventListener('submit', submitRole);
})();
</script>
<?php endif; ?>
<?php
$page_content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>
