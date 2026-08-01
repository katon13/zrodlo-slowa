<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\ValkeyClientInterface;

final class InMemoryValkeyClient implements ValkeyClientInterface
{
    /** @var array<string, string> */
    private array $values = [];
    /** @var array<string, float> */
    private array $expiresAt = [];
    /** @var array<string, list<string>> */
    private array $lists = [];

    public function ping(): bool
    {
        return true;
    }

    public function get(string $key): ?string
    {
        $this->expire($key);
        return $this->values[$key] ?? null;
    }

    public function set(string $key, string $value, int $ttlSeconds = 0): bool
    {
        $this->values[$key] = $value;
        $this->setExpiry($key, $ttlSeconds > 0 ? $ttlSeconds * 1000 : 0);
        return true;
    }

    public function setIfAbsent(string $key, string $value, int $ttlMilliseconds): bool
    {
        $this->expire($key);
        if (array_key_exists($key, $this->values)) {
            return false;
        }
        $this->values[$key] = $value;
        $this->setExpiry($key, max(1, $ttlMilliseconds));
        return true;
    }

    public function delete(string $key): bool
    {
        $exists = array_key_exists($key, $this->values) || array_key_exists($key, $this->lists);
        unset($this->values[$key], $this->lists[$key], $this->expiresAt[$key]);
        return $exists;
    }

    public function increment(string $key, int $amount = 1, int $ttlSeconds = 0): int
    {
        $this->expire($key);
        $new = (int)($this->values[$key] ?? '0') + $amount;
        $first = !array_key_exists($key, $this->values);
        $this->values[$key] = (string)$new;
        if ($first && $ttlSeconds > 0) {
            $this->setExpiry($key, $ttlSeconds * 1000);
        }
        return $new;
    }

    public function compareAndDelete(string $key, string $expectedValue): bool
    {
        if ($this->get($key) !== $expectedValue) {
            return false;
        }
        return $this->delete($key);
    }

    public function pushSignal(string $key, string $payload, int $maxLength, int $ttlSeconds): int
    {
        $this->lists[$key] ??= [];
        array_unshift($this->lists[$key], $payload);
        $this->lists[$key] = array_slice($this->lists[$key], 0, max(1, $maxLength));
        $this->setExpiry($key, max(1, $ttlSeconds) * 1000);
        return count($this->lists[$key]);
    }

    public function popSignal(string $key): ?string
    {
        $this->expire($key);
        if (($this->lists[$key] ?? []) === []) {
            return null;
        }
        $value = array_pop($this->lists[$key]);
        return is_string($value) ? $value : null;
    }

    public function blockingPopSignal(string $key, int $timeoutSeconds): ?string
    {
        return $this->popSignal($key);
    }

    private function expire(string $key): void
    {
        if (($this->expiresAt[$key] ?? INF) <= microtime(true)) {
            $this->delete($key);
        }
    }

    private function setExpiry(string $key, int $ttlMilliseconds): void
    {
        if ($ttlMilliseconds <= 0) {
            unset($this->expiresAt[$key]);
            return;
        }
        $this->expiresAt[$key] = microtime(true) + ($ttlMilliseconds / 1000);
    }
}
