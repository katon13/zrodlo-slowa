<?php
declare(strict_types=1);

namespace App\Infrastructure\Valkey;

use App\Contracts\QueueSignalInterface;
use App\Contracts\ValkeyClientInterface;

final class ValkeyQueueSignal implements QueueSignalInterface
{
    public function __construct(
        private readonly ValkeyClientInterface $client,
        private readonly int $maximumSignals = 1000,
        private readonly int $ttlSeconds = 86400,
    ) {}

    public function notify(string $queue, string $durableJobId): bool
    {
        try {
            return $this->client->pushSignal(
                $this->key($queue),
                $durableJobId,
                $this->maximumSignals,
                $this->ttlSeconds
            ) > 0;
        } catch (\Throwable $error) {
            error_log('Valkey queue wake-up signal skipped: ' . $error->getMessage());
            return false;
        }
    }

    public function consume(string $queue): ?string
    {
        try {
            return $this->client->popSignal($this->key($queue));
        } catch (\Throwable $error) {
            error_log('Valkey queue wake-up signal unavailable: ' . $error->getMessage());
            return null;
        }
    }

    public function wait(string $queue, int $timeoutSeconds): ?string
    {
        return $this->client->blockingPopSignal(
            $this->key($queue),
            max(1, min(300, $timeoutSeconds))
        );
    }

    private function key(string $queue): string
    {
        $queue = preg_replace('/[^a-z0-9_.-]+/i', '-', trim($queue)) ?: 'default';
        return 'queue-signal:' . strtolower($queue);
    }
}
