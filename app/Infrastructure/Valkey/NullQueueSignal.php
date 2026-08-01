<?php
declare(strict_types=1);

namespace App\Infrastructure\Valkey;

use App\Contracts\QueueSignalInterface;

final class NullQueueSignal implements QueueSignalInterface
{
    public function notify(string $queue, string $durableJobId): bool
    {
        return false;
    }

    public function consume(string $queue): ?string
    {
        return null;
    }

    public function wait(string $queue, int $timeoutSeconds): ?string
    {
        return null;
    }
}
