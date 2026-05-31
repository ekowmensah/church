<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/leader_helpers.php';
require_once __DIR__ . '/../helpers/church_helper.php';
require_once __DIR__ . '/../helpers/bible_class_book_helper.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$memberId = isset($_SESSION['member_id']) ? (int) $_SESSION['member_id'] : null;
$isLeader = false;
$leaderInfo = is_bible_class_leader($conn, $userId, $memberId);
if ($leaderInfo) {
    $isLeader = true;
}

$canView = has_permission('view_bible_class_book') || $isLeader;
if (!$canView) {
    http_response_code(403);
    include __DIR__ . '/errors/403.php';
    exit;
}

$canSync = has_permission('sync_bible_class_book') || $isLeader;
$canFinalize = has_permission('finalize_bible_class_book');
$canExport = has_permission('export_bible_class_book') || $isLeader;
$isSuper = has_permission('*') || has_role('Super Admin');

$bookTablesAvailable = bcb_book_tables_available($conn);

$currentYear = (int) date('Y');
$currentQuarter = bcb_quarter_from_month((int) date('n'));

$selectedYear = isset($_GET['year']) ? (int) $_GET['year'] : $currentYear;
$selectedQuarter = isset($_GET['quarter']) ? (int) $_GET['quarter'] : $currentQuarter;
if ($selectedYear < 2020 || $selectedYear > ($currentYear + 1)) {
    $selectedYear = $currentYear;
}
if ($selectedQuarter < 1 || $selectedQuarter > 4) {
    $selectedQuarter = $currentQuarter;
}

if ($isLeader) {
    $selectedChurchId = (int) $leaderInfo['church_id'];
    $selectedClassId = (int) $leaderInfo['class_id'];
} else {
    $defaultChurchId = (int) (get_user_church_id($conn) ?: 0);
    $selectedChurchId = $isSuper
        ? (isset($_GET['church_id']) && (int) $_GET['church_id'] > 0 ? (int) $_GET['church_id'] : $defaultChurchId)
        : $defaultChurchId;
    $selectedClassId = isset($_GET['class_id']) && (int) $_GET['class_id'] > 0 ? (int) $_GET['class_id'] : 0;
}

$churches = [];
if ($isSuper) {
    $churchRes = $conn->query('SELECT id, name FROM churches ORDER BY name ASC');
    while ($churchRes && ($row = $churchRes->fetch_assoc())) {
        $churches[] = $row;
    }
}

$classOptions = [];
if ($selectedChurchId > 0) {
    $classStmt = $conn->prepare('SELECT id, name, code FROM bible_classes WHERE church_id = ? ORDER BY name ASC');
    $classStmt->bind_param('i', $selectedChurchId);
    $classStmt->execute();
    $classRes = $classStmt->get_result();
    while ($row = $classRes->fetch_assoc()) {
        $classOptions[] = $row;
    }
    $classStmt->close();
}

if ($isLeader && $selectedClassId > 0) {
    $existsInOptions = false;
    foreach ($classOptions as $opt) {
        if ((int) $opt['id'] === $selectedClassId) {
            $existsInOptions = true;
            break;
        }
    }
    if (!$existsInOptions) {
        $classOptions[] = [
            'id' => $selectedClassId,
            'name' => $leaderInfo['class_name'] ?? 'My Class',
            'code' => $leaderInfo['code'] ?? ''
        ];
    }
}

$alerts = [];

$fetchBookHeader = function ($churchId, $classId, $year, $quarter) use ($conn, $bookTablesAvailable) {
    if (!$bookTablesAvailable || $churchId <= 0 || $classId <= 0) {
        return null;
    }
    $sql = "SELECT b.*, CONCAT_WS(' ', u.name, m.first_name, m.last_name) AS finalized_by_name
            FROM bible_class_books b
            LEFT JOIN users u ON u.id = b.finalized_by
            LEFT JOIN members m ON m.id = b.finalized_by
            WHERE b.church_id = ? AND b.class_id = ? AND b.book_year = ? AND b.book_quarter = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiii', $churchId, $classId, $year, $quarter);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
};

