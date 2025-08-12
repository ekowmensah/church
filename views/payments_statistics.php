<?php
// Error reporting for development (remove or comment out in production)

ob_start();
require_once '../config/config.php';
require_once '../helpers/auth.php';
require_once '../helpers/permissions.php';
require_once '../includes/admin_auth.php';
require_once '../includes/report_ui_helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// --- CONFIG ---
$date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : date('Y-m-d');
$currency = '₵';
$denominations = [
    ['label' => '200 Note', 'value' => 200],
    ['label' => '100 Note', 'value' => 100],
    ['label' => '50 Note',  'value' => 50],
    ['label' => '20 Note',  'value' => 20],
    ['label' => '10 Note',  'value' => 10],
    ['label' => '5 Note',   'value' => 5],
    ['label' => '2 Note',   'value' => 2],
    ['label' => '1 Note',   'value' => 1],
    ['label' => '2 Coin',   'value' => 2],
    ['label' => '1 Coin',   'value' => 1],
    ['label' => '0.50p',    'value' => 0.5],
    ['label' => '0.20p',    'value' => 0.2],
    ['label' => '0.10p',    'value' => 0.1],
];
// --- FETCH TOTAL CASH ---
$total_cash = 0.00;
try {
    $stmt = $conn->prepare("SELECT SUM(amount) as total FROM payments WHERE DATE(payment_date)=? AND mode='cash'");
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $stmt->bind_result($total_cash);
    $stmt->fetch();
    $stmt->close();
    $total_cash = floatval($total_cash);
} catch (Throwable $e) {
    $total_cash = 0.00;
}
// --- HANDLE FORM SUBMIT ---
$entry = [];
$entry_total = 0;
$success = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['denom'])) {
    foreach ($denominations as $d) {
        $key = $d['label'];
        $qty = isset($_POST['denom'][$key]) && is_numeric($_POST['denom'][$key]) ? max(0, intval($_POST['denom'][$key])) : 0;
        $entry[$key] = $qty;
        $entry_total += $qty * $d['value'];
    }
    if (abs($entry_total - $total_cash) > 0.009) {
        $error = "Total denominations (₵".number_format($entry_total,2).") do not match cash received (₵".number_format($total_cash,2).")!";
    } else {
        $_SESSION['denom_entry_'.$date] = $entry;
        $success = "Denomination entry saved!";
    }
} elseif (isset($_SESSION['denom_entry_'.$date])) {
    $entry = $_SESSION['denom_entry_'.$date];
    foreach ($denominations as $d) {
        $entry_total += (isset($entry[$d['label']]) ? $entry[$d['label']] : 0) * $d['value'];
    }
}
?>
<main class="container py-4 animate__animated animate__fadeIn">
    <div class="row mb-3 justify-content-center">
        <div class="col-12 col-md-10 col-lg-8">
            <?php render_report_filter_bar($date, 'btn-warning'); ?>
        </div>
    </div>
    <div class="row justify-content-center">
        <div class="col-12 col-md-10 col-lg-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light d-flex align-items-center justify-content-between">
                    <div><i class="fas fa-chart-bar text-primary mr-2"></i>Payment Statistics</div>
                    <button class="btn btn-success btn-sm" data-toggle="modal" data-target="#denomModal"><i class="fas fa-plus-circle mr-1"></i>Add Cash Analysis</button>
                </div>
                <div class="card-body">
    <div class="mb-3">
        <span class="h5">Total Received: <span class="text-success font-weight-bold">₵<?= number_format($total_cash,2) ?></span></span>
    </div>
    <div class="table-responsive mb-2">
        <table class="table table-bordered table-hover mb-0" style="background:#fcfcfc;">
            <thead class="thead-light">
                <tr>
                    <th>Payment Type</th>
                    <th>Count</th>
                    <th>Total Amount (₵)</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Fetch payment type breakdown
                $types = [];
                try {
                    $sql = "SELECT pt.name AS payment_type, SUM(p.amount) AS total_amount, COUNT(p.id) AS count
                            FROM payments p
                            JOIN payment_types pt ON p.payment_type_id = pt.id
                            WHERE DATE(p.payment_date) = ?
                            GROUP BY pt.id
                            ORDER BY total_amount DESC";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param('s', $date);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $types[] = $row;
                    }
                    $stmt->close();
                } catch (Throwable $e) {}
                ?>
                <?php if ($types): foreach ($types as $t): ?>
                    <tr>
                        <td><?= htmlspecialchars($t['payment_type']) ?></td>
                        <td><?= intval($t['count']) ?></td>
                        <td>₵<?= number_format($t['total_amount'],2) ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="3" class="text-center text-muted">No payments recorded for this date.</td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="font-weight-bold bg-light">
                    <td class="text-right">Grand Total</td>
                    <td><?= array_sum(array_column($types,'count')) ?></td>
                    <td>₵<?= number_format($total_cash,2) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
            </div>
        </div>
    </div>
