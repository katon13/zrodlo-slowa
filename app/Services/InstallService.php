<?php
namespace App\Services;

use App\Core\Database;
use PDO;

final class InstallService
{
    public const FRESH_CONFIRMATION = 'DROP_AND_REBUILD_ZRODLO_SLOWA';

    private readonly EnvironmentValidator $environmentValidator;
    private readonly SqlScriptRunner $sqlRunner;

    public function __construct(
        private readonly string $rootPath,
        private readonly array $databaseConfig,
        ?EnvironmentValidator $environmentValidator = null,
        ?SqlScriptRunner $sqlRunner = null,
    ) {
        $this->environmentValidator = $environmentValidator ?? new EnvironmentValidator();
        $this->sqlRunner = $sqlRunner ?? new SqlScriptRunner();
    }

    public function install(bool $fresh = false, ?string $confirmation = null): array
    {
        $this->environmentValidator->assertInstallable();
        if ($fresh && !hash_equals(self::FRESH_CONFIRMATION, (string)$confirmation)) {
            throw new \RuntimeException('Odmowa destrukcyjnej instalacji: brak dokładnego potwierdzenia.');
        }

        $this->ensureDatabaseExists();
        $db = new Database($this->databaseConfig['default']);
        if ($fresh) {
            $this->dropApplicationTables($db);
        }

        $schemaLoaded = false;
        $schemaStatements = 0;
        $migrationService = new MigrationService(
            $db,
            $this->migrationDirectory($db),
            $this->sqlRunner
        );

        if (!$this->tableExists($db, 'users')) {
            if ($this->applicationTableCount($db) > 0) {
                throw new \RuntimeException(
                    'Baza zawiera niepełny, obcy lub uszkodzony schemat. Automatyczny import został zatrzymany.'
                );
            }
            $schemaFile = $this->schemaFile($db);
            $schemaStatements = $this->sqlRunner->runFile($db, $schemaFile);
            $schemaLoaded = true;
            // The base dump is intentionally a stable snapshot. Every later change must
            // still be executed, not merely recorded as an applied migration. Otherwise
            // a clean installation can silently miss tables and columns added afterwards.
            $migrations = $migrationService->migrate();
        } else {
            $migrations = $migrationService->migrate();
        }

        $admin = $this->seedAdmin($db);
        $platform = env('SEED_PLATFORM_ACCOUNT', 'true') === 'true'
            ? $this->seedPlatform($db)
            : ['status' => 'skipped_by_configuration'];
        $settings = $this->seedSettings($db);
        $protectedTwoFactorSecrets = $this->protectLegacyTwoFactorSecrets($db);

        return [
            'ok' => true,
            'database' => $this->databaseName(),
            'mode' => $fresh ? 'fresh' : ($schemaLoaded ? 'initial' : 'migrate'),
            'schema_loaded' => $schemaLoaded,
            'schema_statements' => $schemaStatements,
            'migrations' => $migrations,
            'admin' => $admin,
            'platform' => $platform,
            'settings' => $settings,
            'protected_legacy_2fa_secrets' => $protectedTwoFactorSecrets,
        ];
    }

    public function migrate(): array
    {
        $this->ensureDatabaseExists();
        $db = new Database($this->databaseConfig['default']);
        if (!$this->tableExists($db, 'users')) {
            return $this->install(false);
        }

        $migrations = (new MigrationService(
            $db,
            $this->migrationDirectory($db),
            $this->sqlRunner,
        ))->migrate();

        return [
            'ok' => true,
            'database' => $this->databaseName(),
            'mode' => 'migrate',
            'schema_loaded' => false,
            'schema_statements' => 0,
            'migrations' => $migrations,
        ];
    }

