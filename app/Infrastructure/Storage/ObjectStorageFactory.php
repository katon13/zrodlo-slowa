<?php
declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Contracts\ObjectStorageInterface;

final class ObjectStorageFactory
{
    public static function create(string $rootPath, array $config): ObjectStorageInterface
    {
        $driver = strtolower(trim((string)($config['driver'] ?? 'local')));
        if ($driver === 's3') {
            $s3 = (array)($config['s3'] ?? []);
            return new S3ObjectStorage(
                S3ClientFactory::create($s3),
                (string)($s3['bucket'] ?? ''),
                (string)($s3['reference_prefix'] ?? '/objects'),
                (int)($s3['max_read_bytes'] ?? 10_485_760),
            );
        }
        if ($driver !== 'local') {
            throw new \RuntimeException('Nieobsługiwany sterownik magazynu obiektowego.');
        }

        $local = (array)($config['local'] ?? []);
        $rootDirectory = trim((string)($local['root'] ?? 'public/uploads'));
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/D', $rootDirectory) !== 1) {
            $rootDirectory = $rootPath . DIRECTORY_SEPARATOR . str_replace(
                ['/', '\\'],
                DIRECTORY_SEPARATOR,
                $rootDirectory
            );
        }
        return new LocalObjectStorage(
            $rootDirectory,
            (string)($local['public_prefix'] ?? '/uploads'),
        );
    }
}
