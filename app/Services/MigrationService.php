<?php
namespace App\Services;

use App\Core\Database;

final class MigrationService
{
    private SqlScriptRunner $runner;

    public function __construct(
        private readonly Database $database,
        private readonly string $directory,
        ?SqlScriptRunner $runner = null,
    ) {
        $this->runner = $runner ?? new SqlScriptRunner();
    }

    public function migrate(): array
    {
        return $this->withMigrationLock(function (): array {
            $this->ensureHistoryTable();
            $summary = [];
            foreach ($this->migrationFiles() as $file) {
                $version = pathinfo($file, PATHINFO_FILENAME);
                $checksum = hash_file('sha256', $file);
                if (!is_string($checksum)) {
                    throw new \RuntimeException("Nie udało się policzyć sumy migracji $version.");
                }
                $existing = $this->database->one('SELECT * FROM schema_migrations WHERE version=:version', [
                    'version' => $version,
                ]);
                if ($existing && $existing['status'] === 'applied') {
                    if (!hash_equals((string)$existing['checksum'], $checksum)) {
                        throw new \RuntimeException("Zastosowana migracja $version została później zmieniona.");
                    }
                    $summary[$version] = [
                        'status' => 'already_applied',
                        'statements' => (int)$existing['statements_executed'],
                    ];
                    continue;
                }

                $this->markRunning($version, $checksum);

                try {
                    $started = microtime(true);
                    $statements = $this->runner->runFile($this->database, $file);
                    $elapsedMs = (int)round((microtime(true) - $started) * 1000);
                    $this->database->query(
                        "UPDATE schema_migrations
                         SET status='applied',statements_executed=:statements,duration_ms=:duration,
                             error_message=NULL,applied_at=NOW()
                         WHERE version=:version",
                        ['statements' => $statements, 'duration' => $elapsedMs, 'version' => $version]
                    );
                    $summary[$version] = [
                        'status' => 'applied',
                        'statements' => $statements,
                        'duration_ms' => $elapsedMs,
                    ];
                } catch (\Throwable $error) {
                    $this->database->query(
                        "UPDATE schema_migrations
                         SET status='failed',error_message=:error
                         WHERE version=:version",
                        ['error' => mb_substr($error->getMessage(), 0, 2000), 'version' => $version]
                    );
                    throw new \RuntimeException(
                        "Migracja $version nie powiodła się: {$error->getMessage()}",
                        0,
                        $error
                    );
                }
            }
            return $summary;
        });
    }

    public function baselineCurrentFiles(): array
    {
        return $this->withMigrationLock(function (): array {
            $this->ensureHistoryTable();
            $summary = [];
            foreach ($this->migrationFiles() as $file) {
                $version = pathinfo($file, PATHINFO_FILENAME);
                $checksum = hash_file('sha256', $file);
                if (!is_string($checksum)) {
                    throw new \RuntimeException("Nie udało się policzyć sumy migracji $version.");
                }
                $statements = count($this->runner->statements((string)file_get_contents($file)));
                $this->markBaselined($version, $checksum, $statements);
                $summary[$version] = ['status' => 'baselined', 'statements' => $statements];
            }
            return $summary;
        });
    }

    public function status(): array
    {
        $this->ensureHistoryTable();
        return $this->database->all('SELECT * FROM schema_migrations ORDER BY version');
    }

    private function ensureHistoryTable(): void
    {
        if ($this->database->isPostgres()) {
            $this->database->query(
                "CREATE TABLE IF NOT EXISTS schema_migrations (
                    version varchar(190) PRIMARY KEY,
                    checksum char(64) NOT NULL,
                    status varchar(16) NOT NULL CHECK (status IN ('running','applied','failed')),
                    statements_executed integer NOT NULL DEFAULT 0 CHECK (statements_executed >= 0),
                    duration_ms integer NOT NULL DEFAULT 0 CHECK (duration_ms >= 0),
                    error_message text NULL,
                    started_at timestamp without time zone NOT NULL,
                    applied_at timestamp without time zone NULL
                 )"
            );
            $this->database->query(
                'CREATE INDEX IF NOT EXISTS idx_schema_migrations_status
                 ON schema_migrations(status)'
            );
            return;
        }