    public function check(): array
    {
        $environment = $this->environmentValidator->validate(false);
        try {
            $db = new Database($this->databaseConfig['default']);
            $missing = [];
            foreach ($this->requiredSchemaTables() as $table) {
                if (!$this->tableExists($db, $table)) {
                    $missing[] = $table;
                }
            }

            $columnsToCheck = [
                'users' => ['session_version', 'can_write', 'wallet_enabled', 'payout_enabled'],
                'sessions' => ['id', 'user_id', 'payload', 'last_activity'],
                'wallets' => ['main_available_minor', 'slowo_available_minor', 'main_reserved_minor', 'slowo_reserved_minor', 'points_balance', 'is_locked'],
                'wallet_transactions' => ['previous_hash', 'entry_hash', 'hash_algorithm', 'hash_version', 'idempotency_key', 'ref_type', 'ref_id', 'wallet_previous_hash', 'wallet_entry_hash', 'wallet_hash_algorithm', 'wallet_hash_version'],
                'financial_ledger_migration_state' => ['mode', 'legacy_cutover_transaction_id', 'compliance_report_hash', 'verified_at', 'activated_at'],
                'financial_wallet_ledger_heads' => ['wallet_id', 'last_transaction_id', 'last_entry_hash', 'transaction_count'],
                'financial_operations' => ['idempotency_key', 'request_hash', 'status', 'transaction_id'],
                'financial_ledger_anchors' => ['period_start', 'period_end', 'merkle_root', 'previous_anchor_hash', 'anchor_hash', 'manifest_json'],
                'financial_approvals' => ['requested_by', 'requested_role', 'approved_by', 'approved_role', 'admin_note', 'status'],
                'mail_queue' => ['status', 'attempts', 'max_attempts', 'available_at', 'locked_at', 'locked_by', 'message_id', 'idempotency_key', 'dead_lettered_at', 'created_at', 'updated_at'],
                'talent_promotions' => ['code', 'reward_points', 'active_invitation_limit', 'successful_referral_limit', 'is_promoted', 'starts_at', 'ends_at'],
                'app_referral_invitations' => ['public_id', 'promotion_id', 'inviter_user_id', 'invitee_user_id', 'invited_email', 'reward_points', 'token_hash', 'device_hash', 'registration_nonce_hash', 'registration_nonce_expires_at', 'registration_nonce_used_at', 'status', 'mail_queue_id', 'expires_at', 'registered_at', 'first_session_at', 'reward_queued_at'],
                'background_jobs' => ['public_id', 'queue_name', 'job_type', 'status', 'payload_json', 'idempotency_key', 'retry_policy', 'attempts', 'max_attempts', 'lease_expires_at'],
                'background_job_events' => ['background_job_id', 'event_type', 'to_status', 'created_at'],
                'scheduler_runs' => ['task_name', 'scheduled_for', 'status'],
                'articles' => ['status', 'access_mode', 'price_minor', 'published_at', 'revision_of_article_id', 'response_to_article_id', 'response_reward_qualified', 'response_reward_points', 'response_reward_job_public_id'],
                'campaigns' => ['type', 'status', 'budget_minor', 'budget_confirmed', 'placement', 'creative_path', 'creative_mime', 'linked_article_id', 'linked_survey_id', 'minimum_view_seconds'],
                'campaign_events' => ['public_id', 'verification_status', 'proof_type', 'idempotency_key', 'talent_activity_type', 'talent_points_snapshot', 'talent_job_public_id'],
                'campaign_delivery_events' => ['campaign_id', 'session_hash', 'event_type', 'event_date'],
                'bug_reports' => ['public_id', 'status', 'reward_qualified', 'reward_points', 'reward_job_public_id'],
                'ai_jobs' => ['estimated_cost_minor', 'actual_cost_minor', 'budget_period', 'budget_status'],
            ];
            foreach ($columnsToCheck as $table => $columns) {
                if (!$this->tableExists($db, $table)) {
                    continue;
                }
                foreach ($columns as $column) {
                    if (!$this->columnExists($db, $table, $column)) {
                        $missing[] = "{$table}.{$column}";
                    }
                }
            }

            $admin = $this->tableExists($db, 'users')
                ? $db->one(
                    "SELECT u.email,u.display_name
                     FROM users u
                     JOIN user_roles ur ON ur.user_id=u.id
                     WHERE ur.role='admin' AND u.status='active'
                     ORDER BY u.id LIMIT 1"
                )
                : null;
            $failedMigrations = $this->tableExists($db, 'schema_migrations')
                ? $db->all("SELECT version,status,error_message
                            FROM schema_migrations
                            WHERE status<>'applied'
                            ORDER BY version")
                : [];
            $triggerExists = $this->triggerExists($db, 'trg_wallets_before_update');
            $ledgerHeadExists = $this->tableExists($db, 'financial_ledger_head')
                && (int)$db->cell('SELECT COUNT(*) FROM financial_ledger_head WHERE id=1') === 1
                && $this->tableExists($db, 'financial_ledger_migration_state')
                && (int)$db->cell('SELECT COUNT(*) FROM financial_ledger_migration_state WHERE id=1') === 1;

            return [
                'ok' => $missing === []
                    && $admin !== null
                    && $failedMigrations === []
                    && $triggerExists
                    && $ledgerHeadExists
                    && $environment['ok'],
                'database_reachable' => true,
                'environment' => $environment,
                'missing_items' => $missing,
                'failed_migrations' => $failedMigrations,
                'wallet_guard_trigger' => $triggerExists,
                'ledger_head' => $ledgerHeadExists,
                'admin' => $admin,
            ];
        } catch (\Throwable $error) {
            return [
                'ok' => false,
                'database_reachable' => false,
                'environment' => $environment,
                'error' => $error->getMessage(),
            ];
        }
    }

    private function ensureDatabaseExists(): void
    {
        $cfg = $this->databaseConfig['default'];
        if (strtolower((string)($cfg['driver'] ?? 'mysql')) === 'pgsql') {
            try {
                $database = new Database($cfg);
                $pdo = $database->pdo();
            } catch (\Throwable $error) {
                throw new \RuntimeException(
                    'Docelowa baza PostgreSQL musi zostać utworzona poza procesem aplikacji: '
                    . $error->getMessage(),
                    0,
                    $error
                );
            }

            $schema = $database->schema();
            $exists = (int)$database->cell(
                'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name=:schema',
                ['schema' => $schema]
            ) === 1;
            if (!$exists) {
                if (!(bool)($cfg['allow_create_schema'] ?? false)) {
                    throw new \RuntimeException(
                        "Schemat PostgreSQL {$schema} nie istnieje i DB_ALLOW_CREATE_SCHEMA jest wyłączone."
                    );
                }
                $pdo->exec('CREATE SCHEMA ' . $database->quoteIdentifier($schema));
                $pdo->exec('SET search_path TO ' . $database->quoteIdentifier($schema));
            }
            return;
        }

        $dsn = sprintf('mysql:host=%s;port=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['charset']);
        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $database = $this->quotedIdentifier($this->databaseName());
        $charset = $this->safeCharset((string)$cfg['charset']);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS {$database} CHARACTER SET {$charset} COLLATE {$charset}_unicode_ci");
    }

    private function dropApplicationTables(Database $db): void
    {
        if ($db->isPostgres()) {
            $rows = $db->all(
                "SELECT table_name,'BASE TABLE' AS table_type
                 FROM information_schema.tables
                 WHERE table_schema=:schema AND table_type='BASE TABLE'
                 UNION ALL
                 SELECT table_name,'VIEW' AS table_type
                 FROM information_schema.views
                 WHERE table_schema=:schema
                 ORDER BY table_type DESC,table_name",
                ['schema' => $db->schema()]
            );
            foreach ($rows as $row) {
                $kind = strtoupper((string)$row['table_type']) === 'VIEW' ? 'VIEW' : 'TABLE';
                $db->query(
                    "DROP {$kind} IF EXISTS " . $db->quoteIdentifier((string)$row['table_name']) . ' CASCADE'
                );
            }
            return;
        }

        $rows = $db->all(
            'SELECT TABLE_NAME AS table_name,TABLE_TYPE AS table_type FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=:db ORDER BY TABLE_TYPE DESC,TABLE_NAME',
            ['db' => $this->databaseName()]
        );
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($rows as $row) {
                $name = $db->quoteIdentifier((string)$row['table_name']);
                $kind = strtoupper((string)$row['table_type']) === 'VIEW' ? 'VIEW' : 'TABLE';
                $db->query("DROP {$kind} IF EXISTS {$name}");
            }
        } finally {
            $db->query('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function seedAdmin(Database $db): array
    {
        $email = strtolower(trim((string)env('ADMIN_EMAIL', '')));
        $displayName = trim((string)env('ADMIN_DISPLAY_NAME', 'Administrator'));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('ADMIN_EMAIL musi zawierać poprawny adres e-mail.');
        }
        if ($displayName === '') {
            throw new \RuntimeException('ADMIN_DISPLAY_NAME nie może być puste.');
        }

        return $db->transaction(function (Database $db) use ($email, $displayName): array {
            $currentAdmin = $db->one(
                'SELECT u.id,u.email,u.display_name
                 FROM users u JOIN user_roles ur ON ur.user_id=u.id
                 WHERE ur.role=\'admin\' ORDER BY u.id LIMIT 1 FOR UPDATE'
            );
            if ($currentAdmin !== null) {
                return [
                    'status' => 'preserved',
                    'email' => (string)$currentAdmin['email'],
                    'display_name' => (string)$currentAdmin['display_name'],
                ];
            }

            $existing = $db->one('SELECT id,email,display_name FROM users WHERE email=:email FOR UPDATE', ['email' => $email]);
            if ($existing !== null) {
                $userId = (int)$existing['id'];
                $db->query(
                    'UPDATE users
                     SET status=\'active\',can_write=1,talent_enabled=1,wallet_enabled=1,payout_enabled=1,
                         permissions_updated_at=NOW(),updated_at=NOW()
                     WHERE id=:id',
                    ['id' => $userId]
                );
                $status = 'promoted_existing_user';
            } else {
                $password = (string)env('ADMIN_PASSWORD', '');
                $this->assertStrongAdminPassword($password);
                $userId = $db->insert(
                    'INSERT INTO users(
                        email,phone,password_hash,display_name,status,can_write,talent_enabled,
                        wallet_enabled,payout_enabled,permissions_updated_at,created_at,updated_at
                     ) VALUES(:email,NULL,:hash,:name,\'active\',1,1,1,1,NOW(),NOW(),NOW())',
                    [
                        'email' => $email,
                        'hash' => password_hash($password . (string)env('PASSWORD_PEPPER', ''), PASSWORD_DEFAULT),
                        'name' => $displayName,
                    ]
                );
                $status = 'created';
            }

            if ($db->isPostgres()) {
                $db->query(
                    "INSERT INTO user_roles(user_id,role) VALUES(:id,'admin')
                     ON CONFLICT (user_id,role) DO NOTHING",
                    ['id' => $userId]
                );
            } else {
                $db->query(
                    "INSERT INTO user_roles(user_id,role) VALUES(:id,'admin')
                     ON DUPLICATE KEY UPDATE role=VALUES(role)",
                    ['id' => $userId]
                );
            }
            $this->ensureWallet($db, $userId);

            return ['status' => $status, 'email' => $email, 'display_name' => $displayName];
        });
    }

    private function seedPlatform(Database $db): array
    {
        $email = 'platform@zrodlo-slowa.local';
        $displayName = 'Platforma ŹRÓDŁO SŁOWA';

        return $db->transaction(function (Database $db) use ($email, $displayName): array {
            $existing = $db->one('SELECT id FROM users WHERE email=:email FOR UPDATE', ['email' => $email]);
            if ($existing !== null) {
                $userId = (int)$existing['id'];
                $db->query(
                    'UPDATE users
                     SET display_name=:name,status=\'active\',wallet_enabled=1,updated_at=NOW(),permissions_updated_at=NOW()
                     WHERE id=:id',
                    ['name' => $displayName, 'id' => $userId]
                );
                $status = 'preserved';
            } else {
                $userId = $db->insert(
                    'INSERT INTO users(
                        email,phone,password_hash,display_name,status,can_write,talent_enabled,
                        wallet_enabled,payout_enabled,permissions_updated_at,created_at,updated_at
                     ) VALUES(:email,NULL,:hash,:name,\'active\',0,0,1,0,NOW(),NOW(),NOW())',
                    [
                        'email' => $email,
                        'hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                        'name' => $displayName,
                    ]
                );
                $status = 'created';
            }
            $this->ensureWallet($db, $userId);
            return ['status' => $status, 'email' => $email, 'display_name' => $displayName];
        });
    }

    private function seedSettings(Database $db): array
    {
        $settings = [
            'site.name' => (string)env('APP_NAME', 'ŹRÓDŁO SŁOWA'),
            'site.tagline' => 'Pisz. Publikuj. Zarabiaj.',
            'migration.status' => 'ready',
            'premium_access_hours' => '12',
            'publisher_fee_percent' => '40',
            'ai.enabled' => '0',
            'ai.default_provider' => 'openai',
            'ai.openai.model' => 'gpt-5.5',
            'ai.translation.model' => 'gpt-5.5',
            'ai.translation.premium_model' => 'gpt-5.5',
            'ai.translation.max_chars_per_job' => '60000',
            'ai.translation.daily_jobs_limit' => '20',
            'ai.translation.monthly_budget_minor' => '5000',
            'ai.translation.estimated_cost_per_1k_chars_minor' => '5',
            'ai.storage.source_of_truth' => 'database',
            'ai.storage.raw_json_policy' => 'audit_only',
            'ai.translation.enabled' => '0',
            'ai.translation.require_editor_review' => '1',
            'ai.jobs.execute_api_enabled' => '0',
            'ai.openai.last_test_status' => 'never',
            'ai.openai.last_test_at' => '',
            'ai.openai.last_test_error' => '',
        ];
        foreach ($settings as $name => $value) {
            $sql = $db->isPostgres()
                ? 'INSERT INTO settings(name,value,updated_at) VALUES(:name,:value,NOW())
                   ON CONFLICT (name) DO NOTHING'
                : 'INSERT INTO settings(name,value,updated_at) VALUES(:name,:value,NOW())
                   ON DUPLICATE KEY UPDATE name=VALUES(name)';
            $db->query($sql, ['name' => $name, 'value' => $value]);
        }
        return ['defaults_available' => count($settings), 'existing_values_preserved' => true];
    }

    private function ensureWallet(Database $db, int $userId): void
    {
        $sql = $db->isPostgres()
            ? "INSERT INTO wallets(
                    user_id,available_minor,pending_minor,reserved_minor,points_balance,currency,created_at
               ) VALUES(:id,0,0,0,0,'PLN',NOW())
               ON CONFLICT (user_id) DO NOTHING"
            : "INSERT INTO wallets(
                    user_id,available_minor,pending_minor,reserved_minor,points_balance,currency,created_at
               ) VALUES(:id,0,0,0,0,'PLN',NOW())
               ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)";
        $db->query($sql, ['id' => $userId]);
    }

