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

$isSuperAdmin = is_super_admin();
$canManageRoles = $isSuperAdmin || has_permission('manage_roles');
if (!$canManageRoles && !has_permission('view_role_list')) {
    http_response_code(403);
    require __DIR__ . '/errors/403.php';
    exit;
}

$basePath = (string) (parse_url(BASE_URL, PHP_URL_PATH) ?: '');
$basePath = rtrim($basePath, '/');
$canCreate = $isSuperAdmin || ($canManageRoles && has_permission('create_role'));
$canEdit = $isSuperAdmin || ($canManageRoles && has_permission('edit_role'));
$canDeactivate = $isSuperAdmin || ($canManageRoles && has_permission('delete_role'));

ob_start();
?>
<style>
.access-workspace{max-width:1500px;margin:0 auto}.access-hero{border:0;border-radius:1rem;background:linear-gradient(135deg,#173f5f,#236b45);color:#fff;overflow:hidden}.access-hero .card-body{padding:1.5rem}.access-hero p{color:rgba(255,255,255,.78);max-width:720px}.access-metric{border:1px solid #e7edf3;border-radius:.9rem;background:#fff;padding:1rem;height:100%}.access-metric strong{display:block;font-size:1.55rem;color:#173f5f}.access-card{border:0;border-radius:1rem;box-shadow:0 .35rem 1.4rem rgba(23,63,95,.08)}.access-toolbar{display:grid;grid-template-columns:minmax(220px,1fr) 180px 180px;gap:.75rem}.access-table th{border-top:0;color:#526071;font-size:.73rem;text-transform:uppercase;letter-spacing:.055em}.role-name{font-weight:700;color:#173f5f}.role-description{color:#6b7785;font-size:.86rem;max-width:520px}.status-pill,.type-pill{display:inline-flex;align-items:center;border-radius:999px;padding:.24rem .55rem;font-size:.72rem;font-weight:700}.status-active{background:#e5f6ed;color:#176b3a}.status-inactive{background:#f2f3f5;color:#67717d}.type-system{background:#e7eff8;color:#173f5f}.type-custom{background:#f2eafd;color:#633b8c}.permission-editor{border:1px solid #dce7ef;border-radius:1rem;background:#f8fbfd}.permission-group{border:1px solid #e3eaf0;border-radius:.75rem;background:#fff;padding:.85rem}.permission-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.5rem}.permission-option{display:flex;align-items:flex-start;gap:.55rem;padding:.45rem;border-radius:.55rem}.permission-option:hover{background:#f2f7fa}.permission-code{font-weight:650;color:#213547;overflow-wrap:anywhere}.permission-note{font-size:.75rem;color:#7a8794}.empty-state{padding:3rem 1rem;text-align:center;color:#718096}@media(max-width:900px){.access-toolbar{grid-template-columns:1fr}.permission-grid{grid-template-columns:1fr}}@media(max-width:767px){.access-table thead{display:none}.access-table,.access-table tbody,.access-table tr,.access-table td{display:block;width:100%}.access-table tr{border:1px solid #e7edf3;border-radius:.8rem;margin-bottom:.8rem;padding:.7rem}.access-table td{border:0;padding:.3rem}.access-table td[data-label]:before{content:attr(data-label);display:block;font-size:.68rem;font-weight:700;text-transform:uppercase;color:#8793a1}}
</style>

<div class="access-workspace">
    <section class="card access-hero shadow-sm mb-4">
        <div class="card-body d-flex flex-column flex-lg-row align-items-lg-center justify-content-between">
            <div>
                <div class="small text-uppercase font-weight-bold mb-2" style="letter-spacing:.12em">Access governance</div>
                <h1 class="h3 font-weight-bold mb-2">Access Roles</h1>
                <p class="mb-0">Design responsibilities, review assignments and govern direct capabilities without duplicating inherited access.</p>
            </div>
            <?php if ($canCreate): ?>
                <a class="btn btn-light font-weight-bold mt-3 mt-lg-0" href="role_form.php"><i class="fas fa-plus mr-2"></i>Create custom role</a>
            <?php endif; ?>
        </div>
    </section>

    <div id="roleAlert" aria-live="polite"></div>

    <div class="row mb-4">
        <div class="col-6 col-xl-3 mb-3 mb-xl-0"><div class="access-metric"><span class="text-muted small">Total roles</span><strong id="metricTotal">—</strong></div></div>
        <div class="col-6 col-xl-3 mb-3 mb-xl-0"><div class="access-metric"><span class="text-muted small">Active roles</span><strong id="metricActive">—</strong></div></div>
        <div class="col-6 col-xl-3"><div class="access-metric"><span class="text-muted small">Protected roles</span><strong id="metricSystem">—</strong></div></div>
        <div class="col-6 col-xl-3"><div class="access-metric"><span class="text-muted small">Active assignments</span><strong id="metricAssignments">—</strong></div></div>
    </div>

    <section class="card access-card mb-4">
        <div class="card-body">
            <div class="access-toolbar mb-3">
                <label class="mb-0"><span class="sr-only">Search roles</span><input id="roleSearch" class="form-control" type="search" placeholder="Search role, description or parent…"></label>
                <label class="mb-0"><span class="sr-only">Status</span><select id="roleStatus" class="form-control"><option value="all">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                <label class="mb-0"><span class="sr-only">Role type</span><select id="roleType" class="form-control"><option value="all">All role types</option><option value="system">Protected system</option><option value="custom">Custom</option></select></label>
            </div>
            <div class="table-responsive">
                <table class="table access-table mb-0" aria-describedby="roleTableCaption">
                    <caption id="roleTableCaption" class="sr-only">Configured access roles and their direct permission and user counts.</caption>
                    <thead><tr><th>Role</th><th>Parent</th><th>Users</th><th>Direct grants</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
                    <tbody id="rolesTbody"><tr><td colspan="6" class="empty-state"><i class="fas fa-spinner fa-spin mr-2"></i>Loading roles…</td></tr></tbody>
                </table>
            </div>
        </div>
    </section>

    <section id="permissionEditor" class="permission-editor p-3 p-lg-4 mb-4" hidden>
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start mb-3">
            <div><div class="small text-uppercase text-muted font-weight-bold">Direct access grants</div><h2 id="permissionEditorTitle" class="h5 mb-1">Role permissions</h2><p class="text-muted mb-0">Inherited permissions remain effective through the parent role and are intentionally not copied into this list.</p></div>
            <button id="closePermissionEditor" type="button" class="btn btn-sm btn-outline-secondary mt-2 mt-lg-0"><i class="fas fa-times mr-1"></i>Close</button>
        </div>
        <div class="d-flex flex-column flex-md-row mb-3">
            <input id="permissionSearch" class="form-control mr-md-2 mb-2 mb-md-0" type="search" placeholder="Filter the permission catalogue…">
            <div class="btn-group"><button id="selectVisiblePermissions" type="button" class="btn btn-outline-primary">Select visible</button><button id="clearVisiblePermissions" type="button" class="btn btn-outline-secondary">Clear visible</button></div>
        </div>
        <div id="permissionCatalogue" class="row"><div class="col-12 empty-state"><i class="fas fa-spinner fa-spin mr-2"></i>Loading permissions…</div></div>
        <div class="d-flex justify-content-between align-items-center mt-3"><span id="permissionSelectionSummary" class="small text-muted">0 direct permissions selected</span><button id="saveRolePermissions" class="btn btn-primary" type="button"><i class="fas fa-save mr-2"></i>Save direct grants</button></div>
    </section>
</div>

<script>
(() => {
    'use strict';
    const apiBase = <?= json_encode($basePath . '/api/rbac') ?>;
    const csrfToken = <?= json_encode(csrf_token()) ?>;
    const roleFormUrl = <?= json_encode($basePath . '/views/role_form.php') ?>;
    const capabilities = <?= json_encode(['edit' => $canEdit, 'deactivate' => $canDeactivate, 'permissions' => $canManageRoles, 'superAdmin' => $isSuperAdmin]) ?>;
    const state = { roles: [], permissions: [], selected: new Set(), currentRole: null };
    const byId = id => document.getElementById(id);

    function icon(name, extraClass = '') { const node = document.createElement('i'); node.className = `fas fa-${name}${extraClass ? ` ${extraClass}` : ''}`; return node; }
    function textNode(tag, value, className = '') { const node = document.createElement(tag); if (className) node.className = className; node.textContent = value == null || value === '' ? '—' : String(value); return node; }
    function showAlert(message, type = 'danger') { const alert = document.createElement('div'); alert.className = `alert alert-${type} alert-dismissible fade show`; alert.setAttribute('role', 'alert'); alert.textContent = message; const close = document.createElement('button'); close.type = 'button'; close.className = 'close'; close.setAttribute('data-dismiss', 'alert'); close.setAttribute('aria-label', 'Close'); close.appendChild(document.createTextNode('×')); alert.appendChild(close); byId('roleAlert').replaceChildren(alert); window.scrollTo({ top: 0, behavior: 'smooth' }); }
    async function request(url, options = {}) { const response = await fetch(url, { credentials: 'same-origin', ...options, headers: { Accept: 'application/json', ...(options.body ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken } : {}), ...(options.headers || {}) } }); const raw = await response.text(); let payload; try { payload = JSON.parse(raw); } catch (_) { throw new Error(response.ok ? 'The server returned an invalid response.' : `Request failed (${response.status}).`); } if (!response.ok || payload.success === false) throw new Error(payload.message || payload.error || `Request failed (${response.status}).`); return payload; }
    function valueIsTrue(value) { return value === true || value === 1 || value === '1'; }

    async function loadRoles() {
        try { const payload = await request(`${apiBase}/roles.php`); state.roles = payload.data.roles || []; updateMetrics(); renderRoles(); }
        catch (error) { byId('rolesTbody').replaceChildren(emptyRow(error.message)); showAlert(error.message); }
    }
    function emptyRow(message) { const tr = document.createElement('tr'); const td = textNode('td', message, 'empty-state'); td.colSpan = 6; tr.appendChild(td); return tr; }
    function updateMetrics() { byId('metricTotal').textContent = state.roles.length; byId('metricActive').textContent = state.roles.filter(role => valueIsTrue(role.is_active)).length; byId('metricSystem').textContent = state.roles.filter(role => valueIsTrue(role.is_system)).length; byId('metricAssignments').textContent = state.roles.reduce((sum, role) => sum + Number(role.user_count || 0), 0); }
    function filteredRoles() { const query = byId('roleSearch').value.trim().toLowerCase(); const status = byId('roleStatus').value; const type = byId('roleType').value; return state.roles.filter(role => { const active = valueIsTrue(role.is_active); const system = valueIsTrue(role.is_system); const haystack = [role.name, role.description, role.parent_role_name].join(' ').toLowerCase(); return (!query || haystack.includes(query)) && (status === 'all' || (status === 'active') === active) && (type === 'all' || (type === 'system') === system); }); }
    function actionButton(label, iconName, className, handler) { const button = document.createElement('button'); button.type = 'button'; button.className = `btn btn-sm ${className} ml-1 mb-1`; button.title = label; button.append(icon(iconName, 'mr-1'), document.createTextNode(label)); button.addEventListener('click', handler); return button; }
    function renderRoles() {
        const tbody = byId('rolesTbody'); tbody.replaceChildren(); const roles = filteredRoles(); if (!roles.length) { tbody.appendChild(emptyRow('No roles match these filters.')); return; }
        roles.forEach(role => {
            const active = valueIsTrue(role.is_active), system = valueIsTrue(role.is_system); const tr = document.createElement('tr');
            const roleCell = document.createElement('td'); roleCell.dataset.label = 'Role'; roleCell.append(textNode('div', role.name, 'role-name'), textNode('div', role.description || 'No description provided.', 'role-description')); const badge = textNode('span', system ? 'Protected system' : 'Custom', `type-pill mt-1 ${system ? 'type-system' : 'type-custom'}`); roleCell.appendChild(badge);
            const parentCell = textNode('td', role.parent_role_name || 'No parent'); parentCell.dataset.label = 'Parent';
            const userCell = textNode('td', role.user_count || 0); userCell.dataset.label = 'Users';
            const permissionCell = textNode('td', role.permission_count || 0); permissionCell.dataset.label = 'Direct grants';
            const statusCell = document.createElement('td'); statusCell.dataset.label = 'Status'; statusCell.appendChild(textNode('span', active ? 'Active' : 'Inactive', `status-pill ${active ? 'status-active' : 'status-inactive'}`));
            const actions = document.createElement('td'); actions.dataset.label = 'Actions'; actions.className = 'text-right';
            const mayManageProtected = !system || capabilities.superAdmin;
            if (capabilities.edit && !system) { const link = document.createElement('a'); link.className = 'btn btn-sm btn-outline-secondary ml-1 mb-1'; link.href = `${roleFormUrl}?id=${encodeURIComponent(role.id)}`; link.append(icon('pen', 'mr-1'), document.createTextNode('Edit')); actions.appendChild(link); }
            if (capabilities.permissions && mayManageProtected && active) actions.appendChild(actionButton('Permissions', 'key', 'btn-outline-primary', () => openPermissionEditor(role)));
            if (capabilities.deactivate && !system && active && Number(role.user_count || 0) === 0) actions.appendChild(actionButton('Deactivate', 'archive', 'btn-outline-danger', () => deactivateRole(role)));
            tr.append(roleCell, parentCell, userCell, permissionCell, statusCell, actions); tbody.appendChild(tr);
        });
    }
    async function deactivateRole(role) { if (!window.confirm(`Deactivate “${role.name}”? Historical assignments and audit evidence will be preserved.`)) return; try { await request(`${apiBase}/roles.php?id=${encodeURIComponent(role.id)}`, { method: 'DELETE', body: JSON.stringify({ csrf_token: csrfToken }) }); showAlert('Role deactivated. Existing evidence was preserved.', 'success'); await loadRoles(); } catch (error) { showAlert(error.message); } }
    async function openPermissionEditor(role) {
        state.currentRole = role; state.selected.clear(); byId('permissionEditor').hidden = false; byId('permissionEditorTitle').textContent = `${role.name}: direct permissions`; byId('permissionCatalogue').replaceChildren(textNode('div', 'Loading the permission catalogue…', 'col-12 empty-state'));
        try { const [catalogue, direct] = await Promise.all([request(`${apiBase}/permissions.php?is_active=true&delegation=true`), request(`${apiBase}/roles.php?id=${encodeURIComponent(role.id)}&permissions&include_inherited=false`)]); state.permissions = catalogue.data.permissions || []; (direct.data.permissions || []).forEach(permission => state.selected.add(Number(permission.id || permission.permission_id))); renderPermissions(); byId('permissionEditor').scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (error) { showAlert(error.message); byId('permissionEditor').hidden = true; }
    }
    function visiblePermissions() { const query = byId('permissionSearch').value.trim().toLowerCase(); return state.permissions.filter(permission => !query || [permission.name, permission.description, permission.category_name].join(' ').toLowerCase().includes(query)); }
    function renderPermissions() {
        const container = byId('permissionCatalogue'); container.replaceChildren(); const grouped = new Map(); visiblePermissions().forEach(permission => { const category = permission.category_name || 'Uncategorised'; if (!grouped.has(category)) grouped.set(category, []); grouped.get(category).push(permission); });
        if (!grouped.size) { container.appendChild(textNode('div', 'No permissions match this search.', 'col-12 empty-state')); updateSelectionSummary(); return; }
        grouped.forEach((permissions, category) => { const column = document.createElement('div'); column.className = 'col-12 col-xl-6 mb-3'; const group = document.createElement('div'); group.className = 'permission-group h-100'; group.appendChild(textNode('h3', category, 'h6 font-weight-bold text-primary')); const grid = document.createElement('div'); grid.className = 'permission-grid'; permissions.forEach(permission => { const label = document.createElement('label'); label.className = 'permission-option mb-0'; const input = document.createElement('input'); input.type = 'checkbox'; input.className = 'mt-1'; input.value = permission.id; input.checked = state.selected.has(Number(permission.id)); input.disabled = permission.can_delegate === false; input.addEventListener('change', () => { input.checked ? state.selected.add(Number(permission.id)) : state.selected.delete(Number(permission.id)); updateSelectionSummary(); }); const copy = document.createElement('span'); copy.append(textNode('span', permission.name, 'permission-code d-block'), textNode('span', permission.can_delegate === false ? 'Retained as-is; this capability is outside your delegated authority.' : (permission.description || 'No description.'), 'permission-note d-block')); label.append(input, copy); grid.appendChild(label); }); group.appendChild(grid); column.appendChild(group); container.appendChild(column); }); updateSelectionSummary();
    }
    function updateSelectionSummary() { byId('permissionSelectionSummary').textContent = `${state.selected.size} direct permission${state.selected.size === 1 ? '' : 's'} selected`; }
    async function savePermissions() { if (!state.currentRole) return; const button = byId('saveRolePermissions'); button.disabled = true; try { await request(`${apiBase}/roles.php?id=${encodeURIComponent(state.currentRole.id)}&sync`, { method: 'POST', body: JSON.stringify({ permission_ids: Array.from(state.selected), csrf_token: csrfToken }) }); showAlert(`Direct permissions for ${state.currentRole.name} were updated.`, 'success'); await loadRoles(); } catch (error) { showAlert(error.message); } finally { button.disabled = false; } }

    ['roleSearch', 'roleStatus', 'roleType'].forEach(id => byId(id).addEventListener(id === 'roleSearch' ? 'input' : 'change', renderRoles));
    byId('permissionSearch').addEventListener('input', renderPermissions);
    byId('selectVisiblePermissions').addEventListener('click', () => { visiblePermissions().filter(permission => permission.can_delegate !== false).forEach(permission => state.selected.add(Number(permission.id))); renderPermissions(); });
    byId('clearVisiblePermissions').addEventListener('click', () => { visiblePermissions().filter(permission => permission.can_delegate !== false).forEach(permission => state.selected.delete(Number(permission.id))); renderPermissions(); });
    byId('closePermissionEditor').addEventListener('click', () => { byId('permissionEditor').hidden = true; state.currentRole = null; });
    byId('saveRolePermissions').addEventListener('click', savePermissions);
    loadRoles();
})();
</script>
<?php
$page_content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>
