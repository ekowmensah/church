<?php
/**
 * RBAC Migration Runner
 * Executes migrations in order and tracks progress
 */

require_once __DIR__ . '/../../config/config.php';

// Check if running from CLI
$isCLI = php_sapi_name() === 'cli';

if (!$isCLI) {
    // Web access - require admin authentication
    session_start();
    require_once __DIR__ . '/../../helpers/auth.php';
    
    if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] != 3) {
        die('Unauthorized. This script can only be run by super admin or via CLI.');
    }
}

class RBACMigrationRunner {
    private $conn;
    private $migrationsDir;
    private $executedBy;
    
    public function __construct($conn) {
        $this->conn = $conn;
        $this->migrationsDir = __DIR__;
        $this->executedBy = $_SESSION['user_id'] ?? 'cli_user';
    }
    
    /**
     * Run all pending migrations
     */
    public function runAll($dryRun = false) {
        $this->log("=== RBAC Migration Runner ===\n");
        $this->log("Dry Run: " . ($dryRun ? 'YES' : 'NO') . "\n");
        
        // Ensure migration tracker exists
        $this->ensureMigrationTracker();
        
        // Get all migration files
        $migrationFiles = $this->getMigrationFiles();
        $this->log("Found " . count($migrationFiles) . " migration files\n\n");
        
        // Get executed migrations
        $executedMigrations = $this->getExecutedMigrations();
        
        $pendingCount = 0;
        foreach ($migrationFiles as $file) {
            $migrationNumber = $this->extractMigrationNumber($file);
            
            if (in_array($migrationNumber, $executedMigrations)) {
                $this->log("[$migrationNumber] SKIPPED (already executed)\n");
                continue;
            }
            
            $pendingCount++;
            $this->log("[$migrationNumber] EXECUTING: $file\n");
            
            if (!$dryRun) {
                $result = $this->executeMigration($file, $migrationNumber);
                if ($result['success']) {
                    $this->log("  ✓ SUCCESS ({$result['time_ms']}ms)\n");
                } else {
                    $this->log("  ✗ FAILED: {$result['error']}\n");
                    $this->log("\nMigration stopped due to error.\n");
                    return false;
                }
            } else {
                $this->log("  (would execute)\n");
            }
        }
        
        if ($pendingCount === 0) {
            $this->log("\nNo pending migrations to execute.\n");
        } else {
            $this->log("\nCompleted $pendingCount migrations.\n");
        }
        
        return true;
    }
    
