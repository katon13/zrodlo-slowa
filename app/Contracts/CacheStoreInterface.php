<?php
declare(strict_types=1);

namespace App\Contracts;

interface CacheStoreInterface
{
    /**
     * @return array{hit: bool, value: mixed}
     */
    public function get(string $key): array;

    public function set(string $key, mixed $value, int $ttlSeconds): void;

    public function forget(string $key): void;

    public function flushGroup(string $group): void;

    public function flushAll(): void;
}
