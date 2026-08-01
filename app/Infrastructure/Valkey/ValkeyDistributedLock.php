<?php
declare(strict_types=1);

namespace App\Infrastructure\Valkey;

use App\Contracts\DistributedLockInterface;
use App\Contracts\ValkeyClientInterface;

final class ValkeyDistributedLock implements DistributedLockInterface
{
    public function __construct(private readonly ValkeyClientInterface $client) {}

    public function acquire(string $name, int $ttlMilliseconds, int $waitMilliseconds = 0): ?LockHandle
    {
        $key = 'lock:' . hash('sha256', $name);
        $token = bin2hex(random_bytes(24));
        $deadline = microtime(true) + (max(0, $waitMilliseconds) / 1000);

        do {
            if ($this->client->setIfAbsent($key, $token, max(100, $ttlMilliseconds))) {
                return new LockHandle($key, $token);
            }
            if ($waitMilliseconds <= 0 || microtime(true) >= $deadline) {
                return null;
            }
            usleep(random_int(10_000, 25_000));
        } while (microtime(true) < $deadline);

        return null;
    }

    public function release(LockHandle $lock): void
    {
        $this->client->compareAndDelete($lock->key, $lock->token);
    }
}
