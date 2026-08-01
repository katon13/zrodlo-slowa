<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\ValkeyClientInterface;

final class EarningsWorkerRuntime
{
    public function __construct(
        private readonly ?ValkeyClientInterface $valkey,
        private readonly int $ttlSeconds = 120,
        private readonly string $key = 'earnings-worker:runtime',
    ) {}

    /** @param array<string,mixed> $state */
    public function heartbeat(array $state): bool
    {
        if ($this->valkey === null) {
            return false;
        }
        $state['heartbeat_at'] = gmdate('c');
        try {
            return $this->valkey->set(
                $this->key,
                json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                max(30, min(600, $this->ttlSeconds)),
            );
        } catch (\Throwable $error) {
            error_log('Heartbeat worker-earnings niedostępny: ' . $error->getMessage());
            return false;
        }
    }

    /** @return array<string,mixed>|null */
    public function read(): ?array
    {
        if ($this->valkey === null) {
            return null;
        }
        try {
            $json = $this->valkey->get($this->key);
            if ($json === null) {
                return null;
            }
            $state = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            return is_array($state) ? $state : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
