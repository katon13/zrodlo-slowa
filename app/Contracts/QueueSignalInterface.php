<?php
declare(strict_types=1);

namespace App\Contracts;

interface QueueSignalInterface
{
    public function notify(string $queue, string $durableJobId): bool;

    public function consume(string $queue): ?string;

    public function wait(string $queue, int $timeoutSeconds): ?string;
}
