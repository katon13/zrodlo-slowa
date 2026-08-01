<?php
declare(strict_types=1);

namespace App\Infrastructure\Valkey;

use App\Contracts\RateLimiterInterface;
use App\Contracts\ValkeyClientInterface;

final class ValkeyRateLimiter implements RateLimiterInterface
{
    private bool $healthy = true;

    public function __construct(private readonly ValkeyClientInterface $client) {}

    public function available(): bool
    {
        return $this->healthy;
    }

    public function tooManyAttempts(string $key, int $maximum): bool
    {
        try {
            return (int)($this->client->get($this->key($key)) ?? '0') >= max(1, $maximum);
        } catch (\Throwable $error) {
            $this->fail($error);
            return false;
        }
    }

    public function hit(string $key, int $decaySeconds): int
    {
        try {
            return $this->client->increment($this->key($key), 1, max(1, $decaySeconds));
        } catch (\Throwable $error) {
            $this->fail($error);
            return 0;
        }
    }

    public function clear(string $key): void
    {
        try {
            $this->client->delete($this->key($key));
        } catch (\Throwable $error) {
            $this->fail($error);
        }
    }

    private function key(string $key): string
    {
        return 'rate:' . hash('sha256', $key);
    }

    private function fail(\Throwable $error): void
    {
        $this->healthy = false;
        error_log('Valkey rate limiter disabled for this request: ' . $error->getMessage());
    }
}
