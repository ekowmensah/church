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
if (!is_super_admin() && !has_permission('manage_permissions')) {
    http_response_code(403);
    require __DIR__ . '/errors/403.php';
    exit;
}

$categories = [];
$categoryResult = $conn->query(
    'SELECT id, name FROM permission_categories WHERE is_active = 1 ORDER BY sort_order, name'
);
while ($category = $categoryResult->fetch_assoc()) {
    $categories[] = ['id' => (int) $category['id'], 'name' => $category['name']];
}
$basePath = rtrim((string) (parse_url(BASE_URL, PHP_URL_PATH) ?: ''), '/');
$permissionCapabilities = [
    'create' => is_super_admin() || has_permission('create_permission'),
    'edit' => is_super_admin() || has_permission('edit_permission'),
    'deactivate' => is_super_admin() || has_permission('delete_permission'),
];

ob_start();
?>
<style>
.permission-workspace{max-width:1500px;margin:0 auto}.permission-hero{border:0;border-radius:1rem;background:linear-gradient(135deg,#173f5f,#28557a);color:#fff}.permission-hero .card-body{padding:1.5rem}.permission-hero p{color:rgba(255,255,255,.78);max-width:760px}.permission-metric{height:100%;padding:1rem;border:1px solid #e4ebf1;border-radius:.9rem;background:#fff}.permission-metric strong{display:block;font-size:1.5rem;color:#173f5f}.permission-card{border:0;border-radius:1rem;box-shadow:0 .35rem 1.4rem rgba(23,63,95,.08)}.permission-toolbar{display:grid;grid-template-columns:minmax(240px,1fr) 190px 165px 165px;gap:.7rem}.permission-table th{border-top:0;color:#526071;font-size:.7rem;letter-spacing:.055em;text-transform:uppercase;white-space:nowrap}.permission-code{font-weight:700;color:#173f5f;overflow-wrap:anywhere}.permission-description{color:#6f7c89;font-size:.83rem;max-width:520px}.catalogue-pill{display:inline-flex;border-radius:999px;padding:.22rem .5rem;font-size:.7rem;font-weight:700}.risk-standard{background:#eef2f5;color:#506070}.risk-sensitive{background:#fff1cd;color:#785900}.risk-financial{background:#e3f4eb;color:#176b3a}.risk-privileged,.risk-system_critical{background:#f9dddd;color:#942f2f}.context-pill{background:#e7eff8;color:#173f5f}.permission-editor{border:1px solid #dbe6ee;border-radius:1rem;background:#f8fbfd}.empty-state{padding:3rem 1rem;text-align:center;color:#718096}@media(max-width:1100px){.permission-toolbar{grid-template-columns:1fr 1fr}}@media(max-width:767px){.permission-toolbar{grid-template-columns:1fr}.permission-table thead{display:none}.permission-table,.permission-table tbody,.permission-table tr,.permission-table td{display:block;width:100%}.permission-table tr{border:1px solid #e4ebf1;border-radius:.8rem;padding:.65rem;margin-bottom:.75rem}.permission-table td{border:0;padding:.3rem}.permission-table td[data-label]:before{content:attr(data-label);display:block;color:#8793a1;font-size:.67rem;font-weight:700;text-transform:uppercase}}
</style>

<div class="permission-workspace">
    <section class="card permission-hero shadow-sm mb-4">
        <div class="card-body d-flex flex-column flex-lg-row align-items-lg-center justify-content-between">
            <div><div class="small text-uppercase font-weight-bold mb-2" style="letter-spacing:.12em">Capability catalogue</div><h1 class="h3 font-weight-bold mb-2">Permission Catalogue</h1><p class="mb-0">Maintain clearly named, risk-classified capabilities. Protected system permissions are deployment-owned and remain read-only.</p></div>
            <?php if ($permissionCapabilities['create']): ?><button id="createPermission" type="button" class="btn btn-light font-weight-bold mt-3 mt-lg-0"><i class="fas fa-plus mr-2"></i>Create custom permission</button><?php endif; ?>
        </div>
    </section>
    <div id="permissionAlert" aria-live="polite"></div>

    <div class="row mb-4">
        <div class="col-6 col-xl-3 mb-3 mb-xl-0"><div class="permission-metric"><span class="small text-muted">Catalogue size</span><strong id="metricPermissionTotal">—</strong></div></div>
        <div class="col-6 col-xl-3 mb-3 mb-xl-0"><div class="permission-metric"><span class="small text-muted">Active</span><strong id="metricPermissionActive">—</strong></div></div>
        <div class="col-6 col-xl-3"><div class="permission-metric"><span class="small text-muted">Context-scoped</span><strong id="metricPermissionContext">—</strong></div></div>
        <div class="col-6 col-xl-3"><div class="permission-metric"><span class="small text-muted">High risk</span><strong id="metricPermissionRisk">—</strong></div></div>
    </div>

    <section id="permissionEditor" class="permission-editor p-3 p-lg-4 mb-4" hidden>
        <div class="d-flex justify-content-between align-items-start mb-3"><div><div class="small text-uppercase text-muted font-weight-bold">Governed catalogue entry</div><h2 id="editorHeading" class="h5 mb-1">Create custom permission</h2><p id="editorHelp" class="small text-muted mb-0">Permission codes are stable API contracts. Use lowercase words separated by underscores or dots.</p></div><button id="closeEditor" class="btn btn-sm btn-outline-secondary" type="button"><i class="fas fa-times mr-1"></i>Close</button></div>
        <form id="permissionForm" novalidate>
            <input id="permissionId" type="hidden">
            <div class="row">
                <div class="col-lg-6 form-group"><label for="permissionName">Permission code <span class="text-danger">*</span></label><input id="permissionName" class="form-control" maxlength="120" pattern="[a-z][a-z0-9_.-]{2,119}" required><small class="form-text text-muted">Example: <code>approve_payment_corrections</code></small></div>
                <div class="col-lg-6 form-group"><label for="permissionCategory">Category <span class="text-danger">*</span></label><select id="permissionCategory" class="form-control" required><option value="">Choose category</option><?php foreach ($categories as $category): ?><option value="<?= $category['id'] ?>"><?= htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                <div class="col-12 form-group"><label for="permissionDescription">Business description <span class="text-danger">*</span></label><textarea id="permissionDescription" class="form-control" rows="2" maxlength="500" required></textarea></div>
                <div class="col-md-6 col-lg-3 form-group"><label for="permissionType">Capability type</label><select id="permissionType" class="form-control"><option value="action">Action</option><option value="resource">Resource</option><option value="feature">Feature</option><option value="system">System operation</option></select></div>
                <div class="col-md-6 col-lg-3 form-group"><label for="permissionRisk">Risk level</label><select id="permissionRisk" class="form-control"><option value="standard">Standard</option><option value="sensitive">Sensitive</option><option value="financial">Financial</option><option value="privileged">Privileged</option><option value="system_critical">System critical</option></select></div>
                <div class="col-md-6 col-lg-3 form-group"><label for="permissionParent">Parent permission</label><select id="permissionParent" class="form-control"><option value="">No parent</option></select></div>
                <div class="col-md-6 col-lg-3 form-group"><label for="permissionSort">Sort order</label><input id="permissionSort" class="form-control" type="number" min="0" max="9999" value="0"></div>
                <div class="col-md-6 form-group"><div class="custom-control custom-switch mt-md-4"><input id="permissionRequiresContext" class="custom-control-input" type="checkbox"><label class="custom-control-label" for="permissionRequiresContext">Require a resource context</label></div></div>
                <div class="col-md-6 form-group"><div class="custom-control custom-switch mt-md-4"><input id="permissionActive" class="custom-control-input" type="checkbox" checked><label class="custom-control-label" for="permissionActive">Permission is active</label></div></div>
                <div id="contextFields" class="col-12" hidden><div class="row"><div class="col-md-6 form-group"><label for="permissionContextType">Context type</label><select id="permissionContextType" class="form-control"><option value="church">Church</option><option value="bible_class">Bible class</option><option value="organization">Organization</option><option value="organization_unit">Organization unit</option></select></div><div class="col-md-6 form-group"><label for="permissionContextKey">Context key</label><input id="permissionContextKey" class="form-control" maxlength="60" value="church_id"></div></div></div>
            </div>
            <div class="d-flex justify-content-end"><button id="savePermission" type="submit" class="btn btn-primary"><i class="fas fa-save mr-2"></i>Save permission</button></div>
        </form>
    </section>

    <section class="card permission-card mb-4"><div class="card-body">
        <div class="permission-toolbar mb-3">
            <input id="permissionSearch" class="form-control" type="search" placeholder="Search code, description or category…" aria-label="Search permissions">
            <select id="categoryFilter" class="form-control" aria-label="Filter by category"><option value="all">All categories</option><?php foreach ($categories as $category): ?><option value="<?= $category['id'] ?>"><?= htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select>
            <select id="riskFilter" class="form-control" aria-label="Filter by risk"><option value="all">All risk levels</option><option value="standard">Standard</option><option value="sensitive">Sensitive</option><option value="financial">Financial</option><option value="privileged">Privileged</option><option value="system_critical">System critical</option></select>
            <select id="statusFilter" class="form-control" aria-label="Filter by status"><option value="all">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
        </div>
        <div class="table-responsive"><table class="table permission-table mb-0"><thead><tr><th>Permission</th><th>Category</th><th>Type / risk</th><th>Scope</th><th>Status</th><th class="text-right">Actions</th></tr></thead><tbody id="permissionRows"><tr><td colspan="6" class="empty-state"><i class="fas fa-spinner fa-spin mr-2"></i>Loading permissions…</td></tr></tbody></table></div>
    </div></section>
</div>

<script>
(() => {
    'use strict';
    const apiUrl = <?= json_encode($basePath . '/api/rbac/permissions.php') ?>;
    const csrfToken = <?= json_encode(csrf_token()) ?>;
    const capabilities = <?= json_encode($permissionCapabilities) ?>;
    const state = { permissions: [], editing: null };
    const byId = id => document.getElementById(id);
    const truthy = value => value === true || value === 1 || value === '1';
    function icon(name, className = '') { const node = document.createElement('i'); node.className = `fas fa-${name}${className ? ` ${className}` : ''}`; return node; }
    function textNode(tag, value, className = '') { const node = document.createElement(tag); if (className) node.className = className; node.textContent = value == null || value === '' ? '—' : String(value); return node; }
    function showAlert(message, type = 'danger') { const alert = document.createElement('div'); alert.className = `alert alert-${type} alert-dismissible fade show`; alert.setAttribute('role', 'alert'); alert.textContent = message; const close = document.createElement('button'); close.type = 'button'; close.className = 'close'; close.setAttribute('data-dismiss', 'alert'); close.appendChild(document.createTextNode('×')); alert.appendChild(close); byId('permissionAlert').replaceChildren(alert); window.scrollTo({ top: 0, behavior: 'smooth' }); }
    async function request(url, options = {}) { const response = await fetch(url, { credentials: 'same-origin', ...options, headers: { Accept: 'application/json', ...(options.body ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken } : {}), ...(options.headers || {}) } }); const raw = await response.text(); let payload; try { payload = JSON.parse(raw); } catch (_) { throw new Error(response.ok ? 'The server returned an invalid response.' : `Request failed (${response.status}).`); } if (!response.ok || payload.success === false) throw new Error(payload.message || payload.error || `Request failed (${response.status}).`); return payload; }
    async function loadPermissions() { try { const payload = await request(apiUrl); state.permissions = payload.data.permissions || []; updateMetrics(); populateParentOptions(); renderPermissions(); } catch (error) { renderEmpty(error.message); showAlert(error.message); } }
    function updateMetrics() { byId('metricPermissionTotal').textContent = state.permissions.length; byId('metricPermissionActive').textContent = state.permissions.filter(permission => truthy(permission.is_active)).length; byId('metricPermissionContext').textContent = state.permissions.filter(permission => truthy(permission.requires_context)).length; byId('metricPermissionRisk').textContent = state.permissions.filter(permission => ['financial', 'privileged', 'system_critical'].includes(permission.risk_level)).length; }
    function filteredPermissions() { const query = byId('permissionSearch').value.trim().toLowerCase(), category = byId('categoryFilter').value, risk = byId('riskFilter').value, status = byId('statusFilter').value; return state.permissions.filter(permission => { const haystack = [permission.name, permission.permission_code, permission.description, permission.category_name].join(' ').toLowerCase(); const active = truthy(permission.is_active); return (!query || haystack.includes(query)) && (category === 'all' || String(permission.category_id) === category) && (risk === 'all' || permission.risk_level === risk) && (status === 'all' || (status === 'active') === active); }); }
    function renderEmpty(message) { const tr = document.createElement('tr'); const td = textNode('td', message, 'empty-state'); td.colSpan = 6; tr.appendChild(td); byId('permissionRows').replaceChildren(tr); }
    function makeButton(label, iconName, className, handler) { const button = document.createElement('button'); button.type = 'button'; button.className = `btn btn-sm ${className} ml-1 mb-1`; button.append(icon(iconName, 'mr-1'), document.createTextNode(label)); button.addEventListener('click', handler); return button; }
    function renderPermissions() {
        const tbody = byId('permissionRows'); tbody.replaceChildren(); const permissions = filteredPermissions(); if (!permissions.length) { renderEmpty('No permissions match these filters.'); return; }
        permissions.forEach(permission => { const active = truthy(permission.is_active), system = truthy(permission.is_system); const tr = document.createElement('tr'); const identity = document.createElement('td'); identity.dataset.label = 'Permission'; identity.append(textNode('div', permission.permission_code || permission.name, 'permission-code'), textNode('div', permission.description || 'No business description.', 'permission-description')); if (system) identity.appendChild(textNode('span', 'Protected system', 'catalogue-pill context-pill mt-1')); const category = textNode('td', permission.category_name || 'Uncategorised'); category.dataset.label = 'Category'; const typeRisk = document.createElement('td'); typeRisk.dataset.label = 'Type / risk'; typeRisk.append(textNode('div', permission.permission_type || 'action', 'text-capitalize'), textNode('span', String(permission.risk_level || 'standard').replace('_', ' '), `catalogue-pill risk-${permission.risk_level || 'standard'} text-capitalize`)); const context = document.createElement('td'); context.dataset.label = 'Scope'; context.appendChild(truthy(permission.requires_context) ? textNode('span', `${permission.context_type || 'church'} · ${permission.context_key || 'church_id'}`, 'catalogue-pill context-pill') : textNode('span', 'No context', 'text-muted small')); const status = document.createElement('td'); status.dataset.label = 'Status'; status.appendChild(textNode('span', active ? 'Active' : 'Inactive', `badge badge-${active ? 'success' : 'secondary'}`)); const actions = document.createElement('td'); actions.dataset.label = 'Actions'; actions.className = 'text-right'; if (!system) { if (capabilities.edit) actions.appendChild(makeButton('Edit', 'pen', 'btn-outline-secondary', () => openEditor(permission))); if (capabilities.deactivate && active) actions.appendChild(makeButton('Deactivate', 'archive', 'btn-outline-danger', () => deactivatePermission(permission))); } else actions.appendChild(textNode('span', 'Migration managed', 'small text-muted')); tr.append(identity, category, typeRisk, context, status, actions); tbody.appendChild(tr); });
    }
    function populateParentOptions() { const select = byId('permissionParent'), selected = select.value; select.replaceChildren(); const empty = document.createElement('option'); empty.value = ''; empty.textContent = 'No parent'; select.appendChild(empty); state.permissions.filter(permission => truthy(permission.is_active) && (!state.editing || Number(permission.id) !== Number(state.editing.id))).forEach(permission => { const option = document.createElement('option'); option.value = permission.id; option.textContent = permission.permission_code || permission.name; select.appendChild(option); }); select.value = selected; }
    function defaultContextKey() { const keys = { church: 'church_id', bible_class: 'class_id', organization: 'organization_id', organization_unit: 'organization_unit_id' }; return keys[byId('permissionContextType').value] || 'church_id'; }
    function toggleContextFields() { byId('contextFields').hidden = !byId('permissionRequiresContext').checked; }
    function openEditor(permission = null) { state.editing = permission; byId('permissionId').value = permission ? permission.id : ''; byId('editorHeading').textContent = permission ? `Edit ${permission.permission_code || permission.name}` : 'Create custom permission'; byId('permissionName').value = permission ? permission.name : ''; byId('permissionName').readOnly = Boolean(permission); byId('permissionDescription').value = permission ? permission.description || '' : ''; byId('permissionCategory').value = permission ? String(permission.category_id || '') : ''; byId('permissionType').value = permission ? permission.permission_type || 'action' : 'action'; byId('permissionRisk').value = permission ? permission.risk_level || 'standard' : 'standard'; byId('permissionSort').value = permission ? Number(permission.sort_order || 0) : 0; byId('permissionRequiresContext').checked = permission ? truthy(permission.requires_context) : false; byId('permissionActive').checked = permission ? truthy(permission.is_active) : true; populateParentOptions(); byId('permissionParent').value = permission && permission.parent_id ? String(permission.parent_id) : ''; byId('permissionContextType').value = permission ? permission.context_type || 'church' : 'church'; byId('permissionContextKey').value = permission ? permission.context_key || defaultContextKey() : 'church_id'; toggleContextFields(); byId('permissionEditor').hidden = false; byId('permissionEditor').scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    function closeEditor() { byId('permissionEditor').hidden = true; state.editing = null; }
    async function savePermission(event) { event.preventDefault(); if (!byId('permissionForm').reportValidity()) return; const editing = state.editing; const data = { name: byId('permissionName').value.trim(), description: byId('permissionDescription').value.trim(), category_id: Number(byId('permissionCategory').value), parent_id: byId('permissionParent').value ? Number(byId('permissionParent').value) : null, permission_type: byId('permissionType').value, risk_level: byId('permissionRisk').value, requires_context: byId('permissionRequiresContext').checked, context_type: byId('permissionContextType').value, context_key: byId('permissionContextKey').value.trim(), sort_order: Number(byId('permissionSort').value || 0), is_active: byId('permissionActive').checked, csrf_token: csrfToken }; const button = byId('savePermission'); button.disabled = true; try { await request(editing ? `${apiUrl}?id=${encodeURIComponent(editing.id)}` : apiUrl, { method: editing ? 'PUT' : 'POST', body: JSON.stringify(data) }); showAlert(editing ? 'Permission settings updated.' : 'Custom permission created.', 'success'); closeEditor(); await loadPermissions(); } catch (error) { showAlert(error.message); } finally { button.disabled = false; } }
    async function deactivatePermission(permission) { if (!window.confirm(`Deactivate “${permission.permission_code || permission.name}”? Existing audit history will be preserved.`)) return; try { await request(`${apiUrl}?id=${encodeURIComponent(permission.id)}`, { method: 'DELETE', body: JSON.stringify({ csrf_token: csrfToken }) }); showAlert('Permission deactivated. Existing audit history was preserved.', 'success'); await loadPermissions(); } catch (error) { showAlert(error.message); } }

    ['permissionSearch', 'categoryFilter', 'riskFilter', 'statusFilter'].forEach(id => byId(id).addEventListener(id === 'permissionSearch' ? 'input' : 'change', renderPermissions));
    if (byId('createPermission')) byId('createPermission').addEventListener('click', () => openEditor()); byId('closeEditor').addEventListener('click', closeEditor); byId('permissionForm').addEventListener('submit', savePermission); byId('permissionRequiresContext').addEventListener('change', toggleContextFields); byId('permissionContextType').addEventListener('change', () => { byId('permissionContextKey').value = defaultContextKey(); });
    loadPermissions();
})();
</script>
<?php
$page_content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>