$bookHeader = $fetchBookHeader($selectedChurchId, $selectedClassId, $selectedYear, $selectedQuarter);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if (!$bookTablesAvailable) {
        $alerts[] = ['type' => 'danger', 'text' => 'Bible Class Book tables are missing. Run migration 2026_06_05_1000 first.'];
    } elseif ($selectedChurchId <= 0 || $selectedClassId <= 0) {
        $alerts[] = ['type' => 'warning', 'text' => 'Select a valid church and class to continue.'];
    } elseif ($action === 'sync') {
        if (!$canSync) {
            $alerts[] = ['type' => 'danger', 'text' => 'You do not have permission to sync this book.'];
        } elseif ($bookHeader && strtolower((string) $bookHeader['status']) === 'finalized') {
            $alerts[] = ['type' => 'warning', 'text' => 'This quarter is finalized and cannot be re-synced.'];
        } else {
            $bookData = bcb_build_quarter_book_data($conn, $selectedChurchId, $selectedClassId, $selectedYear, $selectedQuarter);
            $result = bcb_upsert_snapshot($conn, $selectedChurchId, $selectedClassId, $selectedYear, $selectedQuarter, (int) ($userId ?: $memberId ?: 0), $bookData);
            if (!empty($result['success'])) {
                $alerts[] = ['type' => 'success', 'text' => 'Snapshot refreshed successfully.'];
            } else {
                $alerts[] = ['type' => 'danger', 'text' => 'Sync failed: ' . ($result['message'] ?? 'Unknown error')];
            }
        }
    } elseif ($action === 'finalize') {
        if (!$canFinalize) {
            $alerts[] = ['type' => 'danger', 'text' => 'You do not have permission to finalize this book.'];
        } else {
            $bookHeader = $fetchBookHeader($selectedChurchId, $selectedClassId, $selectedYear, $selectedQuarter);
            if (!$bookHeader) {
                $bookData = bcb_build_quarter_book_data($conn, $selectedChurchId, $selectedClassId, $selectedYear, $selectedQuarter);
                $result = bcb_upsert_snapshot($conn, $selectedChurchId, $selectedClassId, $selectedYear, $selectedQuarter, (int) ($userId ?: $memberId ?: 0), $bookData);
                if (empty($result['success'])) {
                    $alerts[] = ['type' => 'danger', 'text' => 'Finalize failed: unable to create snapshot first.'];
                }
            }

            $bookHeader = $fetchBookHeader($selectedChurchId, $selectedClassId, $selectedYear, $selectedQuarter);
            if ($bookHeader) {
                if (strtolower((string) $bookHeader['status']) === 'finalized') {
                    $alerts[] = ['type' => 'info', 'text' => 'This book is already finalized.'];
                } else {
                    $finalizeStmt = $conn->prepare('UPDATE bible_class_books SET status = \'finalized\', finalized_at = NOW(), finalized_by = ? WHERE id = ?');
                    $finalizeBy = (int) ($userId ?: $memberId ?: 0);
                    $bookId = (int) $bookHeader['id'];
                    $finalizeStmt->bind_param('ii', $finalizeBy, $bookId);
                    $ok = $finalizeStmt->execute();
                    $finalizeStmt->close();

                    if ($ok) {
                        $alerts[] = ['type' => 'success', 'text' => 'Quarter book finalized successfully.'];
                    } else {
                        $alerts[] = ['type' => 'danger', 'text' => 'Finalize failed due to database error.'];
                    }
                }
            }
        }
    }

    $bookHeader = $fetchBookHeader($selectedChurchId, $selectedClassId, $selectedYear, $selectedQuarter);
}

$bookData = null;
if ($selectedChurchId > 0 && $selectedClassId > 0) {
    $bookData = bcb_build_quarter_book_data($conn, $selectedChurchId, $selectedClassId, $selectedYear, $selectedQuarter);
}

$className = '';
$classCode = '';
foreach ($classOptions as $opt) {
    if ((int) $opt['id'] === $selectedClassId) {
        $className = (string) ($opt['name'] ?? '');
        $classCode = (string) ($opt['code'] ?? '');
        break;
    }
}

$yearOptions = [];
for ($y = $currentYear - 2; $y <= $currentYear + 1; $y++) {
    $yearOptions[] = $y;
}

