<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CacheService;
use PHPUnit\Framework\TestCase;

final class CacheServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zs-cache-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testRememberUsesJsonAndRecoversFromCorruptEntry(): void
    {
        $cache = new CacheService($this->root);
        $calls = 0;
        $first = $cache->remember('site_menu:pl', 60, function () use (&$calls): array {
            $calls++;
            return ['one' => 1];
        });
        $second = $cache->remember('site_menu:pl', 60, function () use (&$calls): array {
            $calls++;
            return ['two' => 2];
        });
        self::assertSame(['one' => 1], $first);
        self::assertSame($first, $second);
        self::assertSame(1, $calls);

        $path = $this->root . '/storage/cache/site/cache_' . hash('sha256', 'site_menu:pl') . '.json';
        file_put_contents($path, '{broken');
        $third = $cache->remember('site_menu:pl', 60, function () use (&$calls): array {
            $calls++;
            return ['recovered' => true];
        });
        self::assertSame(['recovered' => true], $third);
        self::assertSame(2, $calls);

        $decoded = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $decoded['format']);
        $cache->flushGroup('site_menu');
        self::assertFileDoesNotExist($path);
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
