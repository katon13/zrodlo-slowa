<?php
declare(strict_types=1);

namespace App\Core;

final class RequestContext
{
    private static ?string $requestId = null;

    public static function requestId(): string
    {
        if (self::$requestId !== null) {
            return self::$requestId;
        }
        $incoming = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
        self::$requestId = preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,127}$/D', $incoming) === 1
            ? $incoming
            : bin2hex(random_bytes(16));
        return self::$requestId;
    }

    public static function ipAddress(): ?string
    {
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    public static function resetForTests(): void
    {
        self::$requestId = null;
    }
}
