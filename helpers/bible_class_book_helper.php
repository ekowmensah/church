<?php
/**
 * Bible Class Book helper/service functions.
 * Builds quarterly class-book data from existing attendance + payments.
 */

function bcb_scope_columns_available($conn) {
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    $sql = "SELECT COUNT(*) AS cnt
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'attendance_sessions'
              AND COLUMN_NAME IN ('attendance_scope', 'scope_id')";
    $res = $conn->query($sql);
    $row = $res ? $res->fetch_assoc() : null;
    $available = ($row && intval($row['cnt']) === 2);
    return $available;
}

function bcb_book_tables_available($conn) {
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    $sql = "SELECT COUNT(*) AS cnt
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME IN ('bible_class_books', 'bible_class_book_entries', 'bible_class_book_payment_type_map')";
    $res = $conn->query($sql);
    $row = $res ? $res->fetch_assoc() : null;
    $available = ($row && intval($row['cnt']) === 3);
    return $available;
}

function bcb_payment_reversal_columns_available($conn) {
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    $sql = "SELECT COUNT(*) AS cnt
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'payments'
              AND COLUMN_NAME IN ('reversal_approved_at', 'reversal_undone_at')";
    $res = $conn->query($sql);
    $row = $res ? $res->fetch_assoc() : null;
    $available = ($row && intval($row['cnt']) === 2);
    return $available;
}

function bcb_quarter_from_month($month) {
    $month = intval($month);
    if ($month >= 1 && $month <= 3) return 1;
    if ($month >= 4 && $month <= 6) return 2;
    if ($month >= 7 && $month <= 9) return 3;
    return 4;
}

function bcb_quarter_months($quarter) {
    $quarter = intval($quarter);
    if ($quarter === 1) return [1, 2, 3];
    if ($quarter === 2) return [4, 5, 6];
    if ($quarter === 3) return [7, 8, 9];
    return [10, 11, 12];
}

function bcb_quarter_range($year, $quarter) {
    $year = intval($year);
    $months = bcb_quarter_months($quarter);
    $startDate = sprintf('%04d-%02d-01', $year, $months[0]);
    $endDate = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $months[2])));
    return [$startDate, $endDate];
}

function bcb_member_status_code($membershipStatus) {
    $s = strtolower(trim((string)$membershipStatus));
    if ($s === 'full member') return 'FM';
    if ($s === 'catechumen') return 'CAT';
    if ($s === 'adherent') return 'AD';
    if ($s === 'juvenile') return 'JUV';
    if ($s === 'invalid distant member') return 'IDM';
    return '--';
}

function bcb_attendance_code_from_status($status) {
    $s = strtolower(trim((string)$status));
    if ($s === 'present') return 'P';
    if ($s === 'absent') return 'A';
    if ($s === 'sick') return 'S';
    if ($s === 'permission') return 'B';
    if ($s === 'distance') return 'D';
    if ($s === 'invalid') return 'I';
    return '';
}

function bcb_safe_member_dob($dob) {
    if (!$dob || $dob === '0000-00-00') {
        return null;
    }
    $ts = strtotime($dob);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d', $ts);
}

