<?php
/**
 * Script to regenerate SRNs for existing Sunday School members
 * Converts old format to new year-based format: FMC-SYYNN-KM
 * 
 * Usage: Run from command line or browser (with admin access)
 * php fix_sunday_school_srn.php
 */

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
    
    echo "<pre>";
} else {
    require_once __DIR__.'/../config/config.php';
}

echo "===========================================\n";
echo "Sunday School SRN Migration Script\n";
echo "===========================================\n";
echo "Converting to new format: FMC-SYYNN-KM\n";
echo "Where YY = birth year, NN = sequence\n";
echo "===========================================\n\n";

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

// Ask for confirmation in CLI mode
if (php_sapi_name() === 'cli') {
    echo "This will update SRNs for all {$total_records} records.\n";
    echo "Do you want to continue? (yes/no): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    if (trim(strtolower($line)) !== 'yes') {
        echo "Operation cancelled.\n";
        exit;
    }
    fclose($handle);
    echo "\n";
}

$updated = 0;
$skipped = 0;
$errors = 0;
$log = [];

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
            echo "✓ SKIP: {$name} (DOB: {$dob}) - Already has correct SRN: {$new_srn}\n";
            $skipped++;
            $sequence++;
            continue;
        }
        
        // Update the SRN
        $stmt = $conn->prepare("UPDATE sunday_school SET srn = ? WHERE id = ?");
        $stmt->bind_param('si', $new_srn, $record['id']);
        
        if ($stmt->execute()) {
            echo "✓ UPDATE: {$name} (DOB: {$dob})\n";
            echo "  Old SRN: {$old_srn}\n";
            echo "  New SRN: {$new_srn}\n";
            $updated++;
            
            $log[] = [
                'id' => $record['id'],
                'name' => $name,
                'dob' => $dob,
                'old_srn' => $old_srn,
                'new_srn' => $new_srn,
                'status' => 'success'
            ];
        } else {
            echo "✗ ERROR: {$name} (DOB: {$dob}) - " . $stmt->error . "\n";
            $errors++;
            
            $log[] = [
                'id' => $record['id'],
                'name' => $name,
                'dob' => $dob,
                'old_srn' => $old_srn,
                'new_srn' => $new_srn,
                'status' => 'error',
                'error' => $stmt->error
            ];
        }
        
        $stmt->close();
        $sequence++;
    }
}

echo str_repeat("-", 80) . "\n";
echo "\n===========================================\n";
echo "Migration Summary\n";
echo "===========================================\n";
echo "Total records found:  {$total_records}\n";
echo "Successfully updated: {$updated}\n";
echo "Skipped (no change):  {$skipped}\n";
echo "Errors:               {$errors}\n";
echo "===========================================\n\n";

// Save log to file
$log_file = __DIR__ . '/srn_migration_log_' . date('Y-m-d_H-i-s') . '.json';
file_put_contents($log_file, json_encode($log, JSON_PRETTY_PRINT));
echo "Detailed log saved to: {$log_file}\n\n";

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

echo "\n✓ Migration completed successfully!\n";

if (php_sapi_name() !== 'cli') {
    echo "</pre>";
}
