<?php
// Error reporting for development (remove or comment out in production)

if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config/config.php';
require_once '../helpers/auth.php';
require_once '../helpers/permissions_v2.php';
require_once '../helpers/csrf.php';
require_once '../helpers/payment_report_context.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = is_super_admin();
$current_user_id = (int) ($_SESSION['user_id'] ?? 0);
$cashCheckerChurchId = 0;
$is_cash_checker = false;
if (!$is_super_admin && $current_user_id > 0) {
    $checkerStmt = $conn->prepare(
        "SELECT user_account.church_id,
                EXISTS (
                    SELECT 1
                      FROM member_roles_of_serving member_role
                      JOIN roles_of_serving serving_role ON serving_role.id = member_role.role_id
                     WHERE member_role.member_id = user_account.member_id
                       AND UPPER(TRIM(serving_role.name)) IN ('CASH CHECKER', 'CASH CHECKERS')
                ) AS is_cash_checker
           FROM users user_account
          WHERE user_account.id = ? AND user_account.status = 'active'
          LIMIT 1"
    );
    $checkerStmt->bind_param('i', $current_user_id);
    $checkerStmt->execute();
    $checkerAccount = $checkerStmt->get_result()->fetch_assoc() ?: [];
    $checkerStmt->close();
    $cashCheckerChurchId = (int) ($checkerAccount['church_id'] ?? 0);
    $is_cash_checker = !empty($checkerAccount['is_cash_checker']) && $cashCheckerChurchId > 0;
}
$can_review_cash_analyses = $is_super_admin
    || $is_cash_checker
    || has_permission('view_cash_analysis_review');
$can_manage_analysis = $is_super_admin || has_permission('payment_statistics');

