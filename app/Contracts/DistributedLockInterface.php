<?php
declare(strict_types=1);

namespace App\Contracts;

use App\Infrastructure\Valkey\LockHandle;

interface DistributedLockInterface
{
    public function acquire(string $name, int $ttlMilliseconds, int $waitMilliseconds = 0): ?LockHandle;

    public function release(LockHandle $lock): void;
}
