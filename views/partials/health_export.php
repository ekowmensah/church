<?php
// Expects these variables from including scope:
// $id, $recorded_at, $vitals, $notes, $recorded_by

header('Content-Type: text/csv');
header('Content-Disposition: attachment;filename=health_record_' . intval($id) . '.csv');

$fields = [
    'Date/Time',
    'Weight (Kg)',
    'Temperature (C)',
    'BP (MMHG)',
    'BP Status',
    'Sugar (mmol/L)',
    'Sugar Status',
    'Hep B',
    'Malaria',
    'Notes',
    'Recorded By'
];

$out = fopen('php://output', 'w');
fputcsv($out, $fields);

$row = [
    $recorded_at,
    $vitals['weight'] ?? '',
    $vitals['temperature'] ?? '',
    $vitals['bp'] ?? '',
    $vitals['bp_status'] ?? '',
    $vitals['sugar'] ?? '',
    $vitals['sugar_status'] ?? '',
    $vitals['hepatitis_b'] ?? '',
    $vitals['malaria'] ?? '',
    $notes,
    $recorded_by
];

fputcsv($out, $row);
fclose($out);