ob_start();
?>
<style>
.bcb-hero {
    border-radius: 16px;
    background: linear-gradient(120deg, #113b65, #1e5a8f);
    color: #fff;
}
.bcb-kpi {
    border: 1px solid #dbe4ee;
    border-radius: 12px;
    background: #fff;
}
.bcb-kpi .label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #6b7786;
    font-weight: 700;
}
.bcb-kpi .value {
    font-size: 1.4rem;
    font-weight: 700;
    color: #12395b;
}
.bcb-cell {
    min-width: 46px;
}
.bcb-att-code {
    font-weight: 700;
    font-size: 12px;
}
.bcb-amount {
    font-size: 11px;
    color: #3b4f63;
}
.bcb-sheet-wrap {
    overflow-x: auto;
    background: #fff;
}
.bcb-sheet {
    width: max-content;
    min-width: 100%;
    border-collapse: collapse;
    font-family: "Times New Roman", Georgia, serif;
    font-size: 13px;
}
.bcb-sheet th,
.bcb-sheet td {
    border: 1.25px solid #3f3f3f;
    padding: 4px 5px;
    vertical-align: middle;
}
.bcb-sheet thead th {
    background: #efefef;
    font-weight: 700;
}
.bcb-sheet .sheet-title {
    font-size: 36px;
    letter-spacing: .02em;
    font-weight: 700;
    text-transform: uppercase;
    text-align: center;
}
.bcb-sheet .sheet-subtitle {
    font-size: 24px;
    font-weight: 700;
    text-align: center;
    text-transform: uppercase;
}
.bcb-sheet .sheet-quarter-title {
    font-size: 26px;
    font-weight: 700;
    text-transform: uppercase;
    text-align: center;
}
.bcb-sheet .month-name {
    font-size: 22px;
    text-transform: uppercase;
    text-align: center;
}
.bcb-sheet .week-label {
    font-size: 11px;
    text-transform: uppercase;
    text-align: center;
}
.bcb-sheet .ap-label {
    width: 24px;
    font-size: 11px;
    text-align: center;
}
.bcb-sheet .meta-label {
    background: #f7f7f7;
    text-transform: uppercase;
    font-size: 12px;
}
.bcb-sheet .num-cell {
    text-align: center;
    font-weight: 700;
}
.bcb-sheet .money-cell {
    text-align: right;
    font-weight: 700;
}
.bcb-sheet .summary-row th,
.bcb-sheet .summary-row td {
    background: #f4f4f4;
    font-weight: 700;
}
.bcb-sheet .left-meta {
    min-width: 52px;
}
.bcb-sheet .wrapped-head {
    display: inline-block;
    max-width: 44px;
    white-space: normal;
    word-break: break-word;
    line-height: 1.05;
    text-align: center;
}
.bcb-sheet .name-col {
    min-width: 175px;
}
.bcb-sheet .crn-col {
    min-width: 130px;
}
.bcb-expand-btn {
    border: 1px solid #666;
    background: #f5f5f5;
    color: #222;
    width: 20px;
    height: 20px;
    line-height: 16px;
    font-size: 14px;
    font-weight: 700;
    text-align: center;
    padding: 0;
    border-radius: 2px;
    cursor: pointer;
    vertical-align: middle;
}
.bcb-expand-btn:hover {
    background: #e6e6e6;
}
.bcb-member-meta {
    display: none;
    margin-top: 4px;
    border-top: 1px dashed #777;
    padding-top: 4px;
    font-size: 11px;
    line-height: 1.25;
}
.bcb-member-meta.is-open {
    display: block;
}
.bcb-meta-label {
    font-weight: 700;
    text-transform: uppercase;
    color: #38485a;
    margin-right: 3px;
}
@media print {
    .no-print { display: none !important; }
    .content-wrapper { margin-left: 0 !important; }
    .bcb-member-meta { display: block !important; }
    .bcb-expand-btn { display: none !important; }
}
</style>