function bcb_fetch_class_members($conn, $classId) {
    $sql = "SELECT id, crn, first_name, middle_name, last_name, dob, marital_status, phone, profession, membership_status
            FROM members
            WHERE class_id = ? AND status = 'active'
            ORDER BY last_name, first_name, middle_name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $classId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $fullName = trim(implode(' ', array_filter([
            $row['first_name'] ?? '',
            $row['middle_name'] ?? '',
            $row['last_name'] ?? ''
        ])));
        $row['full_name'] = $fullName;
        $row['dob'] = bcb_safe_member_dob($row['dob'] ?? null);
        $row['member_status_code'] = bcb_member_status_code($row['membership_status'] ?? '');
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function bcb_resolve_payment_type_ids($conn, $churchId, $classId) {
    $ids = [];

    if (bcb_book_tables_available($conn)) {
        // Class-specific mappings first
        $sqlClass = "SELECT payment_type_id
                     FROM bible_class_book_payment_type_map
                     WHERE church_id = ? AND class_id = ? AND is_active = 1";
        $stmt = $conn->prepare($sqlClass);
        $stmt->bind_param('ii', $churchId, $classId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $ids[] = intval($row['payment_type_id']);
        }
        $stmt->close();

        if (count($ids) === 0) {
            // Church-level defaults
            $sqlChurch = "SELECT payment_type_id
                          FROM bible_class_book_payment_type_map
                          WHERE church_id = ? AND class_id IS NULL AND is_active = 1";
            $stmt = $conn->prepare($sqlChurch);
            $stmt->bind_param('i', $churchId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $ids[] = intval($row['payment_type_id']);
            }
            $stmt->close();
        }
    }

    if (count($ids) === 0) {
        // Fallback: TITHE payment type
        $res = $conn->query("SELECT id FROM payment_types WHERE UPPER(TRIM(name)) = 'TITHE' LIMIT 1");
        if ($res && ($row = $res->fetch_assoc())) {
            $ids[] = intval($row['id']);
        }
    }

    $ids = array_values(array_unique(array_filter($ids, function ($v) {
        return intval($v) > 0;
    })));

    return $ids;
}

function bcb_fetch_quarter_session_dates($conn, $classId, $startDate, $endDate) {
    // Use actual attendance records for this class to avoid wrong church-wide sessions.
    $sql = "SELECT DISTINCT s.service_date
            FROM attendance_records r
            INNER JOIN attendance_sessions s ON s.id = r.session_id
            INNER JOIN members m ON m.id = r.member_id
            WHERE m.class_id = ?
              AND s.service_date BETWEEN ? AND ?
              AND s.service_date <> '0000-00-00'
            ORDER BY s.service_date ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iss', $classId, $startDate, $endDate);
    $stmt->execute();
    $res = $stmt->get_result();

    $dates = [];
    while ($row = $res->fetch_assoc()) {
        $d = $row['service_date'];
        if ($d) {
            $dates[] = $d;
        }
    }
    $stmt->close();

    return $dates;
}

function bcb_build_quarter_slots($year, $quarter, $attendanceDates) {
    $months = bcb_quarter_months($quarter);
    $slots = [];
    $slotsByMonth = [];
    foreach ($months as $m) {
        $slotsByMonth[$m] = [];
    }

    $monthDateMap = [];
    foreach ($attendanceDates as $d) {
        $month = intval(date('n', strtotime($d)));
        if (!isset($monthDateMap[$month])) {
            $monthDateMap[$month] = [];
        }
        $monthDateMap[$month][] = $d;
    }

    foreach ($months as $m) {
        $dates = $monthDateMap[$m] ?? [];
        sort($dates);
        $dates = array_slice($dates, 0, 5); // Workbook style: up to 5 weekly columns per month

        $weekNo = 1;
        foreach ($dates as $d) {
            $slotKey = date('Y-m-d', strtotime($d));
            $slot = [
                'slot_key' => $slotKey,
                'month_no' => $m,
                'month_label' => date('F', strtotime($slotKey)),
                'week_no_in_month' => $weekNo,
                'date_label' => date('jS', strtotime($slotKey))
            ];
            $slots[] = $slot;
            $slotsByMonth[$m][] = $slot;
            $weekNo++;
        }
    }

    return [$slots, $slotsByMonth];
}

function bcb_fetch_attendance_map($conn, $classId, $startDate, $endDate) {
    $sql = "SELECT r.member_id, s.service_date, r.status
            FROM attendance_records r
            INNER JOIN attendance_sessions s ON s.id = r.session_id
            INNER JOIN members m ON m.id = r.member_id
            WHERE m.class_id = ?
              AND s.service_date BETWEEN ? AND ?
              AND s.service_date <> '0000-00-00'
            ORDER BY r.updated_at DESC, r.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iss', $classId, $startDate, $endDate);
    $stmt->execute();
    $res = $stmt->get_result();

    $map = [];
    while ($row = $res->fetch_assoc()) {
        $key = intval($row['member_id']) . '|' . $row['service_date'];
        if (!isset($map[$key])) {
            $status = strtolower(trim((string)$row['status']));
            $map[$key] = [
                'status' => $status,
                'code' => bcb_attendance_code_from_status($status)
            ];
        }
    }
    $stmt->close();
    return $map;
}

function bcb_fetch_payment_map($conn, $classId, $startDate, $endDate, $paymentTypeIds) {
    if (count($paymentTypeIds) === 0) {
        return [];
    }

    $inPlaceholders = implode(',', array_fill(0, count($paymentTypeIds), '?'));
    $sql = "SELECT p.member_id, DATE(p.payment_date) AS payment_day,
                   SUM(p.amount) AS total_amount,
                   COUNT(*) AS payment_count
            FROM payments p
            INNER JOIN members m ON m.id = p.member_id
            WHERE m.class_id = ?
              AND DATE(p.payment_date) BETWEEN ? AND ?
              AND p.payment_type_id IN ($inPlaceholders)";

    if (bcb_payment_reversal_columns_available($conn)) {
        $sql .= " AND (p.reversal_approved_at IS NULL OR p.reversal_undone_at IS NOT NULL)";
    }

    $sql .= "
            GROUP BY p.member_id, DATE(p.payment_date)";

    $stmt = $conn->prepare($sql);
    $types = 'iss' . str_repeat('i', count($paymentTypeIds));
    $params = array_merge([$classId, $startDate, $endDate], $paymentTypeIds);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $map = [];
    while ($row = $res->fetch_assoc()) {
        $key = intval($row['member_id']) . '|' . $row['payment_day'];
        $map[$key] = [
            'amount' => floatval($row['total_amount'] ?? 0),
            'count' => intval($row['payment_count'] ?? 0)
        ];
    }
    $stmt->close();
    return $map;
}

function bcb_build_quarter_book_data($conn, $churchId, $classId, $year, $quarter) {
    list($startDate, $endDate) = bcb_quarter_range($year, $quarter);
    $members = bcb_fetch_class_members($conn, $classId);

    $sessionDates = bcb_fetch_quarter_session_dates($conn, $classId, $startDate, $endDate);
    list($slots, $slotsByMonth) = bcb_build_quarter_slots($year, $quarter, $sessionDates);

    $attendanceMap = bcb_fetch_attendance_map($conn, $classId, $startDate, $endDate);
    $paymentTypeIds = bcb_resolve_payment_type_ids($conn, $churchId, $classId);
    $paymentMap = bcb_fetch_payment_map($conn, $classId, $startDate, $endDate, $paymentTypeIds);

    $rows = [];
    $presentBySlot = [];
    $amountBySlot = [];
    foreach ($slots as $slot) {
        $presentBySlot[$slot['slot_key']] = 0;
        $amountBySlot[$slot['slot_key']] = 0.0;
    }

    foreach ($members as $member) {
        $row = [
            'member' => $member,
            'slots' => [],
            'total_amount' => 0.0,
            'present_count' => 0
        ];

        foreach ($slots as $slot) {
            $slotKey = $slot['slot_key'];
            $mk = intval($member['id']) . '|' . $slotKey;

            $attendanceStatus = $attendanceMap[$mk]['status'] ?? null;
            $attendanceCode = $attendanceMap[$mk]['code'] ?? '';
            $payAmount = floatval($paymentMap[$mk]['amount'] ?? 0.0);
            $payCount = intval($paymentMap[$mk]['count'] ?? 0);

            if ($attendanceCode === 'P') {
                $row['present_count']++;
                $presentBySlot[$slotKey]++;
            }

            if ($payAmount > 0) {
                $row['total_amount'] += $payAmount;
                $amountBySlot[$slotKey] += $payAmount;
            }

            $row['slots'][$slotKey] = [
                'attendance_status' => $attendanceStatus,
                'attendance_code' => $attendanceCode,
                'payment_amount' => $payAmount,
                'payment_count' => $payCount
            ];
        }

        $rows[] = $row;
    }

    $totals = [
        'members_count' => count($members),
        'quarter_total_amount' => array_sum(array_map(function ($r) {
            return $r['total_amount'];
        }, $rows)),
        'present_by_slot' => $presentBySlot,
        'amount_by_slot' => $amountBySlot
    ];

    return [
        'year' => intval($year),
        'quarter' => intval($quarter),
        'start_date' => $startDate,
        'end_date' => $endDate,
        'slots' => $slots,
        'slots_by_month' => $slotsByMonth,
        'rows' => $rows,
        'totals' => $totals,
        'payment_type_ids' => $paymentTypeIds
    ];
}

function bcb_upsert_snapshot($conn, $churchId, $classId, $year, $quarter, $userId, $bookData) {
    if (!bcb_book_tables_available($conn)) {
        return ['success' => false, 'message' => 'Bible Class Book tables are not available. Run migration first.'];
    }

    $bookTitle = 'Bible Class Book - Q' . intval($quarter) . ' ' . intval($year);
    $stmt = $conn->prepare("
        INSERT INTO bible_class_books (church_id, class_id, book_year, book_quarter, status, title, created_by)
        VALUES (?, ?, ?, ?, 'draft', ?, ?)
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->bind_param('iiiisi', $churchId, $classId, $year, $quarter, $bookTitle, $userId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("SELECT id FROM bible_class_books WHERE church_id = ? AND class_id = ? AND book_year = ? AND book_quarter = ? LIMIT 1");
    $stmt->bind_param('iiii', $churchId, $classId, $year, $quarter);
    $stmt->execute();
    $bookRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$bookRow) {
        return ['success' => false, 'message' => 'Unable to locate or create class book header.'];
    }

    $bookId = intval($bookRow['id']);

    $del = $conn->prepare("DELETE FROM bible_class_book_entries WHERE book_id = ?");
    $del->bind_param('i', $bookId);
    $del->execute();
    $del->close();

    $ins = $conn->prepare("
        INSERT INTO bible_class_book_entries
        (book_id, member_id, week_start_date, month_no, week_no_in_month, attendance_status, attendance_code, payment_amount, payment_count, is_manual_override, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL)
    ");

    foreach ($bookData['rows'] as $row) {
        $memberId = intval($row['member']['id']);
        foreach ($bookData['slots'] as $slot) {
            $slotKey = $slot['slot_key'];
            $cell = $row['slots'][$slotKey] ?? [];
            $attendanceStatus = $cell['attendance_status'] ?? null;
            $attendanceCode = $cell['attendance_code'] ?? null;
            $paymentAmount = floatval($cell['payment_amount'] ?? 0);
            $paymentCount = intval($cell['payment_count'] ?? 0);
            $monthNo = intval($slot['month_no']);
            $weekNo = intval($slot['week_no_in_month']);

            $ins->bind_param(
                'iisiissdi',
                $bookId,
                $memberId,
                $slotKey,
                $monthNo,
                $weekNo,
                $attendanceStatus,
                $attendanceCode,
                $paymentAmount,
                $paymentCount
            );
            $ins->execute();
        }
    }
    $ins->close();

    return ['success' => true, 'book_id' => $bookId, 'message' => 'Snapshot refreshed successfully.'];
}
