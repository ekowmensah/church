<?php
require_once __DIR__.'/../../../config/config.php';
require_once __DIR__.'/../../../helpers/auth.php';

if (!is_logged_in()) {
    http_response_code(403);
    exit('Unauthorized');
}

$user_id = intval($_GET['user_id'] ?? 0);
$payment_type_id = intval($_GET['payment_type_id'] ?? 0);
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = (isset($_GET['per_page']) && $_GET['per_page'] === 'all') ? null : 10;
$offset = $per_page ? ($page - 1) * $per_page : 0;

$where = ['p.recorded_by = ?'];
$params = [$user_id];
$types = 'i';

if ($payment_type_id) {
    $where[] = 'p.payment_type_id = ?';
    $params[] = $payment_type_id;
    $types .= 'i';
}
if ($date_from) {
    $where[] = 'p.payment_date >= ?';
    $params[] = $date_from;
    $types .= 's';
}
if ($date_to) {
    $where[] = 'p.payment_date <= ?';
    $params[] = $date_to;
    $types .= 's';
}

// Main query with member/sunday_school join
$sql = "SELECT p.id, p.payment_date, p.amount, p.description,
        m.first_name, m.last_name, m.middle_name, m.crn,
        ss.srn, ss.first_name AS ss_first_name, ss.last_name AS ss_last_name, ss.middle_name AS ss_middle_name
        FROM payments p
        LEFT JOIN members m ON p.member_id = m.id
        LEFT JOIN sunday_school ss ON p.sundayschool_id = ss.id
        WHERE ".implode(' AND ', $where)."
        ORDER BY p.payment_date DESC";
