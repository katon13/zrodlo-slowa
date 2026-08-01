<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CsrfTokenTest extends TestCase
{
    public function testEmptyOrMalformedTokensAreAlwaysRejected(): void
    {
        self::assertFalse(csrf_token_is_valid('', ''));
        self::assertFalse(csrf_token_is_valid('', null));
        self::assertFalse(csrf_token_is_valid(null, 'token'));
        self::assertFalse(csrf_token_is_valid([], 'token'));
    }

    public function testOnlyExactNonEmptyTokenIsAccepted(): void
    {
        $token = bin2hex(random_bytes(32));
        self::assertTrue(csrf_token_is_valid($token, $token));
        self::assertFalse(csrf_token_is_valid($token . 'x', $token));
    }
}
