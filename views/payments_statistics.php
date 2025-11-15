<?php
// Error reporting for development (remove or comment out in production)

if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config/config.php';
require_once '../helpers/auth.php';
require_once '../helpers/permissions_v2.php';
require_once '../includes/report_ui_helpers.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('payment_statistics')) {
    http_response_code(403);
    if (file_exists(__DIR__.'/errors/403.php')) {
        include __DIR__.'/errors/403.php';
    } else if (file_exists(dirname(__DIR__).'/views/errors/403.php')) {
        include dirname(__DIR__).'/views/errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access payment statistics.</p></div>';
    }
    exit;
}

// Detect user role and filtering
$current_user_id = $_SESSION['user_id'] ?? 0;
$current_role_id = $_SESSION['role_id'] ?? 0;

// Super admin sees all data, others see only their own
$filter_by_user = !$is_super_admin;

// User-friendly label
$view_label = $filter_by_user ? 'My Payments' : 'All Payments';

// --- CONFIG ---
$date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : date('Y-m-d');
$currency = '₵';
$payment_modes = ['cash', 'cheque'];
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
// --- FETCH PAYMENT TOTALS FROM DATABASE ---
$total_cash = 0.00;
$total_cheque = 0.00;

try {
    if ($filter_by_user) {
        // Non-admin: Show only payments recorded by this user
        // Fetch cash total
        $stmt = $conn->prepare("SELECT SUM(amount) as total FROM payments WHERE DATE(payment_date)=? AND mode='cash' AND recorded_by=?");
        $stmt->bind_param('si', $date, $current_user_id);
        $stmt->execute();
        $stmt->bind_result($total_cash);
        $stmt->fetch();
        $stmt->close();
        $total_cash = floatval($total_cash);
        
        // Fetch cheque total
        $stmt = $conn->prepare("SELECT SUM(amount) as total FROM payments WHERE DATE(payment_date)=? AND mode='cheque' AND recorded_by=?");
        $stmt->bind_param('si', $date, $current_user_id);
        $stmt->execute();
        $stmt->bind_result($total_cheque);
        $stmt->fetch();
        $stmt->close();
        $total_cheque = floatval($total_cheque);
    } else {
        // Super admin: Show all payments
        // Fetch cash total
        $stmt = $conn->prepare("SELECT SUM(amount) as total FROM payments WHERE DATE(payment_date)=? AND mode='cash'");
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $stmt->bind_result($total_cash);
        $stmt->fetch();
        $stmt->close();
        $total_cash = floatval($total_cash);
        
        // Fetch cheque total
        $stmt = $conn->prepare("SELECT SUM(amount) as total FROM payments WHERE DATE(payment_date)=? AND mode='cheque'");
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $stmt->bind_result($total_cheque);
        $stmt->fetch();
        $stmt->close();
        $total_cheque = floatval($total_cheque);
    }
} catch (Throwable $e) {
    $total_cash = 0.00;
    $total_cheque = 0.00;
}
// --- LOAD EXISTING ANALYSES FROM DATABASE ---
$entry = [];
$entry_total = 0;
$cheque_entry = ['count' => 0, 'total' => 0.00, 'details' => ''];