<div class="container-fluid mt-4">
    <div class="bcb-hero p-3 p-md-4 shadow-sm mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div>
                <h2 class="mb-1"><i class="fas fa-book-open mr-2"></i>Bible Class Book</h2>
                <small>Quarterly class workbook from attendance and tithe records.</small>
            </div>
            <div class="mt-2 mt-md-0 no-print">
                <?php if ($canExport): ?>
                    <button type="button" class="btn btn-light mr-2" onclick="window.print()"><i class="fas fa-print mr-1"></i>Print</button>
                <?php endif; ?>
                <?php if ($isLeader): ?>
                    <a href="my_bible_class_leader.php" class="btn btn-outline-light"><i class="fas fa-chalkboard-teacher mr-1"></i>Leader Dashboard</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php foreach ($alerts as $alert): ?>
        <div class="alert alert-<?= htmlspecialchars($alert['type']) ?>"><?= htmlspecialchars($alert['text']) ?></div>
    <?php endforeach; ?>

    <?php if (!$bookTablesAvailable): ?>
        <div class="alert alert-danger">
            Bible Class Book foundation tables are missing. Run migration
            <code>2026_06_05_1000_bible_class_book_foundation.sql</code> first.
        </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-3 no-print">
        <div class="card-body">
            <form method="get" class="form-row align-items-end">
                <?php if ($isSuper && !$isLeader): ?>
                    <div class="form-group col-md-3">
                        <label>Church</label>
                        <select class="form-control" name="church_id">
                            <?php foreach ($churches as $church): ?>
                                <option value="<?= (int) $church['id'] ?>" <?= $selectedChurchId === (int) $church['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($church['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="form-group <?= ($isSuper && !$isLeader) ? 'col-md-4' : 'col-md-5' ?>">
                    <label>Bible Class</label>
                    <select class="form-control" name="class_id" <?= $isLeader ? 'disabled' : '' ?>>
                        <option value="">Select class</option>
                        <?php foreach ($classOptions as $class): ?>
                            <option value="<?= (int) $class['id'] ?>" <?= $selectedClassId === (int) $class['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($class['name']) ?><?= !empty($class['code']) ? ' (' . htmlspecialchars($class['code']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($isLeader): ?>
                        <input type="hidden" name="class_id" value="<?= (int) $selectedClassId ?>">
                    <?php endif; ?>
                </div>
                <div class="form-group col-md-2">
                    <label>Year</label>
                    <select class="form-control" name="year">
                        <?php foreach ($yearOptions as $y): ?>
                            <option value="<?= $y ?>" <?= $selectedYear === $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-2">
                    <label>Quarter</label>
                    <select class="form-control" name="quarter">
                        <?php for ($q = 1; $q <= 4; $q++): ?>
                            <option value="<?= $q ?>" <?= $selectedQuarter === $q ? 'selected' : '' ?>>Q<?= $q ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group col-md-1">
                    <button class="btn btn-primary btn-block" type="submit">Go</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($selectedClassId <= 0): ?>
        <div class="alert alert-info">Select a bible class to load the quarterly book.</div>
    <?php elseif ($bookData === null): ?>
        <div class="alert alert-warning">Unable to build the class book at this time.</div>
    <?php else: ?>
        <?php
        $membersCount = (int) ($bookData['totals']['members_count'] ?? 0);
        $quarterAmount = (float) ($bookData['totals']['quarter_total_amount'] ?? 0);
        $slots = $bookData['slots'] ?? [];
        $status = strtolower((string) ($bookHeader['status'] ?? 'draft'));
        $monthsInQuarter = bcb_quarter_months($selectedQuarter);
        $displayMonthSlots = [];
        $dynamicColumnCount = 0;
        foreach ($monthsInQuarter as $mCount) {
            $actualMonthSlots = $bookData['slots_by_month'][$mCount] ?? [];
            $displayMonthSlots[$mCount] = [];

            for ($week = 1; $week <= 5; $week++) {
                if (isset($actualMonthSlots[$week - 1])) {
                    $displayMonthSlots[$mCount][] = $actualMonthSlots[$week - 1];
                } else {
                    $displayMonthSlots[$mCount][] = [
                        'slot_key' => null,
                        'month_no' => $mCount,
                        'month_label' => date('F', strtotime(sprintf('%04d-%02d-01', $selectedYear, $mCount))),
                        'week_no_in_month' => $week,
                        'date_label' => ''
                    ];
                }
            }
            $dynamicColumnCount += 10; // 5 weeks * 2 columns (A and P)
        }
        $quarterLabels = [1 => 'First', 2 => 'Second', 3 => 'Third', 4 => 'Fourth'];
        $quarterTitle = strtoupper(($quarterLabels[$selectedQuarter] ?? 'Quarter') . ' Quarter Ending ' . date('F, Y', strtotime($bookData['end_date'])));
        $statusCounts = ['FM' => 0, 'CAT' => 0, 'AD' => 0, 'JUV' => 0, 'IDM' => 0, '--' => 0];
        foreach (($bookData['rows'] ?? []) as $rowCount) {
            $statusCode = strtoupper((string) ($rowCount['member']['member_status_code'] ?? '--'));
            if (!isset($statusCounts[$statusCode])) {
                $statusCounts[$statusCode] = 0;
            }
            $statusCounts[$statusCode]++;
        }
        ?>

        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center">
                    <div>
                        <h4 class="mb-1"><?= htmlspecialchars($className ?: 'Class') ?> <?= $classCode !== '' ? '(' . htmlspecialchars($classCode) . ')' : '' ?></h4>
                        <div class="text-muted">Q<?= (int) $selectedQuarter ?> <?= (int) $selectedYear ?> | <?= htmlspecialchars($bookData['start_date']) ?> to <?= htmlspecialchars($bookData['end_date']) ?></div>
                        <div class="mt-2">
                            <span class="badge badge-<?= $status === 'finalized' ? 'success' : 'warning' ?>">Status: <?= htmlspecialchars(strtoupper($status)) ?></span>
                            <?php if ($bookHeader && !empty($bookHeader['finalized_at'])): ?>
                                <span class="ml-2 text-muted small">Finalized: <?= htmlspecialchars((string) $bookHeader['finalized_at']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mt-2 mt-md-0 no-print text-right">
                        <?php if ($canSync): ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="sync">
                                <button type="submit" class="btn btn-outline-primary mr-2" <?= $status === 'finalized' ? 'disabled' : '' ?>>
                                    <i class="fas fa-sync-alt mr-1"></i>Sync Snapshot
                                </button>
                            </form>
                        <?php endif; ?>
                        <?php if ($canFinalize): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Finalize this quarter book? This will lock sync updates.');">
                                <input type="hidden" name="action" value="finalize">
                                <button type="submit" class="btn btn-success" <?= $status === 'finalized' ? 'disabled' : '' ?>>
                                    <i class="fas fa-lock mr-1"></i>Finalize
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-3 mb-2">
                <div class="bcb-kpi p-3 h-100">
                    <div class="label">Members</div>
                    <div class="value"><?= number_format($membersCount) ?></div>
                </div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="bcb-kpi p-3 h-100">
                    <div class="label">Tracked Weeks</div>
                    <div class="value"><?= number_format(count($slots)) ?></div>
                </div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="bcb-kpi p-3 h-100">
                    <div class="label">Quarter Tithe Total</div>
                    <div class="value"><?= number_format($quarterAmount, 2) ?></div>
                </div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="bcb-kpi p-3 h-100">
                    <div class="label">Payment Types</div>
                    <div class="value"><?= number_format(count($bookData['payment_type_ids'] ?? [])) ?></div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="bcb-sheet-wrap">
                    <table class="bcb-sheet mb-0">
                        <thead>
                            <tr>
                                <th class="meta-label"><span class="wrapped-head">CLASS NAME</span></th>
                                <th colspan="3" class="sheet-title"><?= htmlspecialchars($className ?: 'Bible Class') ?></th>
                                <th colspan="<?= $dynamicColumnCount ?>" class="sheet-subtitle">Bible Class Attendance And Tithe Payment</th>
                                <th rowspan="4" class="meta-label text-center">Total For<br>The Quarter<br>GHC. P</th>
                            </tr>
                            <tr>
                                <th class="meta-label"><?= htmlspecialchars('Q' . (int) $selectedQuarter) ?></th>
                                <th colspan="3" class="sheet-quarter-title"><?= htmlspecialchars($quarterTitle) ?></th>
                                <?php foreach ($monthsInQuarter as $monthNo): ?>
                                    <?php $monthSlots = $displayMonthSlots[$monthNo] ?? []; ?>
                                    <th colspan="<?= count($monthSlots) * 2 ?>" class="month-name"><?= htmlspecialchars(date('F', strtotime(sprintf('%04d-%02d-01', $selectedYear, $monthNo)))) ?></th>
                                <?php endforeach; ?>
                            </tr>
                            <tr>
                                <th rowspan="2" class="left-meta">No.</th>
                                <th rowspan="2" class="left-meta"><span class="wrapped-head">Member Status</span></th>
                                <th rowspan="2" class="crn-col">CRN</th>
                                <th rowspan="2" class="name-col">Full Name</th>
                                <?php foreach ($monthsInQuarter as $monthNo): ?>
                                    <?php $monthSlots = $displayMonthSlots[$monthNo] ?? []; ?>
                                    <?php foreach ($monthSlots as $slot): ?>
                                        <th colspan="2" class="week-label">WK <?= (int) $slot['week_no_in_month'] ?> <?= htmlspecialchars((string) $slot['date_label']) ?></th>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tr>
                            <tr>
                                <?php foreach ($monthsInQuarter as $monthNo): ?>
                                    <?php $monthSlots = $displayMonthSlots[$monthNo] ?? []; ?>
                                    <?php foreach ($monthSlots as $slot): ?>
                                        <th class="ap-label">A</th>
                                        <th class="ap-label">P</th>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (($bookData['rows'] ?? []) as $idx => $row): ?>
                                <?php
                                $member = $row['member'] ?? [];
                                $dob = !empty($member['dob']) ? date('Y-m-d', strtotime((string) $member['dob'])) : '';
                                $marital = strtoupper(substr((string) ($member['marital_status'] ?? ''), 0, 1));
                                $contact = (string) ($member['phone'] ?? '');
                                $profession = strtoupper((string) ($member['profession'] ?? ''));
                                $metaId = 'bcb-meta-' . $idx;
                                ?>
                                <tr>
                                    <td class="num-cell"><?= $idx + 1 ?></td>
                                    <td class="num-cell"><?= htmlspecialchars((string) ($member['member_status_code'] ?? '--')) ?></td>
                                    <td>
                                        <div class="d-flex align-items-center justify-content-between">
                                            <span><?= htmlspecialchars((string) ($member['crn'] ?? '')) ?></span>
                                            <button type="button" class="bcb-expand-btn no-print" data-meta-id="<?= htmlspecialchars($metaId) ?>" aria-label="Toggle member details">+</button>
                                        </div>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars((string) ($member['full_name'] ?? '')) ?></div>
                                        <div id="<?= htmlspecialchars($metaId) ?>" class="bcb-member-meta">
                                            <div><span class="bcb-meta-label">DOB</span><?= htmlspecialchars($dob !== '' ? $dob : '-') ?></div>
                                            <div><span class="bcb-meta-label">Marital</span><?= htmlspecialchars($marital !== '' ? $marital : '-') ?></div>
                                            <div><span class="bcb-meta-label">Contact</span><?= htmlspecialchars($contact !== '' ? $contact : '-') ?></div>
                                            <div><span class="bcb-meta-label">Profession</span><?= htmlspecialchars($profession !== '' ? $profession : '-') ?></div>
                                        </div>
                                    </td>
                                    <?php foreach ($monthsInQuarter as $monthNo): ?>
                                        <?php $monthSlots = $displayMonthSlots[$monthNo] ?? []; ?>
                                        <?php foreach ($monthSlots as $slot): ?>
                                            <?php
                                            $slotKey = $slot['slot_key'] ?? null;
                                            $cell = $slotKey ? ($row['slots'][$slotKey] ?? null) : null;
                                            $attCode = strtoupper((string) ($cell['attendance_code'] ?? ''));
                                            $amount = (float) ($cell['payment_amount'] ?? 0);
                                            ?>
                                            <td class="text-center bcb-cell"><?= htmlspecialchars($attCode !== '' ? $attCode : '') ?></td>
                                            <td class="text-center bcb-cell"><?= $amount > 0 ? number_format($amount, 0) : '' ?></td>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                    <td class="money-cell"><?= number_format((float) ($row['total_amount'] ?? 0), 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($bookData['rows'])): ?>
                                <tr>
                                    <td colspan="<?= 5 + $dynamicColumnCount ?>" class="text-center py-4 text-muted">No active class members found for this period.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                        <?php if (!empty($bookData['rows'])): ?>
                            <tfoot>
                                <tr class="summary-row">
                                    <th colspan="4" class="text-right">TOTAL PAYMENT</th>
                                    <?php foreach ($monthsInQuarter as $monthNo): ?>
                                        <?php $monthSlots = $displayMonthSlots[$monthNo] ?? []; ?>
                                        <?php foreach ($monthSlots as $slot): ?>
                                            <?php $slotKey = $slot['slot_key'] ?? null; ?>
                                            <th class="text-center"></th>
                                            <th class="text-center"><?= $slotKey ? number_format((float) ($bookData['totals']['amount_by_slot'][$slotKey] ?? 0), 0) : '' ?></th>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                    <th class="money-cell"><?= number_format((float) ($bookData['totals']['quarter_total_amount'] ?? 0), 0) ?></th>
                                </tr>
                                <tr class="summary-row">
                                    <th colspan="4" class="text-right">TOTAL PRESENT</th>
                                    <?php foreach ($monthsInQuarter as $monthNo): ?>
                                        <?php $monthSlots = $displayMonthSlots[$monthNo] ?? []; ?>
                                        <?php foreach ($monthSlots as $slot): ?>
                                            <?php $slotKey = $slot['slot_key'] ?? null; ?>
                                            <th class="text-center"><?= $slotKey ? (int) ($bookData['totals']['present_by_slot'][$slotKey] ?? 0) : '' ?></th>
                                            <th class="text-center"></th>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                    <th class="num-cell"><?= array_sum($bookData['totals']['present_by_slot'] ?? []) ?></th>
                                </tr>
                                <tr class="summary-row">
                                    <th colspan="4" class="text-right">VARIANCE (AMOUNT/PRESENT)</th>
                                    <?php foreach ($monthsInQuarter as $monthNo): ?>
                                        <?php $monthSlots = $displayMonthSlots[$monthNo] ?? []; ?>
                                        <?php foreach ($monthSlots as $slot): ?>
                                            <?php
                                            $slotKey = $slot['slot_key'] ?? null;
                                            $present = $slotKey ? (int) ($bookData['totals']['present_by_slot'][$slotKey] ?? 0) : 0;
                                            $amount = $slotKey ? (float) ($bookData['totals']['amount_by_slot'][$slotKey] ?? 0) : 0;
                                            $variance = $present > 0 ? ($amount / $present) : 0;
                                            ?>
                                            <th class="text-center"></th>
                                            <th class="text-center"><?= $slotKey ? ($variance > 0 ? number_format($variance, 2) : '0.00') : '' ?></th>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                    <?php
                                    $grandPresent = array_sum($bookData['totals']['present_by_slot'] ?? []);
                                    $grandAmount = (float) ($bookData['totals']['quarter_total_amount'] ?? 0);
                                    $grandVariance = $grandPresent > 0 ? ($grandAmount / $grandPresent) : 0;
                                    ?>
                                    <th class="money-cell"><?= number_format($grandVariance, 4) ?></th>
                                </tr>
                                <tr class="summary-row">
                                    <th class="text-center">STATUS</th>
                                    <th class="text-center">FM</th>
                                    <th class="text-center">CAT</th>
                                    <th class="text-center">AD</th>
                                    <td colspan="<?= $dynamicColumnCount + 1 ?>" class="text-left">Status Distribution: FM <?= (int) ($statusCounts['FM'] ?? 0) ?>, CAT <?= (int) ($statusCounts['CAT'] ?? 0) ?>, AD <?= (int) ($statusCounts['AD'] ?? 0) ?>, JUV <?= (int) ($statusCounts['JUV'] ?? 0) ?>, IDM <?= (int) ($statusCounts['IDM'] ?? 0) ?></td>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var toggles = document.querySelectorAll('.bcb-expand-btn');
    toggles.forEach(function (toggle) {
        toggle.addEventListener('click', function (event) {
            event.preventDefault();
            var metaId = this.getAttribute('data-meta-id');
            if (!metaId) {
                return;
            }
            var panel = document.getElementById(metaId);
            if (!panel) {
                return;
            }
            panel.classList.toggle('is-open');
            this.textContent = panel.classList.contains('is-open') ? '-' : '+';
        });
    });
});
</script>

<?php
$page_title = 'Bible Class Book';
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