    private function protectLegacyTwoFactorSecrets(Database $db): int
    {
        return $db->transaction(function (Database $db): int {
            $rows = $db->all(
                'SELECT id,two_factor_secret FROM users
                 WHERE two_factor_secret IS NOT NULL
                   AND two_factor_secret<>\'\'
                   AND two_factor_secret NOT LIKE \'v1:%\'
                 FOR UPDATE'
            );
            if ($rows === []) {
                return 0;
            }
            $cipher = SecretCipher::fromEnvironment();
            $updated = 0;
            foreach ($rows as $row) {
                $legacy = (string)$row['two_factor_secret'];
                $statement = $db->query(
                    'UPDATE users SET two_factor_secret=:encrypted,updated_at=NOW()
                     WHERE id=:id AND two_factor_secret=:legacy',
                    [
                        'encrypted' => $cipher->encrypt($legacy),
                        'id' => (int)$row['id'],
                        'legacy' => $legacy,
                    ]
                );
                $updated += $statement->rowCount();
            }
            return $updated;
        });
    }

    private function assertStrongAdminPassword(string $password): void
    {
        if (
            strlen($password) < 12
            || strlen($password) > 4096
            || preg_match('/[A-Za-z]/', $password) !== 1
            || preg_match('/[^A-Za-z]/', $password) !== 1
            || (new EnvironmentValidator())->isPlaceholderValue($password)
        ) {
            throw new \RuntimeException(
                'ADMIN_PASSWORD dla nowego administratora musi mieć co najmniej 12 znaków, litery i znaki innego typu oraz nie może być wartością przykładową.'
            );
        }
    }

