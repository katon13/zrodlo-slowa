<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Services\InstallService;
use App\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

final class FreshInstallTest extends TestCase
{
    private string $databaseName = '';
    private string $schemaName = '';
    /** @var array<string, mixed> */
    private array $databaseConfig = [];
    /** @var array<string, mixed> */
    private array $savedEnvironment = [];

    protected function setUp(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/database.php';
        if (($config['default']['driver'] ?? 'mysql') === 'pgsql') {
            $this->databaseName = (string)$config['default']['database'];
            $this->schemaName = 'zrodlo_slowa_codex_' . bin2hex(random_bytes(5));
            $config['default']['schema'] = $this->schemaName;
            $config['default']['allow_create_schema'] = true;
        } else {
            $this->databaseName = 'zrodlo_slowa_codex_' . bin2hex(random_bytes(5));
            $this->schemaName = '';
            $config['default']['database'] = $this->databaseName;
        }
        $this->databaseConfig = $config;

        foreach (['ADMIN_EMAIL', 'ADMIN_DISPLAY_NAME', 'ADMIN_PASSWORD'] as $key) {
            $this->savedEnvironment[$key] = $_ENV[$key] ?? null;
        }
        $_ENV['ADMIN_EMAIL'] = 'install-test@example.test';
        $_ENV['ADMIN_DISPLAY_NAME'] = 'Administrator testowy';
        $_ENV['ADMIN_PASSWORD'] = 'Codex-Fresh-Install-9x!2026';
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }

        if ($this->databaseConfig === []) {
            return;
        }
        $cfg = $this->databaseConfig['default'];
        if (($cfg['driver'] ?? 'mysql') === 'pgsql') {
            if (
                $this->schemaName === ''
                || preg_match('/^zrodlo_slowa_codex_[a-f0-9]{10}$/D', $this->schemaName) !== 1
            ) {
                return;
            }
            $database = new Database($cfg);
            $database->pdo()->exec(
                'DROP SCHEMA IF EXISTS ' . $database->quoteIdentifier($this->schemaName) . ' CASCADE'
            );
            return;
        }

        if (
            $this->databaseName === ''
            || preg_match('/^zrodlo_slowa_codex_[a-f0-9]{10}$/D', $this->databaseName) !== 1
        ) {
            return;
        }
        $dsn = sprintf('mysql:host=%s;port=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['charset']);
        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('DROP DATABASE IF EXISTS `' . $this->databaseName . '`');
    }

    public function testCompleteSchemaCanBeInstalledAndCheckedOnAnEmptyDatabase(): void
    {
        $root = dirname(__DIR__, 2);
        $installer = new InstallService($root, $this->databaseConfig);

        $result = $installer->install();
        self::assertTrue($result['ok']);
        self::assertSame('initial', $result['mode']);
        self::assertTrue($result['schema_loaded']);
        self::assertGreaterThan(300, $result['schema_statements']);
        self::assertSame('created', $result['admin']['status']);

        $installedDatabase = new Database($this->databaseConfig['default']);
        self::assertSame(1, (int)$installedDatabase->cell(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=current_schema() AND table_name='security_mobile_devices'"
        ));
        self::assertSame(1, (int)$installedDatabase->cell(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=current_schema() AND table_name='author_agreements'"
        ));
        self::assertSame(1, (int)$installedDatabase->cell(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=current_schema() AND table_name='security_events'"
        ));
        self::assertSame(1, (int)$installedDatabase->cell(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=current_schema() AND table_name='security_settings'"
        ));
        self::assertSame(1, (int)$installedDatabase->cell(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=current_schema() AND table_name='webauthn_challenges'"
        ));
        self::assertSame(1, (int)$installedDatabase->cell(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=current_schema() AND table_name='revenue_split_policies'"
        ));
        self::assertSame(1, (int)$installedDatabase->cell(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=current_schema() AND table_name='safety_fund_allocations'"
        ));
        self::assertSame(1, (int)$installedDatabase->cell(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=current_schema() AND table_name='safety_fund_disbursements'"
        ));
        self::assertSame('4000/4000/2000', (string)$installedDatabase->cell(
            "SELECT author_basis_points || '/' || platform_basis_points || '/' || safety_fund_basis_points
             FROM revenue_split_policies WHERE status='active'"
        ));
        self::assertSame(1, (int)$installedDatabase->cell(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='activity_reward_logs' AND column_name='operation_key'"
        ));
        self::assertSame(1, (int)$installedDatabase->cell(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='activity_bonus_notifications' AND column_name='source_event_key'"
        ));
        self::assertSame(1, (int)$installedDatabase->cell(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='security_mobile_enrollments' AND column_name='device_completed_at'"
        ));

        $migrationDirectory = $root . '/database/postgresql/migrations';
        $expectedMigrations = count(glob($migrationDirectory . '/*.sql') ?: []);
        self::assertSame($expectedMigrations, (int)$installedDatabase->cell(
            "SELECT COUNT(*) FROM schema_migrations WHERE status='applied'"
        ));
        self::assertSame(1, (int)$installedDatabase->cell(
            "SELECT COUNT(*) FROM schema_migrations WHERE version='20260803_003_3dors_independent_audit' AND status='applied'"
        ));

        $check = $installer->check();
        self::assertTrue($check['ok'], json_encode($check, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        self::assertSame([], $check['missing_items']);
        self::assertTrue($check['wallet_guard_trigger']);
        self::assertTrue($check['ledger_head']);

        $secondRun = $installer->install();
        self::assertSame('migrate', $secondRun['mode']);
        self::assertSame('preserved', $secondRun['admin']['status']);
    }
}
