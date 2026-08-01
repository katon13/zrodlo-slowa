<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Storage\ObjectStorageFactory;
use PHPUnit\Framework\TestCase;

final class PublicObjectRouteIntegrationTest extends TestCase
{
    public function testPublicObjectIsServedByBothApplicationInstances(): void
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

        $source = tempnam(sys_get_temp_dir(), 'zs_public_object_');
        self::assertIsString($source);
        $image = imagecreatetruecolor(3, 3);
        self::assertInstanceOf(\GdImage::class, $image);
        self::assertTrue(imagewebp($image, $source, 80));
        imagedestroy($image);
        $expected = file_get_contents($source);
        self::assertIsString($expected);
        $reference = $storage->putFile(
            'public/compatibility/http-' . bin2hex(random_bytes(12)) . '.webp',
            $source,
            'image/webp'
        );
        $instances = [];

        try {
            foreach (['app-1', 'app-2', 'app-1', 'app-2'] as $instance) {
                $headers = [];
                $curl = curl_init('http://' . $instance . ':8080' . $reference);
                self::assertNotFalse($curl);
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                        $separator = strpos($line, ':');
                        if ($separator !== false) {
                            $headers[strtolower(trim(substr($line, 0, $separator)))] = trim(
                                substr($line, $separator + 1)
                            );
                        }
                        return strlen($line);
                    },
                ]);
                $body = curl_exec($curl);
                $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                curl_close($curl);

                self::assertSame(200, $status);
                self::assertSame($expected, $body);
                self::assertSame('image/webp', $headers['content-type'] ?? null);
                $instances[] = (string)($headers['x-app-instance'] ?? '');
            }
        } finally {
            $storage->delete($reference);
            @unlink($source);
        }

        self::assertCount(2, array_unique(array_filter($instances)));
    }

    public function testPrivateObjectCannotBeReadThroughPublicRoute(): void
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
        $source = tempnam(sys_get_temp_dir(), 'zs_private_object_');
        self::assertIsString($source);
        file_put_contents($source, 'private-object');
        $reference = $storage->putFile(
            'private/compatibility/' . bin2hex(random_bytes(12)) . '.txt',
            $source,
            'text/plain'
        );

        try {
            self::assertFalse($storage->isPublicReference($reference));
            $curl = curl_init('http://app-1:8080' . $reference);
            self::assertNotFalse($curl);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 5,
            ]);
            curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            self::assertSame(404, $status);
        } finally {
            $storage->delete($reference);
            @unlink($source);
        }
    }
}
