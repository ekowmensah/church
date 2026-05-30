<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

asset_require_permission('view_asset_reports');

$isSuper = asset_is_super_admin();
$churchId = $isSuper ? (isset($_GET['church_id']) && (int) $_GET['church_id'] > 0 ? (int) $_GET['church_id'] : null) : asset_current_church_id($conn);
$hasMaintenanceFields = asset_can_use_maintenance_fields($conn);

$churches = [];
if ($isSuper) {
    $resChurches = $conn->query('SELECT id, name FROM churches ORDER BY name ASC');
    while ($row = $resChurches->fetch_assoc()) {
        $churches[] = $row;
    }
}

$conditionRows = [];
$deptValueRows = [];
$maintenanceRows = [];

$sqlCond = 'SELECT condition_status, COUNT(*) AS total FROM assets WHERE 1';
$types = '';
$params = [];
if ($churchId !== null) {
    $sqlCond .= ' AND church_id = ?';
    $types = 'i';
    $params[] = $churchId;
}
$sqlCond .= ' GROUP BY condition_status ORDER BY total DESC';
$stmt = $conn->prepare($sqlCond);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $conditionRows[] = $row;
}
$stmt->close();

$sqlDept = "
    SELECT d.name AS department_name,
           COUNT(a.id) AS asset_count,
           SUM(COALESCE(a.amount, 0) * COALESCE(a.quantity, 1)) AS total_value
    FROM assets a
    LEFT JOIN asset_departments d ON d.id = a.department_id
    WHERE 1
";
$types = '';
$params = [];
if ($churchId !== null) {
    $sqlDept .= ' AND a.church_id = ?';
    $types = 'i';
    $params[] = $churchId;
}
$sqlDept .= ' GROUP BY d.name ORDER BY total_value DESC';
$stmt = $conn->prepare($sqlDept);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $deptValueRows[] = $row;
}
$stmt->close();

if ($hasMaintenanceFields) {
    $sqlMaint = "
        SELECT asset_code, item_name, next_maintenance_date, condition_status
        FROM assets
        WHERE next_maintenance_date IS NOT NULL
    ";
    $types = '';
    $params = [];
    if ($churchId !== null) {
        $sqlMaint .= ' AND church_id = ?';
        $types = 'i';
        $params[] = $churchId;
    }
    $sqlMaint .= ' ORDER BY next_maintenance_date ASC LIMIT 100';
    $stmt = $conn->prepare($sqlMaint);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $maintenanceRows[] = $row;
    }
    $stmt->close();
}

$movementTrend = [];
$sqlTrend = "
    SELECT DATE_FORMAT(moved_at, '%Y-%m') AS ym, COUNT(*) AS total
    FROM asset_movements am
    INNER JOIN assets a ON a.id = am.asset_id
    WHERE moved_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
";
$types = '';
$params = [];
if ($churchId !== null) {
    $sqlTrend .= ' AND a.church_id = ?';
    $types = 'i';
    $params[] = $churchId;
}
$sqlTrend .= ' GROUP BY ym ORDER BY ym ASC';
$stmt = $conn->prepare($sqlTrend);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $movementTrend[] = $row;
}
$stmt->close();

ob_start();
?>
<div class="container-fluid mt-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h2 class="mb-1"><i class="fas fa-chart-line mr-2"></i>Asset Reports</h2>
            <small class="text-muted">Condition trends, valuation by department, and maintenance scheduling insights.</small>
        </div>
        <a href="asset_list.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left mr-1"></i> Back to Assets</a>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <form method="get" class="form-row align-items-end">
                <?php if ($isSuper): ?>
                <div class="form-group col-md-4">
                    <label>Church</label>
                    <select name="church_id" class="form-control">
                        <option value="">All Churches</option>
                        <?php foreach ($churches as $church): ?>
                            <option value="<?= (int) $church['id'] ?>" <?= $churchId === (int) $church['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $church['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group col-md-2">
                    <button type="submit" class="btn btn-outline-primary btn-block">Apply</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-4 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header"><strong>Condition Distribution</strong></div>
                <div class="card-body">
                    <?php $conditionTotal = array_sum(array_map(static fn($r) => (int) $r['total'], $conditionRows)); ?>
                    <?php foreach ($conditionRows as $row): ?>
                        <?php $pct = $conditionTotal > 0 ? round(((int) $row['total'] / $conditionTotal) * 100, 1) : 0; ?>
                        <div class="mb-2">
                            <div class="d-flex justify-content-between"><span><?= htmlspecialchars((string) $row['condition_status']) ?></span><strong><?= (int) $row['total'] ?></strong></div>
                            <div class="progress" style="height:8px;"><div class="progress-bar bg-info" style="width:<?= $pct ?>%"></div></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($conditionRows)): ?><div class="text-muted">No data found.</div><?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-8 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header"><strong>Department Valuation</strong></div>
                <div class="card-body table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="thead-light"><tr><th>Department</th><th>Assets</th><th>Total Value</th></tr></thead>
                        <tbody>
                            <?php foreach ($deptValueRows as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) ($row['department_name'] ?? '-')) ?></td>
                                    <td><?= (int) ($row['asset_count'] ?? 0) ?></td>
                                    <td><?= number_format((float) ($row['total_value'] ?? 0), 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($deptValueRows)): ?><tr><td colspan="3" class="text-center">No valuation data.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-6 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header"><strong>Monthly Movement Trend (12 Months)</strong></div>
                <div class="card-body table-responsive">
                    <table class="table table-bordered table-hover mb-0">
                        <thead class="thead-light"><tr><th>Month</th><th>Transfers</th></tr></thead>
                        <tbody>
                            <?php foreach ($movementTrend as $row): ?>
                                <tr><td><?= htmlspecialchars((string) $row['ym']) ?></td><td><?= (int) $row['total'] ?></td></tr>
                            <?php endforeach; ?>
                            <?php if (empty($movementTrend)): ?><tr><td colspan="2" class="text-center">No transfer trend data.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header"><strong>Maintenance Due Schedule</strong></div>
                <div class="card-body table-responsive">
                    <?php if ($hasMaintenanceFields): ?>
                        <table class="table table-bordered table-hover mb-0">
                            <thead class="thead-light"><tr><th>Asset</th><th>Condition</th><th>Next Maintenance</th></tr></thead>
                            <tbody>
                                <?php foreach ($maintenanceRows as $row): ?>
                                    <?php
                                    $isOverdue = !empty($row['next_maintenance_date']) && strtotime((string) $row['next_maintenance_date']) < strtotime(date('Y-m-d'));
                                    ?>
                                    <tr class="<?= $isOverdue ? 'table-danger' : '' ?>">
                                        <td><?= htmlspecialchars((string) (($row['asset_code'] ?? '') . ' ' . ($row['item_name'] ?? ''))) ?></td>
                                        <td><?= htmlspecialchars((string) ($row['condition_status'] ?? '-')) ?></td>
                                        <td><?= htmlspecialchars((string) ($row['next_maintenance_date'] ?? '-')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($maintenanceRows)): ?><tr><td colspan="3" class="text-center">No maintenance schedule data.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="text-muted">Maintenance fields are not enabled in this database yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
