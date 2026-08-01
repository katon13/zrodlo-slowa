<?php
declare(strict_types=1);

namespace App\Infrastructure\Cache;

use App\Contracts\CacheStoreInterface;

final class NullCacheStore implements CacheStoreInterface
{
    public function get(string $key): array
    {
        return ['hit' => false, 'value' => null];
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void {}

    public function forget(string $key): void {}

    public function flushGroup(string $group): void {}

    public function flushAll(): void {}
}
