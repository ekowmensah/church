<?php
/**
 * 2026 Upgrade Migration Runner
 *
 * Usage:
 *   php run_migrations.php status
 *   php run_migrations.php run [--dry-run]
 *   php run_migrations.php rollback-last
 *   php run_migrations.php rollback <migration_key>
 */

require_once __DIR__ . '/../../config/config.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This runner is CLI-only.\n";
    exit;
}

class UpgradeMigrationRunner {
    private $conn;
    private $migrationsDir;
    private $rollbackDir;
    private $executedBy;

    public function __construct($conn) {
        $this->conn = $conn;
        $this->migrationsDir = __DIR__;
        $this->rollbackDir = __DIR__ . '/rollbacks';
        $this->executedBy = get_current_user() ?: 'cli_user';
    }

    public function status() {
        $this->ensureMigrationTracker();

        $currentDb = $this->fetchCurrentDatabase();
        $this->log("Database: {$currentDb}\n");
        $this->log("Directory: {$this->migrationsDir}\n\n");

        $executed = $this->getExecutedMigrations();
        $files = $this->getMigrationFiles();

        $this->log(str_pad('Migration Key', 66) . str_pad('Status', 14) . "Executed At\n");
        $this->log(str_repeat('-', 85) . "\n");

        foreach ($files as $file) {
            $key = $this->migrationKey($file);
            $row = $executed[$key] ?? null;
            $status = $row['status'] ?? 'pending';
            $executedAt = $row['executed_at'] ?? '-';

            $this->log(str_pad($key, 66) . str_pad($status, 14) . $executedAt . "\n");
        }
    }

    public function runAll($dryRun = false) {
        $this->ensureMigrationTracker();

        $files = $this->getMigrationFiles();
        $executed = $this->getExecutedMigrations();

        $this->log("=== 2026 Upgrade Migration Runner ===\n");
        $this->log("Mode: " . ($dryRun ? 'DRY RUN' : 'EXECUTE') . "\n");
        $this->log("Database: " . $this->fetchCurrentDatabase() . "\n");
        $this->log("Migrations found: " . count($files) . "\n\n");

        $pendingCount = 0;

        foreach ($files as $file) {
            $key = $this->migrationKey($file);
            $name = $this->migrationName($file);
            $existing = $executed[$key] ?? null;

            if ($existing && $existing['status'] === 'completed') {
                $this->log("[SKIP] {$key} (already completed)\n");
                continue;
            }

            $pendingCount++;

            if ($dryRun) {
                $this->log("[DRY ] {$key} ({$name})\n");
                continue;
            }

            $this->log("[RUN ] {$key} ({$name}) ... ");
            $result = $this->executeMigration($file, $key, $name);

            if ($result['success']) {
                $this->log("OK ({$result['time_ms']} ms)\n");
            } else {
                $this->log("FAILED\n");
                $this->log("Error: {$result['error']}\n");
                return false;
            }
        }

        if ($pendingCount === 0) {
            $this->log("\nNo pending migrations.\n");
        } else {
            $this->log("\nDone. Processed {$pendingCount} pending migration(s).\n");
        }

        return true;
    }

    public function rollbackLast() {
        $this->ensureMigrationTracker();

        $sql = "
            SELECT migration_key
            FROM schema_migrations
            WHERE status = 'completed'
            ORDER BY executed_at DESC, id DESC
            LIMIT 1
        ";

        $result = $this->conn->query($sql);
        if (!$result || $result->num_rows === 0) {
            $this->log("No completed migration to roll back.\n");
            return true;
        }

        $row = $result->fetch_assoc();
        return $this->rollbackByKey($row['migration_key']);
    }

    public function rollbackByKey($migrationKey) {
        $this->ensureMigrationTracker();

        $rollbackFile = $this->rollbackDir . '/' . $migrationKey . '.rollback.sql';
        if (!file_exists($rollbackFile)) {
            $this->log("Rollback file not found: {$rollbackFile}\n");
            return false;
        }

        $this->log("[ROLLBACK] {$migrationKey} ... ");

        $start = microtime(true);

        try {
            $sql = file_get_contents($rollbackFile);
            if ($sql === false) {
                throw new RuntimeException("Unable to read rollback file.");
            }

            $this->executeSqlMulti($sql);

            $timeMs = (int) round((microtime(true) - $start) * 1000);

            $stmt = $this->conn->prepare(
                "UPDATE schema_migrations
                 SET status = 'rolled_back',
                     notes = CONCAT(IFNULL(notes,''), '\\nRollback applied by ', ?, ' at ', NOW())
                 WHERE migration_key = ?"
            );
            $stmt->bind_param('ss', $this->executedBy, $migrationKey);
            $stmt->execute();

            $this->log("OK ({$timeMs} ms)\n");
            return true;
        } catch (Throwable $e) {
            $this->log("FAILED\n");
            $this->log("Error: {$e->getMessage()}\n");
            return false;
        }
    }

