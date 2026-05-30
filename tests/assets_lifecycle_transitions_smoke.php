<?php
require_once __DIR__ . '/../helpers/asset_register_helper.php';

$cases = [
    ['from' => 'requested', 'to' => 'approved', 'expected' => true],
    ['from' => 'approved', 'to' => 'procured', 'expected' => true],
    ['from' => 'procured', 'to' => 'in_use', 'expected' => true],
    ['from' => 'in_use', 'to' => 'disposed', 'expected' => true],
    ['from' => 'disposed', 'to' => 'in_use', 'expected' => false],
    ['from' => 'requested', 'to' => 'in_use', 'expected' => false],
];

$failed = [];
foreach ($cases as $case) {
    $actual = asset_validate_lifecycle_transition($case['from'], $case['to']);
    if ($actual !== $case['expected']) {
        $failed[] = $case;
    }
}

if (!empty($failed)) {
    fwrite(STDERR, "Lifecycle transition smoke test failed.\n");
    foreach ($failed as $f) {
        fwrite(STDERR, sprintf("from=%s to=%s expected=%s\n", $f['from'], $f['to'], $f['expected'] ? 'true' : 'false'));
    }
    exit(1);
}

echo "Lifecycle transition smoke test passed.\n";
exit(0);

