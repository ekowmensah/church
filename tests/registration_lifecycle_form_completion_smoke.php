<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/member_transfer_origin.php';
require_once __DIR__ . '/../services/MemberLifecycleService.php';

function expect_phase_0026(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$disabled = member_transfer_origin_from_input([
    'transfer_diocese' => 'Must not persist',
]);
expect_phase_0026($disabled['transfer_from_other_chapel'] === 0, 'Disabled transfer flag was not normalized.');
expect_phase_0026($disabled['transfer_diocese'] === '', 'Disabled transfer details must be cleared.');

$enabled = member_transfer_origin_from_input([
    'transfer_from_other_chapel' => '1',
    'transfer_diocese' => '  Accra Diocese  ',
    'transfer_circuit' => 'North Circuit',
    'transfer_society' => 'Grace Society',
    'removal_note_provided' => '1',
    'superintendent_name' => 'Very Rev. Example',
]);
expect_phase_0026(member_transfer_origin_validation_error($enabled) === '', 'Complete transfer details failed validation.');
expect_phase_0026($enabled['transfer_diocese'] === 'Accra Diocese', 'Transfer strings were not trimmed.');
expect_phase_0026($enabled['removal_note_provided'] === 1, 'Removal-note evidence was not normalized.');

$missing = $enabled;
$missing['transfer_circuit'] = '';
expect_phase_0026(
    str_contains(member_transfer_origin_validation_error($missing), 'Circuit'),
    'Incomplete transfer details were not rejected.'
);

$reasonOptions = MemberLifecycleService::lifecycleReasonOptions();
expect_phase_0026(array_keys($reasonOptions) === [
    'deceased', 'unknown', 'exited', 'duplicate_record'
], 'Lifecycle reasons do not match the approved four-value list.');

$service = new MemberLifecycleService($conn, null, null, true);
$normalizer = new ReflectionMethod(MemberLifecycleService::class, 'normalizeLifecycleReason');
$normalizer->setAccessible(true);
[$reasonCode, $reasonText] = $normalizer->invoke($service, 'duplicate_record', 'Same person entered twice.');
expect_phase_0026($reasonCode === 'duplicate_record', 'Structured reason code changed unexpectedly.');
expect_phase_0026(
    str_starts_with($reasonText, 'Duplicate Record'),
    'Readable lifecycle reason does not retain its approved label.'
);

$invalidRejected = false;
try {
    $normalizer->invoke($service, 'free_text_only', 'Not allowed');
} catch (Throwable $e) {
    $invalidRejected = true;
}
expect_phase_0026($invalidRejected, 'Unapproved lifecycle reason was accepted.');

$requiredColumns = [
    'deactivation_reason_code', 'archive_reason_code', 'transfer_from_other_chapel',
    'transfer_diocese', 'transfer_circuit', 'transfer_society',
    'removal_note_provided', 'superintendent_name',
];
$quotedColumns = implode(',', array_map(static fn(string $name): string => "'" . $name . "'", $requiredColumns));
$columnCount = (int) ($conn->query(
    "SELECT COUNT(*) AS total FROM information_schema.COLUMNS"
    . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members'"
    . " AND COLUMN_NAME IN ($quotedColumns)"
)->fetch_assoc()['total'] ?? 0);
expect_phase_0026($columnCount === count($requiredColumns), 'Phase 0026 member columns are incomplete.');

echo "PASS: Phase 0026 transfer capture and structured lifecycle reasons are operational.\n";
