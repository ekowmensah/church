<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    $periods = [];
    
    // Current month (default)
    $currentDate = date('Y-m-01');
    $currentDisplay = date('F Y');
    $periods[] = [
        'id' => $currentDate,
        'name' => $currentDisplay . ' (Current)',
        'default' => true
    ];
    
    // Previous months
    for ($i = 1; $i < 12; $i++) {
        $date = date('Y-m-01', strtotime("-$i months"));
        $display = date('F Y', strtotime($date));
        $periods[] = [
            'id' => $date,
            'name' => $display,
            'default' => false
        ];
    }
    
    // Next month (for advance payments)
    $nextDate = date('Y-m-01', strtotime("+1 month"));
    $nextDisplay = date('F Y', strtotime($nextDate));
    $periods[] = [
        'id' => $nextDate,
        'name' => $nextDisplay . ' (Advance)',
        'default' => false
    ];
    
    echo json_encode($periods);
    
} catch (Exception $e) {
    error_log("Payment periods error: " . $e->getMessage());
    echo json_encode([
        'error' => 'Failed to load payment periods'
    ]);
}
