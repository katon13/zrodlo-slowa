<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;
use App\Services\MigrationService;
use App\Services\SelectedContentMigrationService;
use App\Services\SqlScriptRunner;

$options = getopt('', ['plan', 'dry-run', 'apply', 'resume', 'report:']);
$modes = array_values(array_intersect(['plan', 'dry-run', 'apply', 'resume'], array_keys($options)));
if (count($modes) !== 1) {
    fwrite(STDERR, "Użycie: php scripts/migrate_selected_content_admin_to_postgresql.php --plan|--dry-run|--apply|--resume [--report=plik.json]\n");
    exit(2);
}
foreach (['MYSQL_SOURCE_DB_HOST', 'MYSQL_SOURCE_DB_NAME', 'MYSQL_SOURCE_DB_USER'] as $required) {
    if (trim((string)env($required, '')) === '') {
        fwrite(STDERR, "Brak {$required}; importer nie używa poświadczeń Laragona ani wartości domyślnych.\n");
        exit(2);
    }
}

$mode = $modes[0];
$sourceDatabase = (string)env('MYSQL_SOURCE_DB_NAME');
$reportFile = isset($options['report']) ? trim((string)$options['report']) : '';
$temporarySchema = null;
$rootTarget = null;
$exitCode = 0;
$output = '';

try {
    $source = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string)env('MYSQL_SOURCE_DB_HOST'),
            (int)env('MYSQL_SOURCE_DB_PORT', 3306),
            $sourceDatabase
        ),
        (string)env('MYSQL_SOURCE_DB_USER'),
        (string)env('MYSQL_SOURCE_DB_PASS', ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION TRANSACTION READ ONLY',
        ]
    );

    $targetConfig = require __DIR__ . '/../config/database.php';
    if (strtolower((string)$targetConfig['default']['driver']) !== 'pgsql') {
        throw new RuntimeException('Baza docelowa musi używać DB_DRIVER=pgsql.');
    }
    $rootTarget = new Database($targetConfig['default']);
    $target = $rootTarget;

    if ($mode === 'dry-run') {
        $temporarySchema = 'selected_restore_' . bin2hex(random_bytes(6));
        $rootTarget->pdo()->exec('CREATE SCHEMA ' . $rootTarget->quoteIdentifier($temporarySchema));
        $dryConfig = $targetConfig['default'];
        $dryConfig['schema'] = $temporarySchema;
        $target = new Database($dryConfig);
        (new SqlScriptRunner())->runFile($target, __DIR__ . '/../database/postgresql/schema.sql');
    } elseif (in_array($mode, ['apply', 'resume'], true)) {
        (new MigrationService($target, __DIR__ . '/../database/postgresql/migrations'))->migrate();
    }

    $manifest = require __DIR__ . '/../config/mysql_to_postgresql_selected_migration.php';
    $manifest['_source_database'] = $sourceDatabase;
    $service = new SelectedContentMigrationService($source, $target, $manifest);
    $report = $mode === 'plan' ? $service->plan() : $service->import($mode);
    $report['mode'] = $mode;
    $report['generated_at'] = gmdate('c');
    if ($temporarySchema !== null) {
        $report['isolated_schema'] = $temporarySchema;
        $report['isolated_schema_dropped'] = true;
    }
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    $output = $json;
} catch (Throwable $error) {
    $exitCode = 1;
    $output = json_encode([
        'ok' => false,
        'mode' => $mode,
        'generated_at' => gmdate('c'),
        'error' => $error->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    if ($temporarySchema !== null && $rootTarget instanceof Database) {
        $rootTarget->pdo()->exec('DROP SCHEMA IF EXISTS ' . $rootTarget->quoteIdentifier($temporarySchema) . ' CASCADE');
    }
}

if ($reportFile !== '' && file_put_contents($reportFile, $output, LOCK_EX) === false) {
    fwrite(STDERR, "Nie udało się zapisać raportu: {$reportFile}\n");
    exit(1);
}
fwrite($exitCode === 0 ? STDOUT : STDERR, $output);
exit($exitCode);
