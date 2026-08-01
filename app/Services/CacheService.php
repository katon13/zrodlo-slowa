<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\CacheStoreInterface;
use App\Contracts\DistributedLockInterface;
use App\Infrastructure\Cache\FileCacheStore;
use App\Infrastructure\Valkey\NullDistributedLock;

final class CacheService
{
    private readonly CacheStoreInterface $store;
    private readonly DistributedLockInterface $locks;
    private bool $available = true;

    public function __construct(
        string|CacheStoreInterface $store,
        ?DistributedLockInterface $locks = null,
    ) {
        $this->store = is_string($store) ? new FileCacheStore($store) : $store;
        $this->locks = $locks ?? new NullDistributedLock();
    }

    public function remember(string $key, int $ttlSeconds, callable $callback): mixed
    {
        $cached = $this->get($key);
        if ($cached['hit']) {
            return $cached['value'];
        }

        $lock = null;
        try {
            $lock = $this->locks->acquire('cache-fill:' . $key, 10_000, 150);
            if ($lock !== null) {
                $cached = $this->get($key);
                if ($cached['hit']) {
                    return $cached['value'];
                }
            } else {
                $cached = $this->get($key);
                if ($cached['hit']) {
                    return $cached['value'];
                }
            }

            $value = $callback();
            $jitter = max(0, (int)floor(max(1, $ttlSeconds) * 0.1));
            $effectiveTtl = max(1, $ttlSeconds) + ($jitter > 0 ? random_int(0, $jitter) : 0);
            $this->set($key, $value, $effectiveTtl);
            return $value;
        } finally {
            if ($lock !== null) {
                try {
                    $this->locks->release($lock);
                } catch (\Throwable $error) {
                    error_log('Nie udało się zwolnić blokady cache: ' . $error->getMessage());
                }
            }
        }
    }

    /**
     * @return array{hit: bool, value: mixed}
     */
    public function get(string $key): array
    {
        if (!$this->available) {
            return ['hit' => false, 'value' => null];
        }
        try {
            return $this->store->get($key);
        } catch (\Throwable $error) {
            $this->disable($error);
            return ['hit' => false, 'value' => null];
        }
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        if (!$this->available) {
            return;
        }
        try {
            $this->store->set($key, $value, max(1, $ttlSeconds));
        } catch (\Throwable $error) {
            $this->disable($error);
        }
    }

    public function forget(string $key): void
    {
        if (!$this->available) {
            return;
        }
        try {
            $this->store->forget($key);
        } catch (\Throwable $error) {
            $this->disable($error);
        }
    }

    public function flushGroup(string $group): void
    {
        if (!$this->available) {
            return;
        }
        try {
            $this->store->flushGroup($group);
        } catch (\Throwable $error) {
            $this->disable($error);
        }
    }

    public function flushAll(): void
    {
        if (!$this->available) {
            return;
        }
        try {
            $this->store->flushAll();
        } catch (\Throwable $error) {
            $this->disable($error);
        }
    }

    private function disable(\Throwable $error): void
    {
        $this->available = false;
        error_log('Cache wyłączony dla tego żądania: ' . $error->getMessage());
    }
}
