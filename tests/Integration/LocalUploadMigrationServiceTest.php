<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Services\LocalUploadMigrationService;
use Tests\Support\InMemoryObjectStorage;

final class LocalUploadMigrationServiceTest extends DatabaseTestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rootPath = sys_get_temp_dir() . '/zs_upload_migration_' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->rootPath . '/public/uploads/articles', 0700, true));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->rootPath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->rootPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->rootPath);
        }
        parent::tearDown();
    }

    public function testDryRunAndApplyMigrateDatabaseReferenceWithoutDeletingSource(): void
    {
        $sourcePath = $this->rootPath . '/public/uploads/articles/legacy.webp';
        $image = imagecreatetruecolor(2, 2);
        self::assertInstanceOf(\GdImage::class, $image);
        self::assertTrue(imagewebp($image, $sourcePath, 80));
        imagedestroy($image);

        $mediaId = $this->database->insert(
            'INSERT INTO media(owner_user_id,article_id,path,mime,title,image_position,created_at)
             VALUES(NULL,NULL,:path,:mime,:title,50,NOW())',
            [
                'path' => '/uploads/articles/legacy.webp',
                'mime' => 'image/webp',
                'title' => 'legacy.webp',
            ]
        );
        $storage = new InMemoryObjectStorage();
        $service = new LocalUploadMigrationService($this->database, $storage, $this->rootPath);

        $dryRun = $service->migrate(false);
        self::assertSame(1, $dryRun['pending']);
        self::assertSame(1, count($dryRun['manifest']));
        self::assertFalse($dryRun['manifest'][0]['read_verified']);
        self::assertSame(
            '/uploads/articles/legacy.webp',
            $this->database->cell('SELECT path FROM media WHERE id=:id', ['id' => $mediaId])
        );

        $applied = $service->migrate(true);
        self::assertSame(1, $applied['migrated']);
        self::assertSame(1, $applied['verified']);
        self::assertTrue($applied['manifest'][0]['read_verified']);
        $reference = (string)$this->database->cell(
            'SELECT path FROM media WHERE id=:id',
            ['id' => $mediaId]
        );
        self::assertStringStartsWith('/objects/', $reference);
        self::assertTrue($storage->exists($reference));
        self::assertFileExists($sourcePath);

        $repeated = $service->migrate(true);
        self::assertSame(0, $repeated['scanned']);
        self::assertSame(0, $repeated['migrated']);
    }
}