if (!$is_super_admin && !has_permission('payment_statistics') && !$can_review_cash_analyses) {
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

// Super admin sees all data, others see only their own
$filter_by_user = !$is_super_admin;

// --- CONFIG ---
$legacyDate = payment_report_valid_date((string) ($_GET['date'] ?? ''));
$defaultDate = $legacyDate !== '' ? $legacyDate : date('Y-m-d');
$start_date = payment_report_valid_date((string) ($_GET['start_date'] ?? '')) ?: $defaultDate;
$end_date = payment_report_valid_date((string) ($_GET['end_date'] ?? '')) ?: $defaultDate;
if ($start_date > $end_date) {
    [$start_date, $end_date] = [$end_date, $start_date];
}
$date = $end_date;
$date_range_label = payment_report_period_label($start_date, $end_date);
$payment_modes = ['cash', 'cheque'];
$payment_method_summary = [];

function normalize_payment_mode_key(string $mode): string
{
    $key = strtolower(trim($mode));
    $key = str_replace([' ', '-'], '_', $key);
    if ($key === '') {
        return 'unknown';
    }
    if (in_array($key, ['check', 'cheques'], true)) {
        return 'cheque';
    }
    if (in_array($key, ['mobilemoney', 'mobile_money', 'momo'], true)) {
        return 'mobile_money';
    }
    if (in_array($key, ['banktransfer', 'bank_transfer', 'transfer'], true)) {
        return 'transfer';
    }
    return $key;
}

function payment_mode_label(string $mode): string
{
    $map = [
        'cash' => 'Cash',
        'cheque' => 'Cheque',
        'mobile_money' => 'Mobile Money',
        'transfer' => 'Transfer',
        'pos' => 'POS',
        'online' => 'Online',
        'offline' => 'Offline',
        'paystack' => 'Paystack',
        'card' => 'Card',
        'other' => 'Other',
        'unknown' => 'Unknown',
    ];
    return $map[$mode] ?? ucwords(str_replace('_', ' ', $mode));
}

function payment_mode_icon(string $mode): string
{
    $map = [
        'cash' => 'money-bill-wave',
        'cheque' => 'money-check',
        'mobile_money' => 'mobile-alt',
        'transfer' => 'exchange-alt',
        'pos' => 'cash-register',
        'online' => 'globe',
        'offline' => 'wallet',
        'paystack' => 'credit-card',
        'card' => 'credit-card',
        'other' => 'coins',
        'unknown' => 'question-circle',
    ];
    return $map[$mode] ?? 'wallet';
}
$denominations = [
    ['label' => '200 Note', 'value' => 200, 'type' => 'note'],
    ['label' => '100 Note', 'value' => 100, 'type' => 'note'],
    ['label' => '50 Note',  'value' => 50, 'type' => 'note'],
    ['label' => '20 Note',  'value' => 20, 'type' => 'note'],
    ['label' => '10 Note',  'value' => 10, 'type' => 'note'],
    ['label' => '5 Note',   'value' => 5, 'type' => 'note'],
    ['label' => '2 Note',   'value' => 2, 'type' => 'note'],
    ['label' => '1 Note',   'value' => 1, 'type' => 'note'],
    ['label' => '2 Coin',   'value' => 2, 'type' => 'coin'],
    ['label' => '1 Coin',   'value' => 1, 'type' => 'coin'],
    ['label' => '0.50p',    'value' => 0.5, 'type' => 'coin'],
    ['label' => '0.20p',    'value' => 0.2, 'type' => 'coin'],
    ['label' => '0.10p',    'value' => 0.1, 'type' => 'coin'],
];
// --- FETCH PAYMENT TOTALS FROM DATABASE ---
$total_cash = 0.00;
$total_cheque = 0.00;
$total_all_methods = 0.00;
$analysis_cash_total = 0.00;

try {
    if ($filter_by_user) {
        // Non-admin: Show only payments recorded by this user
        // Fetch cash total
        $stmt = $conn->prepare("SELECT SUM(amount) as total FROM v_posted_payments WHERE payment_date >= ? AND payment_date < DATE_ADD(?, INTERVAL 1 DAY) AND LOWER(TRIM(mode))='cash' AND recorded_by=?");
        $stmt->bind_param('ssi', $start_date, $end_date, $current_user_id);
        $stmt->execute();
        $stmt->bind_result($total_cash);
        $stmt->fetch();
        $stmt->close();
        $total_cash = floatval($total_cash);
        
        // Fetch cheque total
        $stmt = $conn->prepare("SELECT SUM(amount) as total FROM v_posted_payments WHERE payment_date >= ? AND payment_date < DATE_ADD(?, INTERVAL 1 DAY) AND LOWER(TRIM(mode)) IN ('cheque','check') AND recorded_by=?");
        $stmt->bind_param('ssi', $start_date, $end_date, $current_user_id);
        $stmt->execute();
        $stmt->bind_result($total_cheque);
        $stmt->fetch();
        $stmt->close();
        $total_cheque = floatval($total_cheque);

        // Total received for all payment modes (cashier scope)
        $stmt = $conn->prepare("SELECT SUM(amount) as total FROM v_posted_payments WHERE payment_date >= ? AND payment_date < DATE_ADD(?, INTERVAL 1 DAY) AND recorded_by=?");
        $stmt->bind_param('ssi', $start_date, $end_date, $current_user_id);
        $stmt->execute();
        $stmt->bind_result($total_all_methods);
        $stmt->fetch();
        $stmt->close();
        $total_all_methods = floatval($total_all_methods);
    } else {
        // Super admin: Show all payments
        // Fetch cash total
        $stmt = $conn->prepare("SELECT SUM(amount) as total FROM v_posted_payments WHERE payment_date >= ? AND payment_date < DATE_ADD(?, INTERVAL 1 DAY) AND LOWER(TRIM(mode))='cash'");
        $stmt->bind_param('ss', $start_date, $end_date);
        $stmt->execute();
        $stmt->bind_result($total_cash);
        $stmt->fetch();
        $stmt->close();
        $total_cash = floatval($total_cash);
        
        // Fetch cheque total
        $stmt = $conn->prepare("SELECT SUM(amount) as total FROM v_posted_payments WHERE payment_date >= ? AND payment_date < DATE_ADD(?, INTERVAL 1 DAY) AND LOWER(TRIM(mode)) IN ('cheque','check')");
        $stmt->bind_param('ss', $start_date, $end_date);
        $stmt->execute();
        $stmt->bind_result($total_cheque);
        $stmt->fetch();
        $stmt->close();
        $total_cheque = floatval($total_cheque);

        // Total received for all payment modes (admin scope)
        $stmt = $conn->prepare("SELECT SUM(amount) as total FROM v_posted_payments WHERE payment_date >= ? AND payment_date < DATE_ADD(?, INTERVAL 1 DAY)");
        $stmt->bind_param('ss', $start_date, $end_date);
        $stmt->execute();
        $stmt->bind_result($total_all_methods);
        $stmt->fetch();
        $stmt->close();
        $total_all_methods = floatval($total_all_methods);
    }

    // Dynamic payment mode summary (cashier scope for non-admin, all scope for admin)
    if ($filter_by_user) {
        $stmt = $conn->prepare("SELECT COALESCE(mode,'') AS mode, COUNT(id) AS cnt, COALESCE(SUM(amount),0) AS total FROM v_posted_payments WHERE payment_date >= ? AND payment_date < DATE_ADD(?, INTERVAL 1 DAY) AND recorded_by=? GROUP BY mode ORDER BY total DESC");
        $stmt->bind_param('ssi', $start_date, $end_date, $current_user_id);
    } else {
        $stmt = $conn->prepare("SELECT COALESCE(mode,'') AS mode, COUNT(id) AS cnt, COALESCE(SUM(amount),0) AS total FROM v_posted_payments WHERE payment_date >= ? AND payment_date < DATE_ADD(?, INTERVAL 1 DAY) GROUP BY mode ORDER BY total DESC");
        $stmt->bind_param('ss', $start_date, $end_date);
    }
    $stmt->execute();
    $mode_result = $stmt->get_result();
    while ($mode_row = $mode_result->fetch_assoc()) {
        $mode_key = normalize_payment_mode_key((string) ($mode_row['mode'] ?? ''));
        if (!isset($payment_method_summary[$mode_key])) {
            $payment_method_summary[$mode_key] = ['count' => 0, 'total' => 0.0];
        }
        $payment_method_summary[$mode_key]['count'] += (int) $mode_row['cnt'];
        $payment_method_summary[$mode_key]['total'] += (float) $mode_row['total'];
    }
    $stmt->close();

    // The denomination editor is for one day, so compare it with the end date only.
    if ($filter_by_user) {
        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM v_posted_payments WHERE payment_date >= ? AND payment_date < DATE_ADD(?, INTERVAL 1 DAY) AND LOWER(TRIM(mode))='cash' AND recorded_by=?");
        $stmt->bind_param('ssi', $date, $date, $current_user_id);
    } else {
        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM v_posted_payments WHERE payment_date >= ? AND payment_date < DATE_ADD(?, INTERVAL 1 DAY) AND LOWER(TRIM(mode))='cash'");
        $stmt->bind_param('ss', $date, $date);
    }
    $stmt->execute();
    $stmt->bind_result($analysis_cash_total);
    $stmt->fetch();
    $stmt->close();
} catch (Throwable $e) {
    $total_cash = 0.00;
    $total_cheque = 0.00;
    $total_all_methods = 0.00;
    $payment_method_summary = [];
}
// --- LOAD EXISTING ANALYSES FROM DATABASE ---
$entry = [];
$entry_total = 0;
$entry_notes_total = 0;
$entry_coins_total = 0;
$cheque_entry = ['count' => 0, 'total' => 0.00, 'details' => ''];
$analysis_history = [];
$analysis_cashiers = [];
$analysis_cashier_filter = $can_review_cash_analyses ? max(0, (int) ($_GET['cashier_id'] ?? 0)) : 0;

try {
    // The edit modal must load only the signed-in cashier's own record. An
    // administrator must never accidentally overwrite another cashier's entry.
    $stmt = $conn->prepare("
        SELECT payment_mode, denomination_data, denomination_total, notes_total,
               coins_total, cheque_count, cheque_total, cheque_details
        FROM payment_analyses
        WHERE analysis_date = ? AND created_by = ?
    ");
    $stmt->bind_param('si', $date, $current_user_id);
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        if ($row['payment_mode'] === 'cash' && $row['denomination_data']) {
            $entry = json_decode($row['denomination_data'], true) ?: [];
            $entry_total = floatval($row['denomination_total']);
            $entry_notes_total = floatval($row['notes_total']);
            $entry_coins_total = floatval($row['coins_total']);
        } elseif ($row['payment_mode'] === 'cheque') {
            $cheque_entry = [
                'count' => intval($row['cheque_count']),
                'total' => floatval($row['cheque_total']),
                'details' => $row['cheque_details'] ?? ''
            ];
        }
    }
    
    $stmt->close();

    if ($can_review_cash_analyses && !$is_super_admin) {
        $cashierStmt = $conn->prepare(
            'SELECT DISTINCT cashier_id, cashier_name FROM v_cashier_denomination_history'
            . " WHERE analysis_date BETWEEN ? AND ? AND church_id = ? AND payment_mode = 'cash'"
            . ' ORDER BY cashier_name'
        );
        $cashierStmt->bind_param('ssi', $start_date, $end_date, $cashCheckerChurchId);
        $cashierStmt->execute();
        $analysis_cashiers = $cashierStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $cashierStmt->close();
    } elseif ($is_super_admin) {
        $cashierStmt = $conn->prepare(
            'SELECT DISTINCT cashier_id, cashier_name FROM v_cashier_denomination_history'
            . ' WHERE analysis_date BETWEEN ? AND ? ORDER BY cashier_name'
        );
        $cashierStmt->bind_param('ss', $start_date, $end_date);
        $cashierStmt->execute();
        $analysis_cashiers = $cashierStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $cashierStmt->close();
    }

    $historySql = "SELECT history.*,
                          COALESCE((
                              SELECT SUM(payment.amount)
                                FROM v_posted_payments payment
                               WHERE payment.church_id = history.church_id
                                 AND payment.recorded_by = history.cashier_id
                                 AND payment.payment_date >= history.analysis_date
                                 AND payment.payment_date < DATE_ADD(history.analysis_date, INTERVAL 1 DAY)
                                 AND LOWER(TRIM(payment.mode)) = 'cash'
                          ), 0) AS posted_cash_total
                     FROM v_cashier_denomination_history history
                    WHERE history.analysis_date BETWEEN ? AND ?";
    if ($can_review_cash_analyses) {
        if (!$is_super_admin) $historySql .= ' AND history.church_id = ?';
        if (!$is_super_admin) $historySql .= " AND history.payment_mode = 'cash'";
        if ($analysis_cashier_filter > 0) $historySql .= ' AND history.cashier_id = ?';
        $historySql .= ' ORDER BY history.analysis_date DESC, history.cashier_name, history.payment_mode';
        $stmt = $conn->prepare($historySql);
        if (!$is_super_admin && $analysis_cashier_filter > 0) {
            $stmt->bind_param('ssii', $start_date, $end_date, $cashCheckerChurchId, $analysis_cashier_filter);
        } elseif (!$is_super_admin) {
            $stmt->bind_param('ssi', $start_date, $end_date, $cashCheckerChurchId);
        } elseif ($analysis_cashier_filter > 0) {
            $stmt->bind_param('ssi', $start_date, $end_date, $analysis_cashier_filter);
        } else {
            $stmt->bind_param('ss', $start_date, $end_date);
        }
    } elseif ($filter_by_user) {
        $historySql .= ' AND history.cashier_id = ? ORDER BY history.analysis_date DESC, history.payment_mode';
        $stmt = $conn->prepare($historySql);
        $stmt->bind_param('ssi', $start_date, $end_date, $current_user_id);
    } else {
        $historySql .= ' ORDER BY history.analysis_date DESC, history.cashier_name, history.payment_mode';
        $stmt = $conn->prepare($historySql);
        $stmt->bind_param('ss', $start_date, $end_date);
    }
    $stmt->execute();
    $analysis_history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Throwable $e) {
    // Silently fail, use empty defaults
}

// Calculate grand total from database (all payment methods)
$grand_total = $total_all_methods;
$cashAnalysisSummary = ['submissions' => 0, 'matched' => 0, 'short' => 0, 'over' => 0, 'variance' => 0.0];
foreach ($analysis_history as $analysisRow) {
    if ($analysisRow['payment_mode'] !== 'cash') continue;
    $declaredTotal = (float) $analysisRow['denomination_total'];
    $postedTotal = (float) ($analysisRow['posted_cash_total'] ?? 0);
    $variance = $declaredTotal - $postedTotal;
    $cashAnalysisSummary['submissions']++;
    $cashAnalysisSummary['variance'] += $variance;
    if (abs($variance) < 0.01) $cashAnalysisSummary['matched']++;
    elseif ($variance < 0) $cashAnalysisSummary['short']++;
    else $cashAnalysisSummary['over']++;
}

$cashDenominationMatrix = [
    'cashiers' => [],
    'rows' => [],
    'total_quantity' => 0,
    'total_amount' => 0.0,
];
foreach ($denominations as $denomination) {
    $cashDenominationMatrix['rows'][$denomination['label']] = [
        'value' => (float) $denomination['value'],
        'total_quantity' => 0,
        'amount' => 0.0,
    ];
}
foreach ($analysis_history as $analysisRow) {
    if ($analysisRow['payment_mode'] !== 'cash') continue;
    $cashierId = (int) $analysisRow['cashier_id'];
    if (!isset($cashDenominationMatrix['cashiers'][$cashierId])) {
        $cashDenominationMatrix['cashiers'][$cashierId] = [
            'name' => (string) $analysisRow['cashier_name'],
            'counts' => array_fill_keys(array_keys($cashDenominationMatrix['rows']), 0),
            'quantity' => 0,
            'amount' => 0.0,
        ];
    }
    $counts = json_decode((string) ($analysisRow['denomination_data'] ?? ''), true);
    $counts = is_array($counts) ? $counts : [];
    foreach ($denominations as $denomination) {
        $label = $denomination['label'];
        $quantity = max(0, (int) ($counts[$label] ?? 0));
        $cashDenominationMatrix['cashiers'][$cashierId]['counts'][$label] += $quantity;
        $cashDenominationMatrix['cashiers'][$cashierId]['quantity'] += $quantity;
        $cashDenominationMatrix['cashiers'][$cashierId]['amount'] += $quantity * (float) $denomination['value'];
        $cashDenominationMatrix['rows'][$label]['total_quantity'] += $quantity;
    }
}
uasort($cashDenominationMatrix['cashiers'], static function ($first, $second) {
    return strcasecmp($first['name'], $second['name']);
});
foreach ($cashDenominationMatrix['rows'] as &$matrixRow) {
    $matrixRow['amount'] = $matrixRow['total_quantity'] * $matrixRow['value'];
    $cashDenominationMatrix['total_quantity'] += $matrixRow['total_quantity'];
    $cashDenominationMatrix['total_amount'] += $matrixRow['amount'];
}
unset($matrixRow);

// Start output buffering for page content
ob_start();
?>
<style>
.payment-statistics-page {
    --stats-ink: #1d2b37;
    --stats-muted: #687987;
    --stats-line: #dce4e8;
    --stats-soft: #f3f6f7;
    --stats-green: #176b50;
    --stats-green-soft: #e7f2ed;
    --stats-amber: #a45b0a;
    --stats-red: #b33a3a;
    color: var(--stats-ink);
    margin: 0 auto;
    max-width: 1540px;
    padding-left: 24px;
    padding-right: 24px;
}
.stats-header { align-items: flex-end; border-bottom: 1px solid var(--stats-line); display: flex; gap: 24px; justify-content: space-between; margin-bottom: 22px; padding: 8px 0 20px; }
.stats-eyebrow { color: var(--stats-green); font-size: .75rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
.stats-header h1 { font-size: 1.8rem; font-weight: 750; margin: 4px 0 5px; }
.stats-header p { color: var(--stats-muted); font-size: .95rem; margin: 0; }
.stats-header-actions { align-items: flex-end; display: flex; flex-direction: column; gap: 10px; }
.stats-period { color: var(--stats-muted); font-size: .82rem; text-align: right; }
.stats-period strong { color: var(--stats-ink); display: block; font-size: 1rem; }
.stats-toolbar { align-items: flex-end; background: #fff; border: 1px solid var(--stats-line); border-radius: 8px; display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 18px; padding: 13px 15px; }
.stats-toolbar .form-group { margin: 0; }
.stats-toolbar label { color: #485a66; display: block; font-size: .76rem; font-weight: 700; margin-bottom: 4px; }
.stats-toolbar .form-control { min-height: 38px; }
.stats-personal-note { background: #edf5f2; border-left: 3px solid var(--stats-green); color: #365b4b; font-size: .88rem; margin-bottom: 18px; padding: 10px 13px; }
.stats-metrics { border-bottom: 1px solid var(--stats-line); border-top: 1px solid var(--stats-line); display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); margin-bottom: 24px; }
.stats-metric { min-width: 0; padding: 16px 20px; }
.stats-metric + .stats-metric { border-left: 1px solid var(--stats-line); }
.stats-metric-label { color: var(--stats-muted); display: block; font-size: .76rem; font-weight: 750; letter-spacing: .04em; text-transform: uppercase; }
.stats-metric-value { color: var(--stats-ink); display: block; font-size: 1.65rem; font-weight: 800; line-height: 1.25; margin-top: 4px; }
.stats-metric--cash .stats-metric-value { color: var(--stats-green); }
.stats-metric--cheque .stats-metric-value { color: #315d83; }
.stats-metric-sub { color: var(--stats-muted); display: block; font-size: .78rem; margin-top: 3px; }
.stats-breakdown-grid { display: grid; gap: 26px; grid-template-columns: minmax(0, .9fr) minmax(0, 1.1fr); margin-bottom: 32px; }
.stats-section { min-width: 0; }
.stats-section-heading { align-items: flex-end; border-bottom: 1px solid var(--stats-line); display: flex; justify-content: space-between; margin-bottom: 8px; padding-bottom: 10px; }
.stats-section-heading h2 { font-size: 1.08rem; font-weight: 750; margin: 0; }
.stats-section-heading p { color: var(--stats-muted); font-size: .8rem; margin: 3px 0 0; }
.stats-method-list { list-style: none; margin: 0; padding: 0; }
.stats-method-row { align-items: center; border-bottom: 1px solid #e8edef; display: grid; gap: 12px; grid-template-columns: minmax(0, 1fr) auto; padding: 12px 4px; }
.stats-method-name { align-items: center; display: flex; gap: 10px; min-width: 0; }
.stats-method-icon { align-items: center; background: var(--stats-soft); border-radius: 7px; color: var(--stats-green); display: inline-flex; flex: 0 0 34px; height: 34px; justify-content: center; }
.stats-method-name strong { display: block; font-size: .92rem; }
.stats-method-name small { color: var(--stats-muted); display: block; font-size: .76rem; margin-top: 2px; }
.stats-method-amount { font-size: 1rem; font-weight: 750; text-align: right; white-space: nowrap; }
.stats-method-state { color: var(--stats-muted); display: block; font-size: .72rem; font-weight: 500; margin-top: 2px; }
.stats-table-wrap { overflow-x: auto; }
.stats-table { margin-bottom: 0; min-width: 480px; }
.stats-table thead th { background: var(--stats-soft); border-bottom: 1px solid var(--stats-line); border-top: 0; color: #52636f; font-size: .73rem; font-weight: 750; letter-spacing: .04em; text-transform: uppercase; }
.stats-table td, .stats-table th { padding: 10px 12px; vertical-align: middle; }
.stats-table td { border-color: #e8edef; font-size: .88rem; }
.stats-table tfoot td { background: #f8faf9; font-weight: 750; }
.stats-empty { color: var(--stats-muted); font-size: .9rem; padding: 22px 12px; text-align: center; }
.stats-review { border-top: 3px solid var(--stats-green); margin-top: 12px; padding-top: 18px; }
.stats-review-title { align-items: flex-start; display: flex; gap: 18px; justify-content: space-between; margin-bottom: 14px; }
.stats-review-title h2 { font-size: 1.25rem; font-weight: 800; margin: 0; }
.stats-review-title p { color: var(--stats-muted); font-size: .86rem; margin: 4px 0 0; max-width: 760px; }
.stats-review-tools { align-items: flex-end; display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 14px; }
.stats-review-tools label { color: #485a66; display: block; font-size: .75rem; font-weight: 700; margin-bottom: 4px; }
.stats-review-kpis { border-bottom: 1px solid var(--stats-line); border-top: 1px solid var(--stats-line); display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); margin-bottom: 14px; }
.stats-review-kpi { padding: 10px 14px; }
.stats-review-kpi + .stats-review-kpi { border-left: 1px solid var(--stats-line); }
.stats-review-kpi span { color: var(--stats-muted); display: block; font-size: .74rem; }
.stats-review-kpi strong { display: block; font-size: 1.16rem; margin-top: 2px; }
.stats-review-table { min-width: 1100px; }
.stats-review-table thead th { white-space: nowrap; }
.stats-denomination-matrix { margin-bottom: 20px; }
.stats-denomination-matrix .stats-table { min-width: 680px; }
.stats-denomination-matrix .denomination-value { color: #485a66; font-weight: 700; }
.stats-denomination-matrix .cashier-count { font-variant-numeric: tabular-nums; text-align: right; }
.stats-denomination-matrix tfoot th { background: #eaf2ee; border-top: 2px solid #c9d9d1; white-space: nowrap; }
.stats-denomination-matrix .matrix-total { color: var(--stats-green); font-weight: 800; }
.stats-amount { font-variant-numeric: tabular-nums; white-space: nowrap; }
.stats-variance--short { color: var(--stats-red); font-weight: 750; }
.stats-variance--over { color: var(--stats-amber); font-weight: 750; }
.stats-count-button { font-size: .78rem; white-space: nowrap; }
.stats-count-modal .modal-content { border: 0; border-radius: 10px; overflow: hidden; }
.stats-count-modal .modal-header { background: #183c36; color: #fff; }
.stats-count-modal .close { color: #fff; opacity: .8; text-shadow: none; }
.stats-count-modal .modal-header small { display: block; margin-top: 3px; }
.stats-count-summary { display: grid; gap: 10px; grid-template-columns: repeat(3, minmax(0, 1fr)); margin-bottom: 16px; }
.stats-count-summary > div { background: #f3f6f7; border-radius: 6px; padding: 10px 12px; }
.stats-count-summary span { color: var(--stats-muted); display: block; font-size: .74rem; }
.stats-count-summary strong { display: block; font-size: 1.08rem; margin-top: 3px; }
.stats-count-table { min-width: 420px; }
.stats-count-table .amount-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
.stats-count-table tfoot th { background: #eaf2ee; color: var(--stats-green); font-weight: 800; }
.stats-modal .modal-content { border: 0; border-radius: 10px; overflow: hidden; }
.stats-modal .modal-header { background: #183c36; color: #fff; }
.stats-modal .close { color: #fff; opacity: .8; text-shadow: none; }
.stats-modal .denom-qty { max-width: 68px; }
.stats-modal #totalRow { background: var(--stats-soft); border-radius: 7px; margin: 8px 0 0; padding: 12px 4px; }
@media (max-width: 900px) {
    .stats-breakdown-grid { grid-template-columns: 1fr; }
    .stats-header { align-items: flex-start; flex-direction: column; }
    .stats-header-actions { align-items: flex-start; flex-direction: row; flex-wrap: wrap; justify-content: space-between; width: 100%; }
    .stats-period { text-align: left; }
}
@media (max-width: 600px) {
    .payment-statistics-page { padding-left: 14px; padding-right: 14px; }
    .stats-header h1 { font-size: 1.5rem; }
    .stats-toolbar { align-items: stretch; }
    .stats-toolbar .form-group, .stats-toolbar .form-control, .stats-toolbar .btn { width: 100%; }
    .stats-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .stats-metric { padding: 13px 10px; }
    .stats-metric:nth-child(3) { border-left: 0; border-top: 1px solid var(--stats-line); }
    .stats-metric:nth-child(4) { border-top: 1px solid var(--stats-line); }
    .stats-metric-value { font-size: 1.35rem; }
    .stats-review-title { flex-direction: column; }
    .stats-review-tools > div, .stats-review-tools .form-control, .stats-review-tools .btn { width: 100%; }
    .stats-review-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .stats-review-kpi:nth-child(3) { border-left: 0; border-top: 1px solid var(--stats-line); }
    .stats-review-kpi:nth-child(4) { border-top: 1px solid var(--stats-line); }
}
</style>
<main class="payment-statistics-page py-4">
    <header class="stats-header">
        <div>
            <div class="stats-eyebrow">Payments / Reporting</div>
            <h1><?= $can_review_cash_analyses ? 'Payment & Cash Review' : 'Payment Statistics' ?></h1>
            <p><?= $filter_by_user ? 'Your recorded payments and daily cashier analysis.' : 'Collections, payment methods, and cashier submissions.' ?></p>
        </div>
        <div class="stats-header-actions">
            <div class="stats-period">Selected period<strong><?= htmlspecialchars($date_range_label) ?></strong></div>
            <?php if ($can_manage_analysis): ?>
            <button type="button" class="btn btn-success" data-toggle="modal" data-target="#denomModal"><i class="fas fa-plus mr-1"></i>Add daily analysis</button>
            <?php endif; ?>
        </div>
    </header>

    <form method="get" class="stats-toolbar" aria-label="Filter payment statistics by date range">
        <?php if ($can_review_cash_analyses && $analysis_cashier_filter > 0): ?>
        <input type="hidden" name="cashier_id" value="<?= $analysis_cashier_filter ?>">
        <?php endif; ?>
        <div class="form-group">
            <label for="start_date">From</label>
            <input type="date" class="form-control" id="start_date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" required>
        </div>
        <div class="form-group">
            <label for="end_date">To</label>
            <input type="date" class="form-control" id="end_date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" required>
        </div>
        <button type="submit" class="btn btn-dark"><i class="fas fa-filter mr-1"></i>Apply range</button>
        <span class="small text-muted">Daily analysis entry uses <?= htmlspecialchars(date('j M Y', strtotime($date))) ?>.</span>
    </form>

    <?php if ($filter_by_user): ?>
    <div class="stats-personal-note"><i class="fas fa-user-lock mr-2"></i><strong>Personal view.</strong> Payment totals are limited to payments recorded by you.</div>
    <?php endif; ?>

    <section class="stats-metrics" aria-label="Payment totals for selected range">
        <div class="stats-metric stats-metric--cash"><span class="stats-metric-label">Cash received</span><strong class="stats-metric-value">₵<?= number_format($total_cash, 2) ?></strong><span class="stats-metric-sub">Selected date range</span></div>
        <div class="stats-metric stats-metric--cheque"><span class="stats-metric-label">Cheque received</span><strong class="stats-metric-value">₵<?= number_format($total_cheque, 2) ?></strong><span class="stats-metric-sub">Selected date range</span></div>
        <div class="stats-metric"><span class="stats-metric-label">All payment methods</span><strong class="stats-metric-value">₵<?= number_format($grand_total, 2) ?></strong><span class="stats-metric-sub">Combined total</span></div>
        <div class="stats-metric"><span class="stats-metric-label">Transactions</span><strong class="stats-metric-value"><?= number_format(array_sum(array_column($payment_method_summary, 'count'))) ?></strong><span class="stats-metric-sub">Across all shown methods</span></div>
    </section>

    <div class="stats-breakdown-grid">
        <section class="stats-section" aria-labelledby="paymentMethodsHeading">
            <div class="stats-section-heading"><div><h2 id="paymentMethodsHeading">Payment methods</h2><p>Method totals and transaction counts</p></div></div>
            <?php if ($payment_method_summary): ?>
            <ul class="stats-method-list">
                <?php
                uasort($payment_method_summary, static function ($a, $b) {
                    return $b['total'] <=> $a['total'];
                });
                foreach ($payment_method_summary as $mode_key => $mode_data):
                    $mode_icon = payment_mode_icon($mode_key);
                ?>
                <li class="stats-method-row">
                    <div class="stats-method-name"><span class="stats-method-icon"><i class="fas fa-<?= htmlspecialchars($mode_icon) ?>"></i></span><span><strong><?= htmlspecialchars(payment_mode_label($mode_key)) ?></strong><small><?= number_format((int) $mode_data['count']) ?> transaction(s)<?= in_array($mode_key, ['cash', 'cheque'], true) ? ' · ' . (!empty($mode_key === 'cash' ? $entry : $cheque_entry['total']) ? 'Analysis entered for end date' : 'No end-date analysis') : '' ?></small></span></div>
                    <div class="stats-method-amount">₵<?= number_format((float) $mode_data['total'], 2) ?></div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <div class="stats-empty">No payment activity for this range.</div>
            <?php endif; ?>
        </section>

        <section class="stats-section" aria-labelledby="paymentTypesHeading">
            <div class="stats-section-heading"><div><h2 id="paymentTypesHeading">Payment type breakdown</h2><p>Posted payments grouped by type</p></div></div>
            <div class="stats-table-wrap">
                <table class="table table-sm stats-table">
                    <thead><tr><th>Payment type</th><th class="text-right">Transactions</th><th class="text-right">Amount</th></tr></thead>
                    <tbody>
                    <?php
                    $types = [];
                    try {
                        if ($filter_by_user) {
                            $sql = "SELECT pt.name AS payment_type, COALESCE(SUM(p.amount),0) AS total_amount, COALESCE(COUNT(p.id),0) AS count
                                    FROM payment_types pt
                                    LEFT JOIN v_posted_payments p ON p.payment_type_id = pt.id
                                      AND p.payment_date >= ? AND p.payment_date < DATE_ADD(?, INTERVAL 1 DAY)
                                      AND p.recorded_by = ?
                                    GROUP BY pt.id, pt.name HAVING COUNT(p.id) > 0
                                    ORDER BY total_amount DESC, pt.name ASC";
                            $stmt = $conn->prepare($sql);
                            $stmt->bind_param('ssi', $start_date, $end_date, $current_user_id);
                        } else {
                            $sql = "SELECT pt.name AS payment_type, COALESCE(SUM(p.amount),0) AS total_amount, COALESCE(COUNT(p.id),0) AS count
                                    FROM payment_types pt
                                    LEFT JOIN v_posted_payments p ON p.payment_type_id = pt.id
                                      AND p.payment_date >= ? AND p.payment_date < DATE_ADD(?, INTERVAL 1 DAY)
                                    GROUP BY pt.id, pt.name HAVING COUNT(p.id) > 0
                                    ORDER BY total_amount DESC, pt.name ASC";
                            $stmt = $conn->prepare($sql);
                            $stmt->bind_param('ss', $start_date, $end_date);
                        }
                        $stmt->execute();
                        $result = $stmt->get_result();
                        while ($row = $result->fetch_assoc()) $types[] = $row;
                        $stmt->close();
                    } catch (Throwable $e) {}
                    ?>
                    <?php if ($types): foreach ($types as $typeRow): ?>
                    <tr><td><?= htmlspecialchars($typeRow['payment_type']) ?></td><td class="text-right"><?= number_format((int) $typeRow['count']) ?></td><td class="text-right font-weight-bold">₵<?= number_format((float) $typeRow['total_amount'], 2) ?></td></tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="3" class="stats-empty">No payment types found for this range.</td></tr>
                    <?php endif; ?>
                    </tbody>
                    <tfoot><tr><td colspan="2" class="text-right">All methods</td><td class="text-right">₵<?= number_format($grand_total, 2) ?></td></tr></tfoot>
                </table>
            </div>
        </section>
    </div>

    <section class="stats-section stats-review" aria-labelledby="cashAnalysisHeading">
        <div class="stats-review-title">
            <div><div class="stats-eyebrow"><?= $can_review_cash_analyses ? 'Cash accountability' : 'Your submissions' ?></div><h2 id="cashAnalysisHeading"><?= $can_review_cash_analyses ? 'Cash analysis review' : 'Cashier analysis history' ?></h2><p><?= $can_review_cash_analyses ? 'Compare submitted notes and coins with cash posted by each cashier for the same church and day.' : 'Your saved daily cash and cheque analysis submissions.' ?></p></div>
        </div>
        <?php if ($can_review_cash_analyses): ?>
        <form method="get" class="stats-review-tools">
            <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
            <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
            <div><label for="analysis_cashier_id">Cashier</label><select name="cashier_id" id="analysis_cashier_id" class="form-control"><option value="0">All cashiers</option><?php foreach ($analysis_cashiers as $analysisCashier): ?><option value="<?= (int) $analysisCashier['cashier_id'] ?>" <?= $analysis_cashier_filter === (int) $analysisCashier['cashier_id'] ? 'selected' : '' ?>><?= htmlspecialchars($analysisCashier['cashier_name']) ?></option><?php endforeach; ?></select></div>
            <button type="submit" class="btn btn-outline-dark"><i class="fas fa-filter mr-1"></i>Filter cashier</button>
            <?php if ($analysis_cashier_filter > 0): ?><a href="?<?= http_build_query(['start_date' => $start_date, 'end_date' => $end_date]) ?>" class="btn btn-link">Clear</a><?php endif; ?>
        </form>
        <div class="stats-review-kpis" aria-label="Cash reconciliation summary">
            <div class="stats-review-kpi"><span>Cash submissions</span><strong><?= $cashAnalysisSummary['submissions'] ?></strong></div>
            <div class="stats-review-kpi"><span>Matched</span><strong class="text-success"><?= $cashAnalysisSummary['matched'] ?></strong></div>
            <div class="stats-review-kpi"><span>Short</span><strong class="text-danger"><?= $cashAnalysisSummary['short'] ?></strong></div>
            <div class="stats-review-kpi"><span>Over</span><strong class="text-warning"><?= $cashAnalysisSummary['over'] ?></strong></div>
        </div>
        <section class="stats-denomination-matrix" aria-labelledby="cashDenominationMatrixHeading">
            <div class="stats-section-heading">
                <div><h2 id="cashDenominationMatrixHeading">Denomination totals by cashier</h2><p>Quantities are combined across each cashier's cash submissions for <?= htmlspecialchars($date_range_label) ?>.</p></div>
            </div>
            <div class="stats-table-wrap">
                <table class="table table-sm stats-table">
                    <thead><tr><th>Denomination</th><?php foreach ($cashDenominationMatrix['cashiers'] as $cashier): ?><th class="text-right"><?= htmlspecialchars($cashier['name']) ?></th><?php endforeach; ?><th class="text-right">Total</th><th class="text-right">Amount</th></tr></thead>
                    <tbody>
                    <?php if (!$cashDenominationMatrix['cashiers']): ?>
                    <tr><td colspan="<?= count($cashDenominationMatrix['cashiers']) + 3 ?>" class="stats-empty">No cash denomination submissions for this date range.</td></tr>
                    <?php else: foreach ($cashDenominationMatrix['rows'] as $label => $matrixRow): ?>
                    <tr>
                        <td class="denomination-value">₵<?= number_format($matrixRow['value'], 2) ?></td>
                        <?php foreach ($cashDenominationMatrix['cashiers'] as $cashier): ?><td class="cashier-count"><?= number_format($cashier['counts'][$label]) ?></td><?php endforeach; ?>
                        <td class="cashier-count font-weight-bold"><?= number_format($matrixRow['total_quantity']) ?></td>
                        <td class="cashier-count matrix-total">₵<?= number_format($matrixRow['amount'], 2) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                    <?php if ($cashDenominationMatrix['cashiers']): ?>
                    <tfoot><tr><th>Total counted</th><?php foreach ($cashDenominationMatrix['cashiers'] as $cashier): ?><th class="text-right"><?= number_format($cashier['quantity']) ?> pcs<br><span class="matrix-total">₵<?= number_format($cashier['amount'], 2) ?></span></th><?php endforeach; ?><th class="text-right"><?= number_format($cashDenominationMatrix['total_quantity']) ?> pcs</th><th class="text-right matrix-total">₵<?= number_format($cashDenominationMatrix['total_amount'], 2) ?></th></tr></tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </section>
        <?php endif; ?>
        <div class="stats-table-wrap">
            <table class="table table-sm stats-table stats-review-table">
                <thead><tr><th>Date</th><th>Cashier</th><th>Mode</th><th class="text-right">Notes</th><th class="text-right">Coins</th><th class="text-right">Counted total</th><th class="text-right">Posted cash</th><th class="text-right">Difference</th><th>Reconciliation</th><th>Updated</th><th>Denominations</th></tr></thead>
                <tbody>
                <?php if (!$analysis_history): ?>
                <tr><td colspan="11" class="stats-empty">No cashier analysis submissions in this date range.</td></tr>
                <?php else: foreach ($analysis_history as $history):
                    $isCashAnalysis = $history['payment_mode'] === 'cash';
                    $historyTotal = $isCashAnalysis ? (float) $history['denomination_total'] : (float) $history['cheque_total'];
                    $postedCashTotal = (float) ($history['posted_cash_total'] ?? 0);
                    $cashDifference = $historyTotal - $postedCashTotal;
                    $reconciliationLabel = !$isCashAnalysis ? 'N/A' : (abs($cashDifference) < 0.01 ? 'Matched' : ($cashDifference < 0 ? 'Short' : 'Over'));
                    $reconciliationClass = $reconciliationLabel === 'Matched' ? 'success' : ($reconciliationLabel === 'Short' ? 'danger' : ($reconciliationLabel === 'Over' ? 'warning' : 'secondary'));
                    $denominationCounts = $isCashAnalysis ? (json_decode((string) ($history['denomination_data'] ?? ''), true) ?: []) : [];
                ?>
                <tr>
                    <td class="text-nowrap"><?= htmlspecialchars(date('j M Y', strtotime($history['analysis_date']))) ?></td>
                    <td class="font-weight-bold"><?= htmlspecialchars($history['cashier_name']) ?></td>
                    <td><?= htmlspecialchars(ucfirst($history['payment_mode'])) ?><small class="stats-method-state"><?= htmlspecialchars(ucfirst($history['status'])) ?></small></td>
                    <td class="text-right stats-amount"><?= $isCashAnalysis ? '₵' . number_format((float) $history['notes_total'], 2) : '-' ?></td>
                    <td class="text-right stats-amount"><?= $isCashAnalysis ? '₵' . number_format((float) $history['coins_total'], 2) : '-' ?></td>
                    <td class="text-right font-weight-bold stats-amount">₵<?= number_format($historyTotal, 2) ?></td>
                    <td class="text-right stats-amount"><?= $isCashAnalysis ? '₵' . number_format($postedCashTotal, 2) : '-' ?></td>
                    <td class="text-right stats-amount <?= $isCashAnalysis && abs($cashDifference) >= 0.01 ? ($cashDifference < 0 ? 'stats-variance--short' : 'stats-variance--over') : '' ?>"><?= $isCashAnalysis ? ($cashDifference > 0 ? '+' : '') . '₵' . number_format($cashDifference, 2) : '-' ?></td>
                    <td><span class="badge badge-<?= $reconciliationClass ?>"><?= $reconciliationLabel ?></span></td>
                    <td class="text-nowrap"><?= htmlspecialchars(date('j M Y H:i', strtotime($history['updated_at']))) ?></td>
                    <td><?php if ($isCashAnalysis && $denominationCounts): ?><button type="button" class="btn btn-sm btn-outline-primary stats-count-button" data-toggle="modal" data-target="#cashDenominationModal" data-cashier="<?= htmlspecialchars($history['cashier_name'], ENT_QUOTES, 'UTF-8') ?>" data-date="<?= htmlspecialchars(date('j M Y', strtotime($history['analysis_date'])), ENT_QUOTES, 'UTF-8') ?>" data-notes-total="<?= htmlspecialchars((string) $history['notes_total'], ENT_QUOTES, 'UTF-8') ?>" data-coins-total="<?= htmlspecialchars((string) $history['coins_total'], ENT_QUOTES, 'UTF-8') ?>" data-cash-total="<?= htmlspecialchars((string) $history['denomination_total'], ENT_QUOTES, 'UTF-8') ?>" data-denominations="<?= htmlspecialchars(json_encode($denominationCounts), ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-list-ul mr-1"></i>View counts</button><?php else: ?>-<?php endif; ?></td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<div class="modal fade stats-count-modal" id="cashDenominationModal" tabindex="-1" role="dialog" aria-labelledby="cashDenominationModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <div><h5 class="modal-title" id="cashDenominationModalTitle">Cash denomination counts</h5><small id="cashDenominationMeta" class="text-white-50"></small></div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="stats-count-summary">
                    <div><span>Notes total</span><strong id="cashCountNotesTotal">₵0.00</strong></div>
                    <div><span>Coins total</span><strong id="cashCountCoinsTotal">₵0.00</strong></div>
                    <div><span>Cash total</span><strong id="cashCountGrandTotal">₵0.00</strong></div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm stats-table stats-count-table">
                        <thead><tr><th>Denomination</th><th class="text-right">Quantity</th><th class="text-right">Amount</th></tr></thead>
                        <tbody id="cashDenominationRows"></tbody>
                        <tfoot><tr><th colspan="2">Total counted</th><th class="text-right amount-cell" id="cashDenominationTableTotal">₵0.00</th></tr></tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
ob_start();
?>
<?php if ($can_manage_analysis): ?>
               <!-- Denomination Modal -->
               <div class="modal fade stats-modal" id="denomModal" tabindex="-1" role="dialog" aria-labelledby="denomModalLabel" aria-hidden="true">
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
                             $columns = [
                                 'Notes (Paper Currency)' => array_values(array_filter($denominations, static fn($d) => $d['type'] === 'note')),
                                 'Coins' => array_values(array_filter($denominations, static fn($d) => $d['type'] === 'coin')),
                             ];
                             foreach ($columns as $columnTitle => $col): ?>
                             <div class="col-6">
                                 <h6 class="font-weight-bold text-center mb-2"><?= htmlspecialchars($columnTitle) ?></h6>
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
                             <i class="fas fa-money-check mr-2"></i>Enter cheque payment details and amounts. Cheque numbers from payments will be displayed below.
                           </div>
                           
                           <!-- Display cheque payments from database -->
                           <div class="mb-3">
                             <h6 class="font-weight-bold">Cheque Payments for <?= date('M j, Y', strtotime($date)) ?></h6>
                             <div class="table-responsive">
                               <table class="table table-sm table-bordered">
                                 <thead class="thead-light">
                                   <tr>
                                     <th>Member/Student</th>
                                     <th>Cheque Number</th>
                                     <th>Amount (₵)</th>
                                     <th>Payment Type</th>
                                   </tr>
                                 </thead>
                                 <tbody>
                                   <?php
                                   // Fetch cheque payments for the date
                                   $cheque_payments = [];
                                   try {
                                       if ($filter_by_user) {
                                           $stmt = $conn->prepare("
                                               SELECT p.*, 
                                                      COALESCE(m.first_name, ss.first_name) as first_name,
                                                      COALESCE(m.last_name, ss.last_name) as last_name,
                                                      COALESCE(m.crn, ss.srn) as identifier,
                                                      pt.name as payment_type_name
                                               FROM v_posted_payments p
                                               LEFT JOIN members m ON p.member_id = m.id
                                               LEFT JOIN sunday_school ss ON p.sundayschool_id = ss.id
                                               LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
                                               WHERE DATE(p.payment_date) = ? 
                                                 AND p.mode = 'cheque' 
                                                 AND p.recorded_by = ?
                                               ORDER BY p.id
                                           ");
                                           $stmt->bind_param('si', $date, $current_user_id);
                                       } else {
                                           $stmt = $conn->prepare("
                                               SELECT p.*, 
                                                      COALESCE(m.first_name, ss.first_name) as first_name,
                                                      COALESCE(m.last_name, ss.last_name) as last_name,
                                                      COALESCE(m.crn, ss.srn) as identifier,
                                                      pt.name as payment_type_name
                                               FROM v_posted_payments p
                                               LEFT JOIN members m ON p.member_id = m.id
                                               LEFT JOIN sunday_school ss ON p.sundayschool_id = ss.id
                                               LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
                                               WHERE DATE(p.payment_date) = ? 
                                                 AND p.mode = 'cheque'
                                               ORDER BY p.id
                                           ");
                                           $stmt->bind_param('s', $date);
                                       }
                                       $stmt->execute();
                                       $result = $stmt->get_result();
                                       while ($row = $result->fetch_assoc()) {
                                           $cheque_payments[] = $row;
                                       }
                                       $stmt->close();
                                   } catch (Throwable $e) {
                                       // Silently fail
                                   }
                                   
                                   if (empty($cheque_payments)): ?>
                                     <tr>
                                       <td colspan="4" class="text-center text-muted">No cheque payments recorded for this date</td>
                                     </tr>
                                   <?php else: 
                                     foreach ($cheque_payments as $cp): ?>
                                       <tr>
                                         <td><?= htmlspecialchars(($cp['first_name'] ?? '') . ' ' . ($cp['last_name'] ?? '')) ?><br>
                                             <small class="text-muted"><?= htmlspecialchars($cp['identifier'] ?? 'N/A') ?></small>
                                         </td>
                                         <td><strong><?= htmlspecialchars($cp['cheque_number'] ?? 'Not specified') ?></strong></td>
                                         <td>₵<?= number_format($cp['amount'], 2) ?></td>
                                         <td><?= htmlspecialchars($cp['payment_type_name'] ?? 'N/A') ?></td>
                                       </tr>
                                     <?php endforeach;
                                   endif; ?>
                                 </tbody>
                               </table>
                             </div>
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
                             <label for="cheque_details">Additional Cheque Details (Optional)</label>
                             <textarea class="form-control" id="cheque_details" name="cheque_details" rows="2" placeholder="Bank names, additional notes, etc."><?= htmlspecialchars($cheque_entry['details']) ?></textarea>
                           </div>
                         </div>

                         <div class="row align-items-center" id="totalRow">
                           <div class="col-3 text-right font-weight-bold">Notes: &#8373;<span id="notes-total"><?= number_format($entry_notes_total, 2) ?></span></div>
                           <div class="col-3 text-right font-weight-bold">Coins: &#8373;<span id="coins-total"><?= number_format($entry_coins_total, 2) ?></span></div>
                           <div class="col-3 text-right font-weight-bold">Total</div>
                           <div class="col-3 text-right font-weight-bold">&#8373;<span id="denom-total">0.00</span></div>
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
<?php endif; ?>
<script>
// Live denomination calculation (only inside modal)
const denomData = <?= json_encode($denominations) ?>;
function updateDenomTotals() {
    let total = 0;
    let notesTotal = 0;
    let coinsTotal = 0;
    denomData.forEach(function(d) {
        let qty = parseInt(document.querySelector('[name="denom['+d.label+']"]')?.value) || 0;
        let value = qty * d.value;
        let el = document.querySelector('.denom-value[data-label="'+d.label+'"]');
        if (el) el.textContent = value.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
        total += value;
        if (d.type === 'note') notesTotal += value;
        if (d.type === 'coin') coinsTotal += value;
    });
    let notesCell = document.getElementById('notes-total');
    let coinsCell = document.getElementById('coins-total');
    if (notesCell) notesCell.textContent = notesTotal.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
    if (coinsCell) coinsCell.textContent = coinsTotal.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
    let totalCell = document.getElementById('denom-total');
    if (totalCell) {
        totalCell.textContent = total.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
        // Highlight error if not matching cash
        let expected = 0;
        <?php
        // Set expected to cash-mode total
        echo "expected = " . floatval($analysis_cash_total) . ";";
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
        formData.append('csrf_token', <?= json_encode(csrf_token()) ?>);
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

    $('#cashDenominationModal').on('show.bs.modal', function(event) {
        const trigger = event.relatedTarget;
        if (!trigger) return;

        let counts = {};
        try {
            counts = JSON.parse(trigger.getAttribute('data-denominations') || '{}');
        } catch (error) {
            counts = {};
        }

        const formatCurrency = function(value) {
            return '₵' + Number(value || 0).toLocaleString('en-GH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        };
        const $rows = $('#cashDenominationRows').empty();
        let calculatedTotal = 0;

        $('#cashDenominationMeta').text(
            (trigger.getAttribute('data-cashier') || 'Cashier') + ' · ' +
            (trigger.getAttribute('data-date') || '')
        );

        denomData.forEach(function(denomination) {
            const quantity = Math.max(0, parseInt(counts[denomination.label], 10) || 0);
            const amount = quantity * Number(denomination.value);
            calculatedTotal += amount;

            const $row = $('<tr>');
            $row.append($('<td>').text(denomination.label));
            $row.append($('<td class="text-right">').text(quantity.toLocaleString('en-GH')));
            $row.append($('<td class="text-right amount-cell">').text(formatCurrency(amount)));
            $rows.append($row);
        });

        $('#cashCountNotesTotal').text(formatCurrency(trigger.getAttribute('data-notes-total')));
        $('#cashCountCoinsTotal').text(formatCurrency(trigger.getAttribute('data-coins-total')));
        $('#cashCountGrandTotal').text(formatCurrency(trigger.getAttribute('data-cash-total')));
        $('#cashDenominationTableTotal').text(formatCurrency(calculatedTotal));
    });
});
</script>
<?php
$modal_html = ob_get_clean();
include __DIR__.'/../includes/layout.php';
?>


