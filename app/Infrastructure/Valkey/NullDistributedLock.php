<?php
declare(strict_types=1);

namespace App\Infrastructure\Valkey;

use App\Contracts\DistributedLockInterface;

final class NullDistributedLock implements DistributedLockInterface
{
    public function acquire(string $name, int $ttlMilliseconds, int $waitMilliseconds = 0): ?LockHandle
    {
        return null;
    }

    public function release(LockHandle $lock): void {}
}