if ($per_page) {
    $sql .= " LIMIT ? OFFSET ?";
    $params[] = $per_page;
    $types .= 'i';
    $params[] = $offset;
    $types .= 'i';
}
$stmt = $conn->prepare($sql);
if (!empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Count query for pagination
$count_sql = "SELECT COUNT(*) as total FROM payments p WHERE ".implode(' AND ', $where);
$count_stmt = $conn->prepare($count_sql);
if (!empty($types) && $per_page) {
    // Remove last two types/params for LIMIT/OFFSET
    $count_types = substr($types, 0, -2);
    $count_params = array_slice($params, 0, -2);
    if (!empty($count_types)) {
        $count_stmt->bind_param($count_types, ...$count_params);
    }
} else if (!empty($types)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_rows = $count_result->fetch_assoc()['total'];
$total_pages = $per_page ? ceil($total_rows / $per_page) : 1;

// --- STATISTICS QUERIES ---
$stats_where = $where;
$stats_params = $params;
$stats_types = $types;
// Remove pagination params/types for stats queries if per_page is set
if ($per_page) {
    $stats_types = substr($types, 0, -2);
    $stats_params = array_slice($params, 0, -2);
}

// Total payments (all time, filtered)
$total_sql = "SELECT COALESCE(SUM(amount),0) as total FROM payments p WHERE ".implode(' AND ', $stats_where);
$total_stmt = $conn->prepare($total_sql);
if (!empty($stats_types)) $total_stmt->bind_param($stats_types, ...$stats_params);
$total_stmt->execute();
$total_stmt->bind_result($total_paid);
$total_stmt->fetch();
$total_stmt->close();

// Payments this week
$week_where = $stats_where;
$week_params = $stats_params;
$week_types = $stats_types;
$week_where[] = "YEARWEEK(p.payment_date, 1) = YEARWEEK(CURDATE(), 1)";
$week_sql = "SELECT COALESCE(SUM(amount),0) as total FROM payments p WHERE ".implode(' AND ', $week_where);
$week_stmt = $conn->prepare($week_sql);
if (!empty($week_types)) $week_stmt->bind_param($week_types, ...$week_params);
$week_stmt->execute();
$week_stmt->bind_result($week_total);
$week_stmt->fetch();
$week_stmt->close();

// Payments this month
$month_where = $stats_where;
$month_params = $stats_params;
$month_types = $stats_types;
$month_where[] = "YEAR(p.payment_date) = YEAR(CURDATE()) AND MONTH(p.payment_date) = MONTH(CURDATE())";
$month_sql = "SELECT COALESCE(SUM(amount),0) as total FROM payments p WHERE ".implode(' AND ', $month_where);
$month_stmt = $conn->prepare($month_sql);
if (!empty($month_types)) $month_stmt->bind_param($month_types, ...$month_params);
$month_stmt->execute();
$month_stmt->bind_result($month_total);
$month_stmt->fetch();
$month_stmt->close();

// Payment type breakdown
$type_sql = "SELECT pt.name, COALESCE(SUM(p.amount),0) as total FROM payments p
             LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
             WHERE ".implode(' AND ', $stats_where)." GROUP BY pt.id";
$type_stmt = $conn->prepare($type_sql);
if (!empty($stats_types)) $type_stmt->bind_param($stats_types, ...$stats_params);
$type_stmt->execute();
$type_result = $type_stmt->get_result();
$breakdown = [];
while ($r = $type_result->fetch_assoc()) {
    $breakdown[] = $r;
}
$type_stmt->close();
?>
<div class="row mb-3">
  <div class="col-md-3 mb-2">
    <div class="card shadow-sm border-left-primary h-100">
      <div class="card-body p-2 text-center">
        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Payments</div>
        <div class="h5 mb-0 font-weight-bold text-gray-800">₵<?= number_format($total_paid,2) ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-3 mb-2">
    <div class="card shadow-sm border-left-success h-100">
      <div class="card-body p-2 text-center">
        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">This Week</div>
        <div class="h5 mb-0 font-weight-bold text-gray-800">₵<?= number_format($week_total,2) ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-3 mb-2">
    <div class="card shadow-sm border-left-warning h-100">
      <div class="card-body p-2 text-center">
        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">This Month</div>
        <div class="h5 mb-0 font-weight-bold text-gray-800">₵<?= number_format($month_total,2) ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-3 mb-2">
    <div class="card shadow-sm border-left-info h-100">
      <div class="card-body p-2 text-center">
        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">By Type</div>
        <?php foreach($breakdown as $b): ?>
          <div class="small"><?= htmlspecialchars($b['name']) ?>: <span class="font-weight-bold">₵<?= number_format($b['total'],2) ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php

if ($result->num_rows === 0) {
    echo '<div class="text-center text-muted py-4">No transactions found for this user and filter.</div>';
    exit;
}
?>
<div class="mb-2 text-right">
  <button class="btn btn-success btn-sm mr-1" id="export-transactions-excel"><i class="fas fa-file-excel"></i> Export to Excel</button>
  <button class="btn btn-secondary btn-sm" id="export-transactions-csv"><i class="fas fa-file-csv"></i> Export to CSV</button>
</div>
<table class="table table-bordered table-hover" id="user-transactions-table">
  <thead>
    <tr>
      <th>Date</th>
      <th>Member</th>
      <th>Amount</th>
      <th>Description</th>
    </tr>
  </thead>
  <tbody>
    <?php 
    $total_on_page = 0;
    while($row = $result->fetch_assoc()): 
      $total_on_page += $row['amount'];
    ?>
    <tr>
      <td><?= htmlspecialchars(substr($row['payment_date'], 0, 10)) ?></td>
      <td>
        <?php
          if ($row['first_name'] || $row['last_name']) {
            echo htmlspecialchars(trim($row['last_name'].' '.$row['first_name'].' '.$row['middle_name']));
            if ($row['crn']) echo ' <span class="badge badge-info">CRN: '.htmlspecialchars($row['crn']).'</span>';
          } else if ($row['ss_first_name'] || $row['ss_last_name']) {
            echo htmlspecialchars(trim($row['ss_last_name'].' '.$row['ss_first_name'].' '.$row['ss_middle_name']));
            if ($row['srn']) echo ' <span class="badge badge-warning">SRN: '.htmlspecialchars($row['srn']).'</span>';
          } else {
            echo '<span class="text-muted">N/A</span>';
          }
        ?>
      </td>
      <td>₵<?= number_format($row['amount'], 2) ?></td>
      <td><?= htmlspecialchars($row['description']) ?></td>
    </tr>
    <?php endwhile; ?>
  </tbody>
  <tfoot>
    <tr>
      <td colspan="2" class="text-right font-weight-bold">Total:</td>
      <td class="font-weight-bold text-success">₵<?= number_format($total_on_page,2) ?></td>
      <td></td>
    </tr>
  </tfoot>
</table>
<nav>
  <ul class="pagination justify-content-center">
    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
      <li class="page-item <?= $i == $page ? 'active' : '' ?>">
        <a class="page-link user-transactions-page-link" href="#" data-page="<?= $i ?>"><?= $i ?></a>
      </li>
    <?php endfor; ?>
    <li class="page-item">
      <a class="page-link user-transactions-page-link" href="#" data-page="1" data-per-page="all">Show All</a>
    </li>
  </ul>
</nav>