</main>
<?php ob_start(); ?>
               <!-- Denomination Modal -->
               <div class="modal fade" id="denomModal" tabindex="-1" role="dialog" aria-labelledby="denomModalLabel" aria-hidden="true">
                 <div class="modal-dialog modal-lg" role="document">
                   <div class="modal-content">
                     <div class="modal-header py-2">
   <h6 class="modal-title w-100 text-center" id="denomModalLabel">Cash Denominations</h6>
   <button type="button" class="close" data-dismiss="modal" aria-label="Close">
     <span aria-hidden="true">&times;</span>
   </button>
</div>
                     <div class="modal-body">
                       <form method="post" id="denomForm" autocomplete="off">
   <div class="row">
       <?php
       $split = ceil(count($denominations)/2);
       $columns = [array_slice($denominations, 0, $split), array_slice($denominations, $split)];
       foreach ($columns as $colIdx => $col): ?>
       <div class="col-6">
           <table class="table table-sm table-striped table-borderless mb-2">
               <thead>
               <tr>
                   <th style="width:50%">Denomination</th>
                   <th style="width:30%" class="text-center">Qty</th>
                   <th style="width:20%" class="text-right">₵</th>
               </tr>
               </thead>
               <tbody>
               <?php foreach ($col as $d): $label = $d['label']; ?>
                   <tr>
                       <td><?= htmlspecialchars($label) ?></td>
                       <td class="text-center"><input type="number" min="0" name="denom[<?= htmlspecialchars($label) ?>]" class="form-control form-control-sm denom-qty text-center" style="width:60px;display:inline-block;" value="<?= isset($entry[$label]) ? $entry[$label] : '' ?>" /></td>
                       <td class="text-right">₵<span class="denom-value" data-label="<?= htmlspecialchars($label) ?>">0.00</span></td>
                   </tr>
               <?php endforeach; ?>
               </tbody>
           </table>
       </div>
       <?php endforeach; ?>
   </div>
   <div class="row align-items-center">
       <div class="col-6 text-right font-weight-bold">Total</div>
       <div class="col-4"></div>
       <div class="col-2 text-right font-weight-bold">₵<span id="denom-total">0.00</span></div>
   </div>
   <div class="d-flex justify-content-end mt-2">
       <button type="submit" class="btn btn-primary btn-sm px-4">Save</button>
   </div>
   <?php if ($error): ?><div class="alert alert-danger mt-2 py-1 px-2 small"><?= htmlspecialchars($error) ?></div><?php endif; ?>
   <?php if ($success): ?><div class="alert alert-success mt-2 py-1 px-2 small"><?= htmlspecialchars($success) ?></div><?php endif; ?>
</form>
                     </div>
                   </div>
                 </div>
               </div>
<?php $modal_html = ob_get_clean(); ?>
<script>
// Live denomination calculation (only inside modal)
const denomData = <?= json_encode($denominations) ?>;
function updateDenomTotals() {
    let total = 0;
    denomData.forEach(function(d) {
        let qty = parseInt(document.querySelector('[name="denom['+d.label+']"]')?.value) || 0;
        let value = qty * d.value;
        let el = document.querySelector('.denom-value[data-label="'+d.label+'"]');
        if (el) el.textContent = value.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
        total += value;
    });
    let totalCell = document.getElementById('denom-total');
    if (totalCell) {
        totalCell.textContent = total.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
        // Highlight error if not matching cash
        let expected = 0;
        <?php
        // Set expected to cash total (from payment type breakdown)
        foreach ($types as $t) if (strtolower($t['payment_type'])==='cash') echo "expected = ".floatval($t['total_amount']).";";
        ?>
        totalCell.parentElement.classList.remove('bg-danger','bg-warning','bg-success','text-white','text-dark');
        if (Math.abs(total-expected) < 0.01) {
            totalCell.parentElement.classList.add('bg-success','text-white');
        } else if (total < expected) {
            totalCell.parentElement.classList.add('bg-danger','text-white');
        } else if (total > expected) {
            totalCell.parentElement.classList.add('bg-warning','text-dark');
        }
    }
}
document.addEventListener('DOMContentLoaded', function() {
    // Always initialize modal total to red on open
    $('#denomModal').on('shown.bs.modal', function() {
        document.querySelectorAll('.denom-qty').forEach(function(inp) {
            inp.addEventListener('input', updateDenomTotals);
        });
        // Set total cell to red by default (0)
        let totalCell = document.getElementById('denom-total');
        if (totalCell) {
            totalCell.textContent = '0.00';
            totalCell.parentElement.classList.remove('bg-danger','bg-warning','bg-success','text-white','text-dark');
            totalCell.parentElement.classList.add('bg-danger','text-white');
        }
        updateDenomTotals();
    });
});
</script>
<?php
$page_content = ob_get_clean();
echo $modal_html; // Output modal HTML at the end to avoid stacking issues
include __DIR__.'/../includes/layout.php';
?>