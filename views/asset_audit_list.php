<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

asset_require_permission('view_asset_audit');

$isSuper = asset_is_super_admin();
$churchId = $isSuper ? (isset($_GET['church_id']) && (int) $_GET['church_id'] > 0 ? (int) $_GET['church_id'] : null) : asset_current_church_id($conn);
$assetCode = trim((string) ($_GET['asset_code'] ?? ''));
$action = trim((string) ($_GET['action'] ?? ''));

$tableExists = asset_table_exists($conn, 'asset_audit_log');
$churches = [];
if ($isSuper) {
    $resChurches = $conn->query('SELECT id, name FROM churches ORDER BY name ASC');
    while ($row = $resChurches->fetch_assoc()) {
        $churches[] = $row;
    }
}

$rows = [];
$departmentNames = [];
if ($tableExists) {
    $deptRes = $conn->query('SELECT id, name FROM asset_departments');
    while ($deptRes && ($deptRow = $deptRes->fetch_assoc())) {
        $departmentNames[(int) $deptRow['id']] = (string) $deptRow['name'];
    }

    $sql = "
        SELECT aal.*, a.asset_code, a.item_name, u.name AS performed_by_name, c.name AS church_name
        FROM asset_audit_log aal
        LEFT JOIN assets a ON a.id = aal.asset_id
        LEFT JOIN users u ON u.id = aal.performed_by
        LEFT JOIN churches c ON c.id = aal.church_id
        WHERE 1
    ";
    $types = '';
    $params = [];

    if ($churchId !== null) {
        $sql .= ' AND aal.church_id = ?';
        $types .= 'i';
        $params[] = $churchId;
    }
    if ($assetCode !== '') {
        $sql .= ' AND a.asset_code LIKE ?';
        $types .= 's';
        $params[] = '%' . $assetCode . '%';
    }
    if ($action !== '') {
        $sql .= ' AND aal.action = ?';
        $types .= 's';
        $params[] = $action;
    }

    $sql .= ' ORDER BY aal.performed_at DESC';
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
}

$normalizeAuditPayload = static function (?string $json, array $departmentNames): array {
    if ($json === null || trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return ['raw_value' => $json];
    }

    if (isset($decoded['from_department_id'])) {
        $id = (int) $decoded['from_department_id'];
        $decoded['from_department_name'] = $departmentNames[$id] ?? ('Department #' . $id);
    }
    if (isset($decoded['to_department_id'])) {
        $id = (int) $decoded['to_department_id'];
        $decoded['to_department_name'] = $departmentNames[$id] ?? ('Department #' . $id);
    }
    if (isset($decoded['department_id'])) {
        $id = (int) $decoded['department_id'];
        $decoded['department_name'] = $departmentNames[$id] ?? ('Department #' . $id);
    }

    return $decoded;
};

$renderAuditPayload = static function (?string $json, array $departmentNames) use ($normalizeAuditPayload): string {
    $payload = $normalizeAuditPayload($json, $departmentNames);
    if (empty($payload)) {
        return '<span class="text-muted">-</span>';
    }

    $html = '<div style="min-width:220px;">';
    foreach ($payload as $key => $value) {
        if (in_array($key, ['from_department_id', 'to_department_id', 'department_id'], true)) {
            continue;
        }
        $label = ucwords(str_replace('_', ' ', (string) $key));
        if (is_array($value)) {
            $valueText = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($valueText === false) {
                $valueText = '[invalid json]';
            }
        } else {
            $valueText = (string) $value;
        }
        $html .= '<div class="mb-1"><span class="text-muted" style="font-size:11px;">' . htmlspecialchars($label) . '</span><br><strong style="font-size:13px;">' . htmlspecialchars($valueText) . '</strong></div>';
    }
    $html .= '</div>';

    return $html;
};

ob_start();
?>
<div class="container-fluid mt-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h2 class="mb-1"><i class="fas fa-user-shield mr-2"></i>Asset Audit Log</h2>
            <small class="text-muted">Immutable activity history for asset operations.</small>
        </div>
        <a href="asset_list.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left mr-1"></i> Back to Assets</a>
    </div>

    <?php if (!$tableExists): ?>
        <div class="alert alert-warning">Asset audit table not found. Run the enterprise assets patch to enable audit logging.</div>
    <?php else: ?>
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <form method="get" class="form-row align-items-end">
                    <?php if ($isSuper): ?>
                    <div class="form-group col-md-3">
                        <label>Church</label>
                        <select class="form-control" name="church_id">
                            <option value="">All Churches</option>
                            <?php foreach ($churches as $church): ?>
                                <option value="<?= (int) $church['id'] ?>" <?= $churchId === (int) $church['id'] ? 'selected' : '' ?>><?= htmlspecialchars($church['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="form-group col-md-3">
                        <label>Asset Code</label>
                        <input type="text" name="asset_code" class="form-control" value="<?= htmlspecialchars($assetCode) ?>" placeholder="AST-...">
                    </div>
                    <div class="form-group col-md-3">
                        <label>Action</label>
                        <input type="text" name="action" class="form-control" value="<?= htmlspecialchars($action) ?>" placeholder="asset_update">
                    </div>
                    <div class="form-group col-md-2">
                        <button type="submit" class="btn btn-outline-primary btn-block">Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body table-responsive">
                <table class="table table-bordered table-hover" id="assetAuditTable">
                    <thead class="thead-light">
                        <tr>
                            <th>When</th>
                            <th>Asset</th>
                            <th>Action</th>
                            <th>Performed By</th>
                            <th>Before</th>
                            <th>After</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) ($row['performed_at'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) (($row['asset_code'] ?? '-') . ' ' . ($row['item_name'] ?? ''))) ?></td>
                                <td><span class="badge badge-info"><?= htmlspecialchars((string) ($row['action'] ?? '')) ?></span></td>
                                <td><?= htmlspecialchars((string) ($row['performed_by_name'] ?? '-')) ?></td>
                                <td><?= $renderAuditPayload((string) ($row['before_json'] ?? ''), $departmentNames) ?></td>
                                <td><?= $renderAuditPayload((string) ($row['after_json'] ?? ''), $departmentNames) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="6" class="text-center">No audit records found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
<script>
$(function(){
  if ($.fn.DataTable) {
    $('#assetAuditTable').DataTable({pageLength: 25, order:[[0,'desc']]});
  }
});
</script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
