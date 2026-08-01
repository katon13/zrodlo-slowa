<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Services\UploadService;
use App\Services\UserService;
use Tests\Support\InMemoryObjectStorage;

final class UploadServiceObjectStorageTest extends DatabaseTestCase
{
    public function testAvatarIsValidatedConvertedAndStoredUnderUniquePublicKey(): void
    {
        $storage = new InMemoryObjectStorage();
        $service = new UploadService($this->database, $storage);
        $image = imagecreatetruecolor(2, 2);
        self::assertInstanceOf(\GdImage::class, $image);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        self::assertIsString($png);
        $dataUrl = 'data:image/png;base64,' . base64_encode($png);

        $first = $service->uploadAvatarDataUrl($dataUrl, 42);
        $second = $service->uploadAvatarDataUrl($dataUrl, 42);

        self::assertNotSame($first, $second);
        self::assertTrue($storage->exists($first));
        self::assertSame('image/webp', $storage->read($first)->contentType);
        self::assertTrue($storage->isPublicReference($first));
    }

    public function testAvatarRejectsDeclaredImageWithInvalidContents(): void
    {
        $service = new UploadService($this->database, new InMemoryObjectStorage());

        $this->expectException(\RuntimeException::class);
        $service->uploadAvatarDataUrl(
            'data:image/png;base64,' . base64_encode('not-an-image'),
            42
        );
    }

    public function testAvatarReferenceUsesOptimisticConcurrencyGuard(): void
    {
        $userId = (int)$this->database->cell('SELECT id FROM users ORDER BY id LIMIT 1');
        self::assertGreaterThan(0, $userId);
        $this->database->query(
            'UPDATE users SET avatar_path=:path WHERE id=:id',
            ['id' => $userId, 'path' => '/objects/old-avatar']
        );
        $service = new UserService($this->database);

        self::assertTrue($service->updateAvatarIfCurrent(
            $userId,
            '/objects/new-avatar',
            '/objects/old-avatar'
        ));
        self::assertFalse($service->updateAvatarIfCurrent(
            $userId,
            '/objects/stale-avatar',
            '/objects/old-avatar'
        ));
        self::assertSame(
            '/objects/new-avatar',
            $this->database->cell('SELECT avatar_path FROM users WHERE id=:id', ['id' => $userId])
        );
    }
}
