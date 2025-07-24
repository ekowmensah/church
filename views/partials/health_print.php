<?php
/**
 * Health Record Print Template
 * Generates a printable version of a single health record
 */

// Ensure we have the necessary data
if (!isset($row) || !isset($vitals)) {
    die('Invalid print request - missing health record data');
}

// Set content type for proper printing
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health Record - <?= htmlspecialchars($person_name) ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: white;
            color: black;
            font-size: 12px;
        }
        
        .print-header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        
        .print-header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: bold;
        }
        
        .print-header h2 {
            margin: 10px 0 0 0;
            font-size: 18px;
            font-weight: normal;
        }
        
        .patient-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            border: 1px solid #000;
            padding: 15px;
        }
        
        .patient-info div {
            flex: 1;
        }
        
        .vital-signs {
            margin-bottom: 30px;
        }
        
        .vital-signs h3 {
            border-bottom: 1px solid #000;
            padding-bottom: 5px;
            margin-bottom: 15px;
        }
        
        .vitals-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .vital-item {
            border: 1px solid #ccc;
            padding: 10px;
        }
        
        .vital-label {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .vital-value {
            font-size: 14px;
        }
        
        .test-results {
            margin-bottom: 30px;
        }
        
        .test-results h3 {
            border-bottom: 1px solid #000;
            padding-bottom: 5px;
            margin-bottom: 15px;
        }
        
        .test-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        .test-item {
            border: 1px solid #ccc;
            padding: 10px;
            display: flex;
            justify-content: space-between;
        }
        
        .notes-section {
            margin-bottom: 30px;
        }
        
        .notes-section h3 {
            border-bottom: 1px solid #000;
            padding-bottom: 5px;
            margin-bottom: 15px;
        }
        
        .notes-content {
            border: 1px solid #ccc;
            padding: 15px;
            min-height: 100px;
        }
        
        .print-footer {
            margin-top: 50px;
            border-top: 1px solid #000;
            padding-top: 20px;
            display: flex;
            justify-content: space-between;
        }
        
        @media print {
            body { margin: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="print-header">
        <h1>HEALTH RECORD</h1>
        <h2><?= htmlspecialchars($church_name ?? 'Church Management System') ?></h2>
    </div>
    
    <div class="patient-info">
        <div>
            <strong>Patient Name:</strong> <?= htmlspecialchars($person_name) ?><br>
            <strong>ID:</strong> <?= htmlspecialchars($person_id) ?><br>
            <strong>Type:</strong> <?= htmlspecialchars($person_type) ?>
        </div>
        <div>
            <strong>Church:</strong> <?= htmlspecialchars($church_name) ?><br>
            <strong>Class:</strong> <?= htmlspecialchars($class_name) ?><br>
            <strong>Phone:</strong> <?= htmlspecialchars($person_phone) ?>
        </div>
        <div>
            <strong>Record Date:</strong> <?= date('F j, Y', strtotime($recorded_at)) ?><br>
            <strong>Record Time:</strong> <?= date('g:i A', strtotime($recorded_at)) ?><br>
            <strong>Recorded By:</strong> <?= htmlspecialchars($recorded_by) ?>
        </div>
    </div>
    
    <div class="vital-signs">
        <h3>VITAL SIGNS</h3>
        <div class="vitals-grid">
            <?php if (!empty($vitals['weight'])): ?>
            <div class="vital-item">
                <div class="vital-label">Weight</div>
                <div class="vital-value"><?= htmlspecialchars($vitals['weight']) ?> kg</div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($vitals['temperature'])): ?>
            <div class="vital-item">
                <div class="vital-label">Temperature</div>
                <div class="vital-value"><?= htmlspecialchars($vitals['temperature']) ?> °C</div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($vitals['bp'])): ?>
            <div class="vital-item">
                <div class="vital-label">Blood Pressure</div>
                <div class="vital-value">
                    <?= htmlspecialchars($vitals['bp']) ?> mmHg
                    <?php if (!empty($vitals['bp_status'])): ?>
                    <br><small>(<?= htmlspecialchars($vitals['bp_status']) ?>)</small>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($vitals['sugar'])): ?>
            <div class="vital-item">
                <div class="vital-label">Blood Sugar</div>
                <div class="vital-value">
                    <?= htmlspecialchars($vitals['sugar']) ?> mmol/L
                    <?php if (!empty($vitals['sugar_status'])): ?>
                    <br><small>(<?= htmlspecialchars($vitals['sugar_status']) ?>)</small>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if (!empty($vitals['hepatitis_b']) || !empty($vitals['malaria'])): ?>
    <div class="test-results">
        <h3>TEST RESULTS</h3>
        <div class="test-grid">
            <?php if (!empty($vitals['hepatitis_b'])): ?>
            <div class="test-item">
                <span>Hepatitis B Test:</span>
                <strong><?= htmlspecialchars($vitals['hepatitis_b']) ?></strong>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($vitals['malaria'])): ?>
            <div class="test-item">
                <span>Malaria Test:</span>
                <strong><?= htmlspecialchars($vitals['malaria']) ?></strong>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="notes-section">
        <h3>MEDICAL NOTES</h3>
        <div class="notes-content">
            <?= nl2br(htmlspecialchars($notes ?: 'No notes recorded.')) ?>
        </div>
    </div>
    
    <div class="print-footer">
        <div>
            <strong>Printed:</strong> <?= date('F j, Y \a\t g:i A') ?>
        </div>
        <div>
            <strong>System:</strong> Church Management System
        </div>
    </div>
    
    <script>
        // Auto-print when page loads
        window.onload = function() {
            window.print();
        };
    </script>
</body>
</html>
