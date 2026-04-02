<?php
/**
 * SAFE Script to regenerate SRNs for existing Sunday School members
 * Converts old format to new year-based format: FMC-SYYNN-KM
 * 
 * Features:
 * - Dry run mode (preview changes without applying)
 * - Database backup before changes
 * - Rollback capability
 * - Detailed logging
 * 
 * Usage: 
 * Dry run: php fix_sunday_school_srn_safe.php --dry-run
 * Execute: php fix_sunday_school_srn_safe.php --execute
 * Rollback: php fix_sunday_school_srn_safe.php --rollback
 */

// Parse command line arguments
$dry_run = in_array('--dry-run', $argv ?? []);
$execute = in_array('--execute', $argv ?? []);
$rollback = in_array('--rollback', $argv ?? []);

// Allow execution from command line or browser
if (php_sapi_name() !== 'cli') {
    session_start();
    require_once __DIR__.'/../config/config.php';
    require_once __DIR__.'/../helpers/auth.php';
    require_once __DIR__.'/../helpers/permissions_v2.php';

    // Authentication check for web access
    if (!is_logged_in()) {
        die('Unauthorized. Please login first.');
    }

    // Only super admin can run this script
    $user_id = $_SESSION['user_id'] ?? 0;
    $role_id = $_SESSION['role_id'] ?? 0;
    if ($user_id != 3 && $role_id != 1) {
        die('Forbidden. Only super admin can run this script.');
    }
    
    // Set mode from GET parameters
    $dry_run = isset($_GET['dry_run']);
    $execute = isset($_GET['execute']);
    $rollback = isset($_GET['rollback']);
    
    echo "<pre>";
} else {
    require_once __DIR__.'/../config/config.php';
}

// Determine mode
if ($rollback) {
    $mode = 'ROLLBACK';
} elseif ($execute) {
    $mode = 'EXECUTE';
} else {
    $mode = 'DRY RUN';
    $dry_run = true;
}

echo "===========================================\n";
echo "Sunday School SRN Migration Script (SAFE)\n";
echo "===========================================\n";
echo "Mode: {$mode}\n";
echo "New format: FMC-SYYNN-KM\n";
echo "Where YY = birth year, NN = sequence\n";
echo "===========================================\n\n";

// Rollback functionality
if ($rollback) {
    echo "ROLLBACK MODE\n";
    echo str_repeat("-", 80) . "\n";
    
    // Find latest backup
    $backup_files = glob(__DIR__ . '/srn_backup_*.json');
    if (empty($backup_files)) {
        die("No backup files found. Cannot rollback.\n");
    }
    
    rsort($backup_files);
    $latest_backup = $backup_files[0];
    
    echo "Found backup: " . basename($latest_backup) . "\n";
    echo "Do you want to restore from this backup? (yes/no): ";
    
    if (php_sapi_name() === 'cli') {
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        if (trim(strtolower($line)) !== 'yes') {
            echo "Rollback cancelled.\n";
            exit;
        }
        fclose($handle);
    } else {
        if (!isset($_GET['confirm'])) {
            echo "\n<a href='?rollback&confirm=1'>Click here to confirm rollback</a>\n";
            exit;
        }
    }
    
    $backup_data = json_decode(file_get_contents($latest_backup), true);
    
    echo "\nRestoring {$backup_data['count']} records...\n";
    
    $restored = 0;
    foreach ($backup_data['records'] as $record) {
        $stmt = $conn->prepare("UPDATE sunday_school SET srn = ? WHERE id = ?");
        $stmt->bind_param('si', $record['old_srn'], $record['id']);
        if ($stmt->execute()) {
            $restored++;
            echo "✓ Restored ID {$record['id']}: {$record['new_srn']} → {$record['old_srn']}\n";
        } else {
            echo "✗ Failed to restore ID {$record['id']}: " . $stmt->error . "\n";
        }
        $stmt->close();
    }
    
    echo "\n✓ Rollback completed. Restored {$restored} records.\n";
    exit;
}

// Get all Sunday School records with DOB
$query = "SELECT id, srn, first_name, last_name, dob, church_id 
          FROM sunday_school 
          WHERE dob IS NOT NULL AND dob != '' 
          ORDER BY church_id, dob, id";

$result = $conn->query($query);

if (!$result) {
    die("Error fetching records: " . $conn->error . "\n");
}

$total_records = $result->num_rows;
echo "Found {$total_records} records with DOB\n\n";

if ($total_records == 0) {
    echo "No records to process.\n";
    exit;
}

// Confirmation for execute mode
if ($execute && php_sapi_name() === 'cli') {
    echo "⚠️  WARNING: This will update SRNs for all {$total_records} records.\n";
    echo "A backup will be created before making changes.\n";
    echo "Do you want to continue? (yes/no): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    if (trim(strtolower($line)) !== 'yes') {
        echo "Operation cancelled.\n";
        exit;
    }
    fclose($handle);
    echo "\n";
} elseif ($execute && php_sapi_name() !== 'cli') {
    if (!isset($_GET['confirm'])) {
        echo "⚠️  WARNING: This will update SRNs for all {$total_records} records.\n";
        echo "<a href='?execute&confirm=1' style='color: red; font-weight: bold;'>Click here to confirm and execute</a>\n";
        exit;
    }
}

$changes = [];
$updated = 0;
$skipped = 0;
$errors = 0;

// Group records by church and birth year for sequential numbering
$records_by_year = [];

