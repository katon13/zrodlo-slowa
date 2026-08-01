<?php
declare(strict_types=1);

use App\Core\Database;
use App\Infrastructure\Storage\ObjectStorageFactory;
use App\Services\LocalUploadMigrationService;

require_once dirname(__DIR__) . '/app/Core/bootstrap.php';

$apply = in_array('--apply', $argv, true);
$deleteSource = in_array('--delete-source', $argv, true);
foreach (array_slice($argv, 1) as $argument) {
    if (!in_array($argument, ['--apply', '--delete-source'], true)) {
        fwrite(STDERR, "Użycie: php scripts/migrate_local_uploads_to_s3.php [--apply] [--delete-source]\n");
        exit(2);
    }
}

$rootPath = dirname(__DIR__);
$storageConfig = require $rootPath . '/config/storage.php';
if (($storageConfig['driver'] ?? 'local') !== 's3') {
    fwrite(STDERR, "Migracja wymaga OBJECT_STORAGE_DRIVER=s3.\n");
    exit(2);
}

try {
    $databaseConfig = require $rootPath . '/config/database.php';
    $report = (new LocalUploadMigrationService(
        new Database($databaseConfig['default']),
        ObjectStorageFactory::create($rootPath, $storageConfig),
        $rootPath,
        max(1_048_576, (int)env('S3_MIGRATION_MAX_BYTES', 26_214_400)),
    ))->migrate($apply, $deleteSource);
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(($report['failed'] + $report['invalid'] + $report['missing']) > 0 ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode([
        'status' => 'failed',
        'error' => $error->getMessage(),
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
