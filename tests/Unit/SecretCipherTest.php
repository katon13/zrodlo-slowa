<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SecretCipher;
use PHPUnit\Framework\TestCase;

final class SecretCipherTest extends TestCase
{
    public function testRoundTripAndTamperDetection(): void
    {
        $cipher = new SecretCipher(str_repeat('A', 48));
        $encrypted = $cipher->encrypt('JBSWY3DPEHPK3PXP');

        self::assertStringStartsWith('v1:', $encrypted);
        self::assertSame('JBSWY3DPEHPK3PXP', $cipher->decrypt($encrypted));
        self::assertSame('legacy-plain-secret', $cipher->decrypt('legacy-plain-secret'));

        $tampered = substr($encrypted, 0, -1) . ($encrypted[-1] === 'A' ? 'B' : 'A');
        $this->expectException(\RuntimeException::class);
        $cipher->decrypt($tampered);
    }
}