while ($row = $result->fetch_assoc()) {
    $birth_year = date('Y', strtotime($row['dob']));
    $year_suffix = substr($birth_year, -2);
    $church_id = $row['church_id'];
    
    $key = $church_id . '_' . $year_suffix;
    
    if (!isset($records_by_year[$key])) {
        $records_by_year[$key] = [];
    }
    
    $records_by_year[$key][] = $row;
}

// Get church codes
$churches = [];
$church_result = $conn->query("SELECT id, church_code, circuit_code FROM churches");
while ($church = $church_result->fetch_assoc()) {
    $churches[$church['id']] = $church;
}

echo "Processing records...\n";
echo str_repeat("-", 80) . "\n";

// Process each year group
foreach ($records_by_year as $key => $records) {
    list($church_id, $year_suffix) = explode('_', $key);
    
    if (!isset($churches[$church_id])) {
        echo "Warning: Church ID {$church_id} not found. Skipping year group {$year_suffix}\n";
        $skipped += count($records);
        continue;
    }
    
    $church_code = $churches[$church_id]['church_code'];
    $circuit_code = $churches[$church_id]['circuit_code'];
    $class_code = 'S'; // Sunday School
    
    $sequence = 1;
    
    foreach ($records as $record) {
        $old_srn = $record['srn'];
        $seq_str = str_pad($sequence, 2, '0', STR_PAD_LEFT);
        $new_srn = $church_code . '-' . $class_code . $year_suffix . $seq_str . '-' . $circuit_code;
        
        $name = $record['first_name'] . ' ' . $record['last_name'];
        $dob = $record['dob'];
        
        // Check if SRN already matches new format
        if ($old_srn === $new_srn) {
            if ($dry_run) {
                echo "✓ SKIP: {$name} (DOB: {$dob}) - Already correct: {$new_srn}\n";
            }
            $skipped++;
            $sequence++;
            continue;
        }
        
        // Store change for backup
        $changes[] = [
            'id' => $record['id'],
            'name' => $name,
            'dob' => $dob,
            'old_srn' => $old_srn,
            'new_srn' => $new_srn
        ];
        
        if ($dry_run) {
            echo "→ PREVIEW: {$name} (DOB: {$dob})\n";
            echo "  Old SRN: {$old_srn}\n";
            echo "  New SRN: {$new_srn}\n";
            $updated++;
        } else {
            // Execute the update
            $stmt = $conn->prepare("UPDATE sunday_school SET srn = ? WHERE id = ?");
            $stmt->bind_param('si', $new_srn, $record['id']);
            
            if ($stmt->execute()) {
                echo "✓ UPDATE: {$name} (DOB: {$dob})\n";
                echo "  Old SRN: {$old_srn}\n";
                echo "  New SRN: {$new_srn}\n";
                $updated++;
            } else {
                echo "✗ ERROR: {$name} (DOB: {$dob}) - " . $stmt->error . "\n";
                $errors++;
            }
            
            $stmt->close();
        }
        
        $sequence++;
    }
}

echo str_repeat("-", 80) . "\n";
echo "\n===========================================\n";
echo "Migration Summary ({$mode})\n";
echo "===========================================\n";
echo "Total records found:  {$total_records}\n";
echo "Records to update:    {$updated}\n";
echo "Skipped (no change):  {$skipped}\n";
echo "Errors:               {$errors}\n";
echo "===========================================\n\n";

if ($dry_run) {
    echo "ℹ️  This was a DRY RUN. No changes were made to the database.\n";
    echo "To execute the changes, run with --execute flag:\n";
    if (php_sapi_name() === 'cli') {
        echo "php fix_sunday_school_srn_safe.php --execute\n\n";
    } else {
        echo "<a href='?execute'>Click here to execute changes</a>\n\n";
    }
} else {
    // Save backup
    $backup_file = __DIR__ . '/srn_backup_' . date('Y-m-d_H-i-s') . '.json';
    $backup_data = [
        'timestamp' => date('Y-m-d H:i:s'),
        'count' => count($changes),
        'records' => $changes
    ];
    file_put_contents($backup_file, json_encode($backup_data, JSON_PRETTY_PRINT));
    echo "✓ Backup saved to: " . basename($backup_file) . "\n";
    echo "To rollback, run: php fix_sunday_school_srn_safe.php --rollback\n\n";
}

// Save detailed log
$log_file = __DIR__ . '/srn_migration_log_' . date('Y-m-d_H-i-s') . '.json';
file_put_contents($log_file, json_encode($changes, JSON_PRETTY_PRINT));
echo "Detailed log saved to: " . basename($log_file) . "\n\n";

// Show sample of new SRNs by year
echo "Sample SRNs by Birth Year:\n";
echo str_repeat("-", 80) . "\n";

$sample_query = "SELECT DISTINCT YEAR(dob) as birth_year, 
                 (SELECT srn FROM sunday_school WHERE YEAR(dob) = birth_year LIMIT 1) as sample_srn
                 FROM sunday_school 
                 WHERE dob IS NOT NULL 
                 ORDER BY birth_year DESC 
                 LIMIT 10";

$sample_result = $conn->query($sample_query);
while ($sample = $sample_result->fetch_assoc()) {
    echo "Birth Year {$sample['birth_year']}: {$sample['sample_srn']}\n";
}

echo "\n✓ Script completed successfully!\n";

if (php_sapi_name() !== 'cli') {
    echo "</pre>";
}