        $this->database->query(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                version varchar(190) NOT NULL,
                checksum char(64) NOT NULL,
                status enum('running','applied','failed') NOT NULL,
                statements_executed int unsigned NOT NULL DEFAULT 0,
                duration_ms int unsigned NOT NULL DEFAULT 0,
                error_message text NULL,
                started_at datetime NOT NULL,
                applied_at datetime NULL,
                PRIMARY KEY (version),
                KEY idx_schema_migrations_status (status)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function markRunning(string $version, string $checksum): void
    {
        if ($this->database->isPostgres()) {
            $this->database->query(
                "INSERT INTO schema_migrations(
                    version,checksum,status,statements_executed,error_message,started_at,applied_at
                 ) VALUES(:version,:checksum,'running',0,NULL,NOW(),NULL)
                 ON CONFLICT (version) DO UPDATE SET
                    checksum=EXCLUDED.checksum,status='running',statements_executed=0,
                    error_message=NULL,started_at=NOW(),applied_at=NULL",
                ['version' => $version, 'checksum' => $checksum]
            );
            return;
        }

        $this->database->query(
            "INSERT INTO schema_migrations(
                version,checksum,status,statements_executed,error_message,started_at,applied_at
             ) VALUES(:version,:checksum,'running',0,NULL,NOW(),NULL)
             ON DUPLICATE KEY UPDATE checksum=VALUES(checksum),status='running',
                statements_executed=0,error_message=NULL,started_at=NOW(),applied_at=NULL",
            ['version' => $version, 'checksum' => $checksum]
        );
    }

    private function markBaselined(string $version, string $checksum, int $statements): void
    {
        if ($this->database->isPostgres()) {
            $this->database->query(
                "INSERT INTO schema_migrations(
                    version,checksum,status,statements_executed,duration_ms,error_message,started_at,applied_at
                 ) VALUES(:version,:checksum,'applied',:statements,0,NULL,NOW(),NOW())
                 ON CONFLICT (version) DO UPDATE SET
                    checksum=EXCLUDED.checksum,status='applied',
                    statements_executed=EXCLUDED.statements_executed,duration_ms=0,
                    error_message=NULL,applied_at=NOW()",
                ['version' => $version, 'checksum' => $checksum, 'statements' => $statements]
            );
            return;
        }

        $this->database->query(
            "INSERT INTO schema_migrations(
                version,checksum,status,statements_executed,duration_ms,error_message,started_at,applied_at
             ) VALUES(:version,:checksum,'applied',:statements,0,NULL,NOW(),NOW())
             ON DUPLICATE KEY UPDATE checksum=VALUES(checksum),status='applied',
                statements_executed=VALUES(statements_executed),duration_ms=0,
                error_message=NULL,applied_at=NOW()",
            ['version' => $version, 'checksum' => $checksum, 'statements' => $statements]
        );
    }

    private function withMigrationLock(callable $callback): mixed
    {
        $lockName = 'zrodlo_slowa_schema_migrations';
        if ($this->database->isPostgres()) {
            $this->database->cell(
                'SELECT pg_advisory_lock(hashtextextended(:lock_name,0))',
                ['lock_name' => $lockName]
            );
        } else {
            $acquired = (int)$this->database->cell(
                'SELECT GET_LOCK(:lock_name,30)',
                ['lock_name' => $lockName]
            );
            if ($acquired !== 1) {
                throw new \RuntimeException('Nie udało się uzyskać blokady migratora.');
            }
        }

        try {
            return $callback();
        } finally {
            if ($this->database->isPostgres()) {
                $this->database->cell(
                    'SELECT pg_advisory_unlock(hashtextextended(:lock_name,0))',
                    ['lock_name' => $lockName]
                );
            } else {
                $this->database->cell(
                    'SELECT RELEASE_LOCK(:lock_name)',
                    ['lock_name' => $lockName]
                );
            }
        }
    }

    private function migrationFiles(): array
    {
        if (!is_dir($this->directory)) {
            throw new \RuntimeException("Katalog migracji nie istnieje: {$this->directory}");
        }
        $files = glob(rtrim($this->directory, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            if (!preg_match('/^\d{8}_\d{3}_[a-z0-9_]+\.sql$/', basename($file))) {
                throw new \RuntimeException('Nieprawidłowa nazwa migracji: ' . basename($file));
            }
        }
        return $files;
    }
}
