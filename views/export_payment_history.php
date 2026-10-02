<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';

$isStaff = false;
$isMember = false;
$memberId = 0;

if (!empty($_SESSION['user_id'])) {
    require_once __DIR__ . '/../helpers/auth.php';
    require_once __DIR__ . '/../helpers/permissions_v2.php';
    if (is_logged_in()) {
        $isStaff = true;
        $memberId = (int) ($_GET['member_id'] ?? 0);
        if ($memberId < 1) {
            http_response_code(400);
            exit('A valid member is required.');
        }
        if (!is_super_admin() && !has_permission('view_payment_list')) {
            http_response_code(403);
            exit('You do not have permission to export payment history.');
        }
    }
}

if (!$isStaff) {
    require_once __DIR__ . '/../includes/member_auth.php';
    $memberId = (int) ($_SESSION['member_id'] ?? 0);
    if ($memberId < 1) {
        http_response_code(401);
        exit('Authentication is required.');
    }
    $isMember = true;
}

$memberSql = 'SELECT id, church_id, first_name, last_name, crn FROM members WHERE id = ?';
$memberParams = [$memberId];
$memberTypes = 'i';
if ($isStaff && !is_super_admin()) {
    $churchId = (int) ($_SESSION['church_id'] ?? 0);
    if ($churchId < 1) {
        $churchStmt = $conn->prepare('SELECT church_id FROM users WHERE id = ? LIMIT 1');
        $userId = (int) $_SESSION['user_id'];
        $churchStmt->bind_param('i', $userId);
        $churchStmt->execute();
        $churchId = (int) ($churchStmt->get_result()->fetch_assoc()['church_id'] ?? 0);
        $churchStmt->close();
    }
    $memberSql .= ' AND church_id = ?';
    $memberParams[] = $churchId;
    $memberTypes .= 'i';
}
$memberStmt = $conn->prepare($memberSql);
$memberStmt->bind_param($memberTypes, ...$memberParams);
$memberStmt->execute();
$member = $memberStmt->get_result()->fetch_assoc();
$memberStmt->close();
if (!$member) {
    http_response_code(404);
    exit('Member not found within your authorized church.');
}

$validDate = static function ($value): string {
    $value = trim((string) $value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
};
$startDate = $validDate($_GET['start_date'] ?? '');
$endDate = $validDate($_GET['end_date'] ?? '');
$paymentTypeId = max(0, (int) ($_GET['payment_type'] ?? 0));
$paymentMode = trim((string) ($_GET['payment_mode'] ?? ''));
$minAmount = trim((string) ($_GET['min_amount'] ?? ''));
$maxAmount = trim((string) ($_GET['max_amount'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));

$where = [
    'payment.member_id = ?',
    '(payment.reversal_approved_at IS NULL OR payment.reversal_undone_at IS NOT NULL)',
];
$params = [$memberId];
$types = 'i';
if ($startDate !== '') {
    $where[] = 'payment.payment_date >= ?';
    $params[] = $startDate . ' 00:00:00';
    $types .= 's';
}
if ($endDate !== '') {
    $where[] = 'payment.payment_date <= ?';
    $params[] = $endDate . ' 23:59:59';
    $types .= 's';
}
if ($paymentTypeId > 0) {
    $where[] = 'payment.payment_type_id = ?';
    $params[] = $paymentTypeId;
    $types .= 'i';
}
if ($paymentMode !== '') {
    $where[] = 'payment.mode = ?';
    $params[] = $paymentMode;
    $types .= 's';
}
if ($minAmount !== '' && is_numeric($minAmount)) {
    $where[] = 'payment.amount >= ?';
    $params[] = (float) $minAmount;
    $types .= 'd';
}
if ($maxAmount !== '' && is_numeric($maxAmount)) {
    $where[] = 'payment.amount <= ?';
    $params[] = (float) $maxAmount;
    $types .= 'd';
}
if ($search !== '') {
    $where[] = '(payment.description LIKE ? OR payment_type.name LIKE ? OR CAST(payment.id AS CHAR) LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term);
    $types .= 'sss';
}

$sql = "SELECT payment.id, payment.payment_date, payment_type.name AS payment_type,
               payment.description, payment.payment_period,
               payment.payment_period_description, payment.mode, payment.amount,
               user_account.name AS recorded_by_name
        FROM payments payment
        LEFT JOIN payment_types payment_type ON payment_type.id = payment.payment_type_id
        LEFT JOIN users user_account ON user_account.id = payment.recorded_by
        WHERE " . implode(' AND ', $where) . '
        ORDER BY payment.payment_date DESC, payment.id DESC';
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$safeCrn = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($member['crn'] ?? 'member'));
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="payment_history_' . $safeCrn . '_' . date('Ymd_His') . '.csv"');
header('Cache-Control: no-store, no-cache, must-revalidate');

$output = fopen('php://output', 'wb');
fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, ['Member', trim($member['first_name'] . ' ' . $member['last_name'])]);
fputcsv($output, ['CRN', $member['crn']]);
fputcsv($output, []);
fputcsv($output, ['Payment ID', 'Payment Date', 'Payment Type', 'Description', 'Reporting Period', 'Mode', 'Amount (GHS)', 'Recorded By']);
foreach ($rows as $row) {
    $period = trim((string) ($row['payment_period_description'] ?? ''));
    if ($period === '') {
        $period = (string) ($row['payment_period'] ?? '');
    }
    $values = [
        $row['id'], $row['payment_date'], $row['payment_type'], $row['description'],
        $period, $row['mode'], number_format((float) $row['amount'], 2, '.', ''),
        $row['recorded_by_name'],
    ];
    $values = array_map(static function ($value) {
        $value = (string) ($value ?? '');
        return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
    }, $values);
    fputcsv($output, $values);
}
fclose($output);
exit;

