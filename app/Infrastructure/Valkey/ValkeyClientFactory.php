<?php
declare(strict_types=1);

namespace App\Infrastructure\Valkey;

use App\Contracts\ValkeyClientInterface;

final class ValkeyClientFactory
{
    public static function connect(array $config): ?ValkeyClientInterface
    {
        try {
            return PhpRedisValkeyClient::connect($config);
        } catch (\Throwable $error) {
            error_log('Valkey unavailable; safe fallbacks enabled: ' . $error->getMessage());
            return null;
        }
    }
}