    /**
     * Rollback last migration
     */
    public function rollbackLast() {
        $this->log("=== Rolling back last migration ===\n");
        
        $stmt = $this->conn->prepare("
            SELECT migration_number, migration_name 
            FROM rbac_migrations 
            WHERE status = 'completed' 
            ORDER BY executed_at DESC 
            LIMIT 1
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($migration = $result->fetch_assoc()) {
            $rollbackFile = $this->migrationsDir . '/rollbacks/' . 
                           $migration['migration_number'] . '_rollback_' . 
                           $migration['migration_name'] . '.sql';
            
            if (file_exists($rollbackFile)) {
                $this->log("Rolling back: {$migration['migration_name']}\n");
                $this->executeRollback($rollbackFile, $migration['migration_number']);
                $this->log("✓ Rollback completed\n");
            } else {
                $this->log("✗ Rollback file not found: $rollbackFile\n");
                return false;
            }
        } else {
            $this->log("No migrations to rollback.\n");
        }
        
        return true;
    }
    
    /**
     * Get migration status
     */
    public function getStatus() {
        $stmt = $this->conn->query("
            SELECT migration_number, migration_name, status, executed_at, execution_time_ms
            FROM rbac_migrations
            ORDER BY migration_number
        ");
        
        $this->log("=== Migration Status ===\n\n");
        $this->log(str_pad("Number", 10) . str_pad("Name", 40) . str_pad("Status", 15) . "Executed At\n");
        $this->log(str_repeat("-", 100) . "\n");
        
        while ($row = $stmt->fetch_assoc()) {
            $this->log(
                str_pad($row['migration_number'], 10) .
                str_pad(substr($row['migration_name'], 0, 38), 40) .
                str_pad($row['status'], 15) .
                ($row['executed_at'] ?? 'N/A') . "\n"
            );
        }
    }
    
    // ===== Private Methods =====
    
    private function ensureMigrationTracker() {
        // Check if table exists
        $result = $this->conn->query("SHOW TABLES LIKE 'rbac_migrations'");
        if ($result->num_rows === 0) {
            $this->log("Creating migration tracker table...\n");
            $sql = file_get_contents($this->migrationsDir . '/000_create_migration_tracker.sql');
            $this->conn->multi_query($sql);
            while ($this->conn->next_result()) {;} // Clear results
        }
    }
    
    private function getMigrationFiles() {
        $files = glob($this->migrationsDir . '/*.sql');
        $migrations = [];
        
        foreach ($files as $file) {
            $filename = basename($file);
            if ($filename !== '000_create_migration_tracker.sql') {
                $migrations[] = $filename;
            }
        }
        
        sort($migrations);
        return $migrations;
    }
    
    private function getExecutedMigrations() {
        $stmt = $this->conn->query("
            SELECT migration_number 
            FROM rbac_migrations 
            WHERE status = 'completed'
        ");
        
        $executed = [];
        while ($row = $stmt->fetch_assoc()) {
            $executed[] = $row['migration_number'];
        }
        
        return $executed;
    }
    
    private function extractMigrationNumber($filename) {
        preg_match('/^(\d+)_/', $filename, $matches);
        return $matches[1] ?? '000';
    }
    
    private function executeMigration($filename, $migrationNumber) {
        $startTime = microtime(true);
        $migrationName = str_replace('.sql', '', str_replace($migrationNumber . '_', '', $filename));
        
        // Mark as running
        $stmt = $this->conn->prepare("
            INSERT INTO rbac_migrations (migration_number, migration_name, status, executed_by)
            VALUES (?, ?, 'running', ?)
            ON DUPLICATE KEY UPDATE status = 'running'
        ");
        $stmt->bind_param('sss', $migrationNumber, $migrationName, $this->executedBy);
        $stmt->execute();
        
        try {
            // Read and execute SQL file
            $sql = file_get_contents($this->migrationsDir . '/' . $filename);
            
            // Execute multi-query
            if ($this->conn->multi_query($sql)) {
                do {
                    if ($result = $this->conn->store_result()) {
                        $result->free();
                    }
                } while ($this->conn->next_result());
            }
            
            if ($this->conn->errno) {
                throw new Exception($this->conn->error);
            }
            
            $executionTime = round((microtime(true) - $startTime) * 1000);
            
            // Mark as completed
            $stmt = $this->conn->prepare("
                UPDATE rbac_migrations 
                SET status = 'completed', execution_time_ms = ?
                WHERE migration_number = ?
            ");
            $stmt->bind_param('is', $executionTime, $migrationNumber);
            $stmt->execute();
            
            return ['success' => true, 'time_ms' => $executionTime];
            
        } catch (Exception $e) {
            // Mark as failed
            $errorMsg = $e->getMessage();
            $stmt = $this->conn->prepare("
                UPDATE rbac_migrations 
                SET status = 'failed', error_message = ?
                WHERE migration_number = ?
            ");
            $stmt->bind_param('ss', $errorMsg, $migrationNumber);
            $stmt->execute();
            
            return ['success' => false, 'error' => $errorMsg];
        }
    }
    
    private function executeRollback($filename, $migrationNumber) {
        $sql = file_get_contents($filename);
        
        if ($this->conn->multi_query($sql)) {
            do {
                if ($result = $this->conn->store_result()) {
                    $result->free();
                }
            } while ($this->conn->more_results() && $this->conn->next_result());
        }
        
        // Clear any remaining results
        while ($this->conn->more_results()) {
            $this->conn->next_result();
        }
        
        // Mark as rolled back
        $stmt = $this->conn->prepare("
            UPDATE rbac_migrations 
            SET status = 'rolled_back', rollback_executed_at = NOW()
            WHERE migration_number = ?
        ");
        $stmt->bind_param('s', $migrationNumber);
        $stmt->execute();
    }
    
    private function log($message) {
        echo $message;
        flush();
    }
}

// ===== CLI Usage =====
if ($isCLI) {
    $command = $argv[1] ?? 'help';
    $runner = new RBACMigrationRunner($conn);
    
    switch ($command) {
        case 'run':
            $dryRun = isset($argv[2]) && $argv[2] === '--dry-run';
            $runner->runAll($dryRun);
            break;
            
        case 'rollback':
            $runner->rollbackLast();
            break;
            
        case 'status':
            $runner->getStatus();
            break;
            
        case 'help':
        default:
            echo "RBAC Migration Runner\n\n";
            echo "Usage:\n";
            echo "  php run_migrations.php run [--dry-run]  Run all pending migrations\n";
            echo "  php run_migrations.php rollback         Rollback last migration\n";
            echo "  php run_migrations.php status           Show migration status\n";
            echo "  php run_migrations.php help             Show this help\n";
            break;
    }
} else {
    // Web interface
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>RBAC Migration Runner</title>
        <style>
            body { font-family: monospace; padding: 20px; background: #1e1e1e; color: #d4d4d4; }
            .container { max-width: 1000px; margin: 0 auto; }
            .btn { padding: 10px 20px; margin: 5px; cursor: pointer; background: #007acc; color: white; border: none; border-radius: 4px; }
            .btn:hover { background: #005a9e; }
            .output { background: #252526; padding: 15px; border-radius: 4px; margin-top: 20px; white-space: pre-wrap; }
            .success { color: #4ec9b0; }
            .error { color: #f48771; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>🚀 RBAC Migration Runner</h1>
            <p>Manage database migrations for the RBAC refactoring project.</p>
            
            <div>
                <button class="btn" onclick="runMigrations(false)">Run Migrations</button>
                <button class="btn" onclick="runMigrations(true)">Dry Run</button>
                <button class="btn" onclick="showStatus()">Show Status</button>
                <button class="btn" onclick="rollback()">Rollback Last</button>
            </div>
            
            <div id="output" class="output">Ready to execute migrations...</div>
        </div>
        
        <script>
            function runMigrations(dryRun) {
                document.getElementById('output').textContent = 'Running migrations...';
                fetch('?action=run&dry_run=' + (dryRun ? '1' : '0'))
                    .then(r => r.text())
                    .then(data => document.getElementById('output').textContent = data);
            }
            
            function showStatus() {
                document.getElementById('output').textContent = 'Loading status...';
                fetch('?action=status')
                    .then(r => r.text())
                    .then(data => document.getElementById('output').textContent = data);
            }
            
            function rollback() {
                if (confirm('Are you sure you want to rollback the last migration?')) {
                    document.getElementById('output').textContent = 'Rolling back...';
                    fetch('?action=rollback')
                        .then(r => r.text())
                        .then(data => document.getElementById('output').textContent = data);
                }
            }
        </script>
    </body>
    </html>
    <?php
    
    // Handle AJAX requests
    if (isset($_GET['action'])) {
        ob_start();
        $runner = new RBACMigrationRunner($conn);
        
        switch ($_GET['action']) {
            case 'run':
                $dryRun = isset($_GET['dry_run']) && $_GET['dry_run'] === '1';
                $runner->runAll($dryRun);
                break;
            case 'status':
                $runner->getStatus();
                break;
            case 'rollback':
                $runner->rollbackLast();
                break;
        }
        
        $output = ob_get_clean();
        die($output);
    }
}
