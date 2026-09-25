<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/payment_report_context.php';

function expect_phase_0027(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

[$preset, $from, $to] = payment_report_resolve_period('q4', '', '');
expect_phase_0027($preset === 'q4', 'Quarter preset was not retained.');
expect_phase_0027(substr($from, 5) === '10-01' && substr($to, 5) === '12-31', 'Fourth-quarter boundaries are incorrect.');
[$preset, $from, $to] = payment_report_resolve_period('custom', '2026-12-31', '2026-01-01');
expect_phase_0027($from === '2026-01-01' && $to === '2026-12-31', 'Reversed custom dates were not normalized.');

$organizations = payment_report_organizations_expression('m');
$zeroSql = "SELECT pt.name AS payment_type, m.crn, m.phone,
                   bible_class.name AS class_name, $organizations AS organizations
            FROM members m
            CROSS JOIN payment_types pt
            LEFT JOIN bible_classes bible_class ON bible_class.id = m.class_id
            WHERE m.status = 'active' AND m.is_archived = 0 AND pt.active = 1
              AND NOT EXISTS (
                  SELECT 1 FROM v_posted_payments p
                  WHERE p.member_id = m.id AND p.payment_type_id = pt.id
                    AND p.payment_date BETWEEN '2026-01-01' AND '2026-12-31'
              )
            LIMIT 1";
$zeroResult = $conn->query($zeroSql);
expect_phase_0027($zeroResult !== false, 'Zero-payment contextual query failed.');

$accumulatedSql = "SELECT m.id, m.crn, bible_class.name AS class_name,
                          $organizations AS organizations,
                          pt.name AS payment_type, SUM(p.amount) AS total_amount
                   FROM v_posted_payments p
                   JOIN members m ON m.id = p.member_id
                   LEFT JOIN bible_classes bible_class ON bible_class.id = m.class_id
                   LEFT JOIN payment_types pt ON pt.id = p.payment_type_id
                   WHERE m.status = 'active' AND m.is_archived = 0
                   GROUP BY m.id, m.crn, bible_class.name, pt.id, pt.name
                   LIMIT 1";
$accumulatedResult = $conn->query($accumulatedSql);
expect_phase_0027($accumulatedResult !== false, 'Accumulated member-payment query failed.');

$individualSql = "SELECT m.id, m.crn, m.phone, bible_class.name AS class_name,
                         pt.name AS payment_type, p.amount, p.payment_date,
                         COALESCE(NULLIF(p.reporting_period_label, ''),
                                  NULLIF(p.payment_period_description, ''),
                                  DATE_FORMAT(COALESCE(p.payment_period, p.payment_date), '%M %Y')) AS reporting_period
                  FROM members m
                  JOIN v_posted_payments p ON p.member_id = m.id
                  LEFT JOIN bible_classes bible_class ON bible_class.id = m.class_id
                  LEFT JOIN payment_types pt ON pt.id = p.payment_type_id
                  WHERE m.status = 'active' AND m.is_archived = 0
                  LIMIT 1";
$individualResult = $conn->query($individualSql);
expect_phase_0027($individualResult !== false, 'Individual-statement query failed.');

$historyCount = (int) ($conn->query('SELECT COUNT(*) AS total FROM v_cashier_denomination_history')->fetch_assoc()['total'] ?? 0);
$analysisCount = (int) ($conn->query('SELECT COUNT(*) AS total FROM payment_analyses')->fetch_assoc()['total'] ?? 0);
expect_phase_0027($historyCount === $analysisCount, 'Cashier history view omitted saved analyses.');

$mismatchCount = (int) ($conn->query(
    "SELECT COUNT(*) AS total FROM payment_analyses"
    . " WHERE payment_mode = 'cash'"
    . " AND ABS(COALESCE(denomination_total, 0) - notes_total - coins_total) > 0.01"
)->fetch_assoc()['total'] ?? 0);
expect_phase_0027($mismatchCount === 0, 'Notes and coins do not reconcile to denomination totals.');

$analysisEndpoint = file_get_contents(__DIR__ . '/../views/ajax_payment_analysis.php');
$statisticsPage = file_get_contents(__DIR__ . '/../views/payments_statistics.php');
expect_phase_0027(
    strpos($analysisEndpoint, "csrf_is_valid(\$_POST['csrf_token'] ?? null)") !== false,
    'Denomination mutations are missing CSRF validation.'
);
expect_phase_0027(
    strpos($statisticsPage, "formData.append('csrf_token'") !== false,
    'Denomination saves do not send the CSRF token.'
);

echo "PASS: Phase 0027 denomination history and contextual payment reports are operational.\n";