try {
    // Load analyses for current date and user
    if ($filter_by_user) {
        $stmt = $conn->prepare("
            SELECT payment_mode, denomination_data, denomination_total, cheque_count, cheque_total, cheque_details
            FROM payment_analyses
            WHERE analysis_date = ? AND created_by = ?
        ");
        $stmt->bind_param('si', $date, $current_user_id);
    } else {
        // Super admin can see all analyses, but we'll load the most recent one
        $stmt = $conn->prepare("
            SELECT payment_mode, denomination_data, denomination_total, cheque_count, cheque_total, cheque_details, created_by
            FROM payment_analyses
            WHERE analysis_date = ?
            ORDER BY created_at DESC
            LIMIT 2
        ");
        $stmt->bind_param('s', $date);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        if ($row['payment_mode'] === 'cash' && $row['denomination_data']) {
            $entry = json_decode($row['denomination_data'], true) ?: [];
            $entry_total = floatval($row['denomination_total']);
        } elseif ($row['payment_mode'] === 'cheque') {
            $cheque_entry = [
                'count' => intval($row['cheque_count']),
                'total' => floatval($row['cheque_total']),
                'details' => $row['cheque_details'] ?? ''
            ];
        }
    }
    
    $stmt->close();
} catch (Throwable $e) {
    // Silently fail, use empty defaults
}

// Calculate grand total from database (Cash + Cheque from actual payments)
$grand_total = $total_cash + $total_cheque;

// Start output buffering for page content
ob_start();
?>
<main class="container py-4 animate__animated animate__fadeIn">
    <div class="row mb-3 justify-content-center">
        <div class="col-12 col-md-10 col-lg-8">
            <?php render_report_filter_bar($date, 'btn-warning'); ?>
        </div>
    </div>
    <div class="row justify-content-center">
        <div class="col-12 col-md-10 col-lg-8">
            <?php if ($filter_by_user): ?>
            <div class="alert alert-info mb-3">
                <i class="fas fa-info-circle mr-2"></i><strong>Personal View:</strong> Showing only payments recorded by you.
            </div>
            <?php endif; ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light d-flex align-items-center justify-content-between">
                    <div><i class="fas fa-chart-bar text-primary mr-2"></i>Payment Statistics<?php if ($filter_by_user) echo ' <span class="badge badge-info">' . $view_label . '</span>'; ?></div>
                    <button class="btn btn-success btn-sm" data-toggle="modal" data-target="#denomModal"><i class="fas fa-plus-circle mr-1"></i>Add Payment Analysis</button>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="row">
                            <div class="col-md-6">
                                <span class="h5">Cash Received: <span class="text-success font-weight-bold">₵<?= number_format($total_cash,2) ?></span></span>
                            </div>
                            <div class="col-md-6 text-right">
                                <span class="h5">Grand Total: <span class="text-primary font-weight-bold">₵<?= number_format($grand_total,2) ?></span></span>
                                <br><small class="text-muted">All payment methods combined</small>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Method Summary -->
                    <div class="mb-4">
                        <h6 class="font-weight-bold mb-3">Payment Method Summary</h6>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="card border-left-primary shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Cash</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800">₵<?= number_format($total_cash, 2) ?></div>
                                                <?php if (!empty($entry)): ?>
                                                    <div class="text-xs text-muted mt-1">Analysis: Completed</div>
                                                <?php else: ?>
                                                    <div class="text-xs text-muted mt-1">Analysis: Pending</div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-money-bill-wave fa-2x text-primary"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card border-left-success shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Cheque</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800">₵<?= number_format($total_cheque, 2) ?></div>
                                                <?php if (!empty($cheque_entry['total'])): ?>
                                                    <div class="text-xs text-muted mt-1">Analysis: Completed</div>
                                                <?php else: ?>
                                                    <div class="text-xs text-muted mt-1">Analysis: Pending</div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-money-check fa-2x text-success"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Grand Total Calculation Display -->
                        <div class="mt-3 p-3 bg-light rounded">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <small class="text-muted">
                                        <strong>Grand Total Calculation:</strong><br>
                                        Cash (₵<?= number_format($total_cash, 2) ?>) +
                                        Cheque (₵<?= number_format($total_cheque, 2) ?>) =
                                    </small>
                                </div>
                                <div class="col-md-4 text-right">
                                    <span class="h4 text-primary font-weight-bold">₵<?= number_format($grand_total, 2) ?></span>
                                    <?php
                                    $analysis_complete = !empty($entry) && !empty($cheque_entry['total']);
                                    if ($analysis_complete): ?>
                                        <br><small class="text-success"><i class="fas fa-check-circle"></i> All methods analyzed</small>
                                    <?php else: ?>
                                        <br><small class="text-warning"><i class="fas fa-exclamation-triangle"></i> Analysis incomplete</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
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
                                    if ($filter_by_user) {
                                        // Non-admin: Show only payments recorded by this user
                                        $sql = "SELECT pt.name AS payment_type, SUM(p.amount) AS total_amount, COUNT(p.id) AS count
                                                FROM payments p
                                                JOIN payment_types pt ON p.payment_type_id = pt.id
                                                WHERE DATE(p.payment_date) = ? AND p.recorded_by = ?
                                                GROUP BY pt.id
                                                ORDER BY total_amount DESC";
                                        $stmt = $conn->prepare($sql);
                                        $stmt->bind_param('si', $date, $current_user_id);
                                    } else {
                                        // Super admin: Show all payments
                                        $sql = "SELECT pt.name AS payment_type, SUM(p.amount) AS total_amount, COUNT(p.id) AS count
                                                FROM payments p
                                                JOIN payment_types pt ON p.payment_type_id = pt.id
                                                WHERE DATE(p.payment_date) = ?
                                                GROUP BY pt.id
                                                ORDER BY total_amount DESC";
                                        $stmt = $conn->prepare($sql);
                                        $stmt->bind_param('s', $date);
                                    }
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
                                    <td class="text-right">Grand Total (Cash + Cheque)</td>
                                    <td><?= array_sum(array_column($types,'count')) ?></td>
                                    <td>₵<?= number_format($grand_total,2) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
<?php 
$page_content = ob_get_clean();
ob_start(); 
?>
               <!-- Denomination Modal -->
               <div class="modal fade" id="denomModal" tabindex="-1" role="dialog" aria-labelledby="denomModalLabel" aria-hidden="true">
                 <div class="modal-dialog modal-lg" role="document">
                   <div class="modal-content">
                     <div class="modal-header py-2">
   <h6 class="modal-title w-100 text-center" id="denomModalLabel">Payment Analysis</h6>
   <button type="button" class="close" data-dismiss="modal" aria-label="Close">
     <span aria-hidden="true">&times;</span>
   </button>
</div>
                     <div class="modal-body">
                       <form method="post" id="denomForm" autocomplete="off">
                         <!-- Payment Method Selection -->
                         <div class="form-group mb-3">
                           <label class="font-weight-bold">Payment Method</label>
                           <div class="row">
                             <?php foreach ($payment_modes as $mode): ?>
                             <div class="col-auto">
                               <div class="custom-control custom-radio">
                                 <input type="radio" id="mode_<?= $mode ?>" name="payment_mode" value="<?= $mode ?>" class="custom-control-input" <?= ($mode === 'cash' ? 'checked' : '') ?>>
                                 <label class="custom-control-label" for="mode_<?= $mode ?>"><?= ucfirst($mode) ?></label>
                               </div>
                             </div>
                             <?php endforeach; ?>
                           </div>
                         </div>

                         <!-- Cash Denomination Analysis -->
                         <div id="cashAnalysis" class="payment-method-content">
                           <div class="alert alert-info">
                             <i class="fas fa-info-circle mr-2"></i>Enter the breakdown of cash denominations received.
                           </div>
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
                         </div>

                         <!-- Cheque Analysis -->
                         <div id="chequeAnalysis" class="payment-method-content" style="display: none;">
                           <div class="alert alert-info">
                             <i class="fas fa-money-check mr-2"></i>Enter cheque payment details and amounts.
                           </div>
                           <div class="row">
                             <div class="col-md-6">
                               <div class="form-group">
                                 <label for="cheque_count">Number of Cheques</label>
                                 <input type="number" min="0" class="form-control" id="cheque_count" name="cheque_count" value="<?= htmlspecialchars($cheque_entry['count']) ?>">
                               </div>
                             </div>
                             <div class="col-md-6">
                               <div class="form-group">
                                 <label for="cheque_total">Total Cheque Amount (₵)</label>
                                 <input type="number" min="0" step="0.01" class="form-control" id="cheque_total" name="cheque_total" value="<?= htmlspecialchars($cheque_entry['total']) ?>">
                               </div>
                             </div>
                           </div>
                           <div class="form-group">
                             <label for="cheque_details">Cheque Details (Optional)</label>
                             <textarea class="form-control" id="cheque_details" name="cheque_details" rows="2" placeholder="Bank names, cheque numbers, etc."><?= htmlspecialchars($cheque_entry['details']) ?></textarea>
                           </div>
                         </div>

                         <div class="row align-items-center" id="totalRow">
                           <div class="col-6 text-right font-weight-bold">Total</div>
                           <div class="col-4"></div>
                           <div class="col-2 text-right font-weight-bold">₵<span id="denom-total">0.00</span></div>
                         </div>
                         <div class="d-flex justify-content-end mt-2">
                           <button type="button" id="saveAnalysisBtn" class="btn btn-primary btn-sm px-4">
                               <i class="fas fa-save mr-1"></i>Save Analysis
                           </button>
                         </div>
                         <div id="analysisMessage" class="mt-2"></div>
                       </form>
                     </div>
                   </div>
                 </div>
               </div>
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
    // Payment method selection handler
    document.querySelectorAll('input[name="payment_mode"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            const selectedMode = this.value;
            const cashAnalysis = document.getElementById('cashAnalysis');
            const chequeAnalysis = document.getElementById('chequeAnalysis');
            const totalRow = document.getElementById('totalRow');
            const modalTitle = document.getElementById('denomModalLabel');

            // Hide all analysis sections
            cashAnalysis.style.display = 'none';
            chequeAnalysis.style.display = 'none';

            if (selectedMode === 'cash') {
                cashAnalysis.style.display = 'block';
                totalRow.style.display = 'flex';
                modalTitle.textContent = 'Cash Denomination Analysis';
                // Re-initialize denomination calculations
                initializeDenomInputs();
            } else if (selectedMode === 'cheque') {
                chequeAnalysis.style.display = 'block';
                totalRow.style.display = 'none';
                modalTitle.textContent = 'Cheque Payment Analysis';
            }
        });
    });

    // Initialize denomination inputs
    function initializeDenomInputs() {
        document.querySelectorAll('.denom-qty').forEach(function(inp) {
            inp.addEventListener('input', updateDenomTotals);
        });
    }

    // Always initialize modal total to red on open
    $('#denomModal').on('shown.bs.modal', function() {
        const selectedMode = document.querySelector('input[name="payment_mode"]:checked').value;
        const modalTitle = document.getElementById('denomModalLabel');
        
        if (selectedMode === 'cash') {
            modalTitle.textContent = 'Cash Denomination Analysis';
            initializeDenomInputs();
            // Set total cell to red by default (0)
            let totalCell = document.getElementById('denom-total');
            if (totalCell) {
                totalCell.textContent = '0.00';
                totalCell.parentElement.classList.remove('bg-danger','bg-warning','bg-success','text-white','text-dark');
                totalCell.parentElement.classList.add('bg-danger','text-white');
            }
            updateDenomTotals();
        } else if (selectedMode === 'cheque') {
            modalTitle.textContent = 'Cheque Payment Analysis';
        }
    });
    
    // Save Analysis Button Handler
    $('#saveAnalysisBtn').on('click', function() {
        const btn = $(this);
        const originalHtml = btn.html();
        const selectedMode = $('input[name="payment_mode"]:checked').val();
        const currentDate = '<?= $date ?>';
        
        // Disable button and show loading
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Saving...');
        $('#analysisMessage').html('');
        
        // Prepare form data
        const formData = new FormData();
        formData.append('action', 'save');
        formData.append('date', currentDate);
        formData.append('payment_mode', selectedMode);
        
        if (selectedMode === 'cash') {
            // Collect denomination data
            $('.denom-qty').each(function() {
                const name = $(this).attr('name');
                const value = $(this).val() || 0;
                formData.append(name, value);
            });
        } else if (selectedMode === 'cheque') {
            formData.append('cheque_count', $('#cheque_count').val() || 0);
            formData.append('cheque_total', $('#cheque_total').val() || 0);
            formData.append('cheque_details', $('#cheque_details').val() || '');
        }
        
        // Submit via AJAX
        $.ajax({
            url: 'ajax_payment_analysis.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#analysisMessage').html(
                        '<div class="alert alert-success alert-dismissible fade show py-2 px-3 small">' +
                        '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                        '<i class="fas fa-check-circle mr-2"></i>' + response.message +
                        '</div>'
                    );
                    
                    // Close modal after 2 seconds
                    setTimeout(function() {
                        $('#denomModal').modal('hide');
                        // Reload page to show updated analysis
                        location.reload();
                    }, 2000);
                } else {
                    $('#analysisMessage').html(
                        '<div class="alert alert-danger alert-dismissible fade show py-2 px-3 small">' +
                        '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                        '<i class="fas fa-exclamation-triangle mr-2"></i>' + (response.error || 'Failed to save analysis') +
                        '</div>'
                    );
                }
            },
            error: function(xhr) {
                let errorMsg = 'An error occurred while saving';
                try {
                    const response = JSON.parse(xhr.responseText);
                    errorMsg = response.error || errorMsg;
                } catch(e) {}
                
                $('#analysisMessage').html(
                    '<div class="alert alert-danger alert-dismissible fade show py-2 px-3 small">' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '<i class="fas fa-exclamation-triangle mr-2"></i>' + errorMsg +
                    '</div>'
                );
            },
            complete: function() {
                // Re-enable button
                btn.prop('disabled', false).html(originalHtml);
            }
        });
    });
});
</script>
<?php
$modal_html = ob_get_clean();
include __DIR__.'/../includes/layout.php';
?>