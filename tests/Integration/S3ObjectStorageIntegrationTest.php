<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Storage\ObjectStorageFactory;
use PHPUnit\Framework\TestCase;

final class S3ObjectStorageIntegrationTest extends TestCase
{
    public function testConfiguredS3EndpointSupportsPutReadHeadAndDelete(): void
    {
        $rootPath = dirname(__DIR__, 2);
        $config = require $rootPath . '/config/storage.php';
        if (($config['driver'] ?? 'local') !== 's3') {
            self::markTestSkipped('Test wymaga OBJECT_STORAGE_DRIVER=s3.');
        }
        $storage = ObjectStorageFactory::create($rootPath, $config);
        if (!$storage->healthCheck()) {
            self::markTestSkipped('Skonfigurowany bucket S3 nie jest gotowy.');
        }

        $source = tempnam(sys_get_temp_dir(), 'zs_s3_compat_');
        self::assertIsString($source);
        $contents = 's3-compatible-' . bin2hex(random_bytes(16));
        file_put_contents($source, $contents);
        $reference = '';

        try {
            $reference = $storage->putFile(
                'public/compatibility/' . bin2hex(random_bytes(16)) . '.txt',
                $source,
                'text/plain'
            );
            self::assertStringStartsWith('/objects/', $reference);
            self::assertTrue($storage->isPublicReference($reference));
            self::assertTrue($storage->exists($reference));
            $object = $storage->read($reference);
            self::assertSame($contents, $object->contents);
            self::assertSame('text/plain', $object->contentType);

            $storage->delete($reference);
            self::assertFalse($storage->exists($reference));
            $reference = '';
        } finally {
            if ($reference !== '') {
                $storage->delete($reference);
            }
            @unlink($source);
        }
    }
}