    private function requiredSchemaTables(): array
    {
        $driver = strtolower((string)($this->databaseConfig['default']['driver'] ?? 'mysql'));
        $schemaFile = $driver === 'pgsql'
            ? $this->rootPath . '/database/postgresql/schema.sql'
            : $this->rootPath . '/database/zrodlo_slowa.sql';
        $sql = is_file($schemaFile) ? (string)file_get_contents($schemaFile) : '';
        $pattern = $driver === 'pgsql'
            ? '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?"([^"]+)"/i'
            : '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`/i';
        preg_match_all($pattern, $sql, $matches);
        $tables = array_values(array_unique(array_merge($matches[1], ['schema_migrations'])));
        sort($tables, SORT_STRING);
        return $tables;
    }

    private function applicationTableCount(Database $db): int
    {
        if ($db->isPostgres()) {
            return (int)$db->cell(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=:schema AND table_type='BASE TABLE'",
                ['schema' => $db->schema()]
            );
        }
        return (int)$db->cell(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=:db AND TABLE_TYPE='BASE TABLE'",
            ['db' => $this->databaseName()]
        );
    }

    private function tableExists(Database $db, string $table): bool
    {
        if ($db->isPostgres()) {
            return (int)$db->cell(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=:schema AND table_name=:table',
                ['schema' => $db->schema(), 'table' => $table]
            ) > 0;
        }
        return (int)$db->cell(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:table',
            ['db' => $this->databaseName(), 'table' => $table]
        ) > 0;
    }

