<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database;
use App\Services\MigrationService;
use PHPUnit\Framework\TestCase;

final class MigrationChecksumTest extends TestCase
{
    public function testChecksumIsPortableBetweenLfAndCrlfCheckouts(): void
    {
        $directory = sys_get_temp_dir() . '/zs-migration-checksum-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        $lfFile = $directory . '/lf.sql';
        $crlfFile = $directory . '/crlf.sql';
        file_put_contents($lfFile, "SELECT 1;\nSELECT 2;\n");
        file_put_contents($crlfFile, "SELECT 1;\r\nSELECT 2;\r\n");

        try {
            $service = new MigrationService(
                new Database(['driver' => 'pgsql']),
                $directory,
            );
            $method = new \ReflectionMethod($service, 'migrationChecksum');

            self::assertSame(
                $method->invoke($service, $lfFile, 'lf'),
                $method->invoke($service, $crlfFile, 'crlf'),
            );
        } finally {
            @unlink($lfFile);
            @unlink($crlfFile);
            @rmdir($directory);
        }
    }
}
