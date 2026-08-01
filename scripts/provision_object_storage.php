<?php
declare(strict_types=1);

use App\Infrastructure\Storage\S3BucketProvisioner;
use App\Infrastructure\Storage\S3ClientFactory;

require_once dirname(__DIR__) . '/app/Core/bootstrap.php';

$config = require dirname(__DIR__) . '/config/storage.php';
if (($config['driver'] ?? 'local') !== 's3') {
    fwrite(STDOUT, json_encode(['status' => 'skipped', 'driver' => $config['driver'] ?? 'local']) . PHP_EOL);
    exit(0);
}

$s3 = (array)$config['s3'];
try {
    $status = (new S3BucketProvisioner(
        S3ClientFactory::create($s3),
        (string)$s3['bucket'],
        (string)$s3['region'],
    ))->provision();
    fwrite(STDOUT, json_encode([
        'status' => $status,
        'bucket' => (string)$s3['bucket'],
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode([
        'status' => 'failed',
        'error' => $error->getMessage(),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
