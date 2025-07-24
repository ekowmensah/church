<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/member_auth.php';

$member_id = $_SESSION['member_id'];
// Fetch member info
$stmt = $conn->prepare('SELECT first_name, middle_name, last_name, crn FROM members WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $member_id);
$stmt->execute();
$stmt->bind_result($first_name, $middle_name, $last_name, $crn);
$stmt->fetch();
$stmt->close();
$member_name = trim("$last_name $first_name $middle_name");
// Fetch all health records for this member
$stmt2 = $conn->prepare('SELECT * FROM health_records WHERE member_id = ? ORDER BY recorded_at DESC');
$stmt2->bind_param('i', $member_id);
$stmt2->execute();
$result = $stmt2->get_result();
$page_title = 'My Health Records';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0 text-gray-800"><i class="fas fa-heartbeat mr-2"></i>My Health Records</h1>
</div>
<?php $all_result = $result; include __DIR__.'/partials/health_bp_graph.php'; $result->data_seek(0); ?>
<div class="card shadow mb-4">
    <div class="card-header bg-primary text-white">
        <h6 class="m-0 font-weight-bold">All My Health Records</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="thead-light">
                    <tr>
                        <th>Date/Time</th>
                        <th>Weight (Kg)</th>
                        <th>Temperature (°C)</th>
                        <th>BP (MMHG)</th>
                        <th>BP Status</th>
                        <th>Sugar (mmol/L)</th>
                        <th>Sugar Status</th>
                        <th>Hep B</th>
                        <th>Malaria</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php while($rec = $result->fetch_assoc()): $v = json_decode($rec['vitals'], true) ?: []; ?>
                    <tr>
                        <td><?= htmlspecialchars($rec['recorded_at']) ?></td>
                        <td><?= htmlspecialchars($v['weight'] ?? '') ?></td>
                        <td><?= htmlspecialchars($v['temperature'] ?? '') ?></td>
                        <td><?= htmlspecialchars($v['bp'] ?? '') ?></td>
                        <td><?= htmlspecialchars($v['bp_status'] ?? '') ?></td>
                        <td><?= htmlspecialchars($v['sugar'] ?? '') ?></td>
                        <td><?= htmlspecialchars($v['sugar_status'] ?? '') ?></td>
                        <td><?= htmlspecialchars($v['hepatitis_b'] ?? '') ?></td>
                        <td><?= htmlspecialchars($v['malaria'] ?? '') ?></td>
                        <td><?= htmlspecialchars($rec['notes']) ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require_once __DIR__.'/../includes/layout.php';