    private function columnExists(Database $db, string $table, string $column): bool
    {
        if ($db->isPostgres()) {
            return (int)$db->cell(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema=:schema AND table_name=:table AND column_name=:column',
                ['schema' => $db->schema(), 'table' => $table, 'column' => $column]
            ) > 0;
        }
        return (int)$db->cell(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:table AND COLUMN_NAME=:column',
            ['db' => $this->databaseName(), 'table' => $table, 'column' => $column]
        ) > 0;
    }

    private function databaseName(): string
    {
        $name = (string)($this->databaseConfig['default']['database'] ?? '');
        if ($name === '' || preg_match('/^[A-Za-z0-9_$-]+$/D', $name) !== 1) {
            throw new \RuntimeException('Nieprawidłowa nazwa bazy danych.');
        }
        return $name;
    }

    private function quotedIdentifier(string $identifier): string
    {
        if ($identifier === '' || str_contains($identifier, "\0")) {
            throw new \RuntimeException('Nieprawidłowy identyfikator SQL.');
        }
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function safeCharset(string $charset): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $charset) !== 1) {
            throw new \RuntimeException('Nieprawidłowy zestaw znaków bazy danych.');
        }
        return $charset;
    }

    private function schemaFile(Database $db): string
    {
        return $db->isPostgres()
            ? $this->rootPath . '/database/postgresql/schema.sql'
            : $this->rootPath . '/database/zrodlo_slowa.sql';
    }

    private function migrationDirectory(Database $db): string
    {
        return $db->isPostgres()
            ? $this->rootPath . '/database/postgresql/migrations'
            : $this->rootPath . '/database/migrations';
    }

    private function triggerExists(Database $db, string $trigger): bool
    {
        if ($db->isPostgres()) {
            return (int)$db->cell(
                'SELECT COUNT(*) FROM information_schema.triggers
                 WHERE trigger_schema=:schema AND trigger_name=:trigger',
                ['schema' => $db->schema(), 'trigger' => $trigger]
            ) === 1;
        }

        return (int)$db->cell(
            'SELECT COUNT(*) FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA=:db AND TRIGGER_NAME=:trigger',
            ['db' => $this->databaseName(), 'trigger' => $trigger]
        ) === 1;
    }
}