    private function executeMigration($fileName, $key, $name) {
        $start = microtime(true);

        try {
            $this->upsertTracker($key, $name, 'running', null);

            $sql = file_get_contents($this->migrationsDir . '/' . $fileName);
            if ($sql === false) {
                throw new RuntimeException("Unable to read migration file.");
            }

            $this->executeSqlMulti($sql);

            $timeMs = (int) round((microtime(true) - $start) * 1000);
            $this->upsertTracker($key, $name, 'completed', $timeMs);

            return ['success' => true, 'time_ms' => $timeMs];
        } catch (Throwable $e) {
            $this->upsertTracker($key, $name, 'failed', null, $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeSqlMulti($sql) {
        if (!$this->conn->multi_query($sql)) {
            throw new RuntimeException($this->conn->error ?: 'SQL execution failed.');
        }

        do {
            if ($result = $this->conn->store_result()) {
                $result->free();
            }
        } while ($this->conn->more_results() && $this->conn->next_result());

        if ($this->conn->errno) {
            throw new RuntimeException($this->conn->error);
        }
    }

    private function ensureMigrationTracker() {
        $this->conn->query("CREATE TABLE IF NOT EXISTS schema_migrations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            migration_key VARCHAR(191) NOT NULL,
            migration_name VARCHAR(255) NOT NULL,
            checksum_sha256 CHAR(64) NULL,
            executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            executed_by VARCHAR(120) NULL,
            execution_time_ms INT NULL,
            status ENUM('completed', 'rolled_back', 'failed', 'running') NOT NULL DEFAULT 'completed',
            rollback_script VARCHAR(255) NULL,
            notes TEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_schema_migrations_key (migration_key),
            KEY idx_schema_migrations_status (status),
            KEY idx_schema_migrations_executed_at (executed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function upsertTracker($key, $name, $status, $timeMs = null, $notes = null) {
        $rollbackScript = $key . '.rollback.sql';

        $stmt = $this->conn->prepare(
            "INSERT INTO schema_migrations
                (migration_key, migration_name, executed_by, execution_time_ms, status, rollback_script, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                migration_name = VALUES(migration_name),
                executed_at = CURRENT_TIMESTAMP,
                executed_by = VALUES(executed_by),
                execution_time_ms = VALUES(execution_time_ms),
                status = VALUES(status),
                rollback_script = VALUES(rollback_script),
                notes = VALUES(notes)"
        );

        $stmt->bind_param('sssisss', $key, $name, $this->executedBy, $timeMs, $status, $rollbackScript, $notes);
        $stmt->execute();
    }

    private function getExecutedMigrations() {
        $rows = [];
        $result = $this->conn->query("SELECT migration_key, status, executed_at FROM schema_migrations");

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[$row['migration_key']] = $row;
            }
        }

        return $rows;
    }

    private function getMigrationFiles() {
        $files = glob($this->migrationsDir . '/*.sql') ?: [];
        $result = [];

        foreach ($files as $file) {
            $name = basename($file);
            if (substr($name, -13) === '.rollback.sql') {
                continue;
            }
            $result[] = $name;
        }

        sort($result, SORT_STRING);
        return $result;
    }

    private function migrationKey($file) {
        return preg_replace('/\.sql$/', '', $file) ?? $file;
    }

    private function migrationName($file) {
        $key = $this->migrationKey($file);
        $parts = explode('_', $key, 4);
        return $parts[3] ?? $key;
    }

    private function fetchCurrentDatabase() {
        $result = $this->conn->query('SELECT DATABASE() AS db_name');
        if (!$result) {
            return '(unknown)';
        }
        $row = $result->fetch_assoc();
        return $row['db_name'] ?? '(unknown)';
    }

    private function log($message) {
        echo $message;
    }
}

function printHelp() {
    echo "2026 Upgrade Migration Runner\n\n";
    echo "Usage:\n";
    echo "  php run_migrations.php status [--db=DB_NAME]\n";
    echo "  php run_migrations.php run [--dry-run] [--db=DB_NAME]\n";
    echo "  php run_migrations.php rollback-last [--db=DB_NAME]\n";
    echo "  php run_migrations.php rollback <migration_key> [--db=DB_NAME]\n";
}

$dbOption = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--db=') === 0) {
        $dbOption = substr($arg, 5);
        break;
    }
}

if ($dbOption) {
    try {
        $ok = $conn->select_db($dbOption);
    } catch (Throwable $e) {
        $ok = false;
        $selectDbError = $e->getMessage();
    }

    if (!$ok) {
        $err = isset($selectDbError) ? $selectDbError : $conn->error;
        fwrite(STDERR, "Unable to select database '{$dbOption}': {$err}\n");
        exit(1);
    }
}

$command = $argv[1] ?? 'help';
$runner = new UpgradeMigrationRunner($conn);

switch ($command) {
    case 'status':
        $runner->status();
        break;

    case 'run':
        $dryRun = in_array('--dry-run', $argv, true);
        $ok = $runner->runAll($dryRun);
        exit($ok ? 0 : 1);

    case 'rollback-last':
        $ok = $runner->rollbackLast();
        exit($ok ? 0 : 1);

    case 'rollback':
        $migrationKey = $argv[2] ?? '';
        if ($migrationKey === '') {
            echo "Missing migration key.\n";
            printHelp();
            exit(1);
        }
        $ok = $runner->rollbackByKey($migrationKey);
        exit($ok ? 0 : 1);

    case 'help':
    default:
        printHelp();
        break;
}
