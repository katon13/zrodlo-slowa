<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Storage\LocalObjectStorage;
use PHPUnit\Framework\TestCase;

final class LocalObjectStorageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/zs_local_storage_' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700, true));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testPutReadAndDeleteUseApplicationReference(): void
    {
        $source = $this->root . '/source.txt';
        file_put_contents($source, 'object-storage-contract');
        $storage = new LocalObjectStorage($this->root . '/objects', '/uploads');

        $reference = $storage->putFile('public/tests/example.txt', $source, 'text/plain');

        self::assertSame('/uploads/tests/example.txt', $reference);
        self::assertTrue($storage->exists($reference));
        self::assertSame('object-storage-contract', $storage->read($reference)->contents);
        self::assertTrue($storage->isPublicReference($reference));

        $storage->delete($reference);
        self::assertFalse($storage->exists($reference));
    }

    public function testLocalPublicAdapterRejectsPrivateObject(): void
    {
        $source = $this->root . '/source.txt';
        file_put_contents($source, 'private');
        $storage = new LocalObjectStorage($this->root . '/objects', '/uploads');

        $this->expectException(\InvalidArgumentException::class);
        $storage->putFile('private/example.txt', $source, 'text/plain');
    }
}
