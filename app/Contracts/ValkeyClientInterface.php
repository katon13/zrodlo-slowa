<?php
declare(strict_types=1);

namespace App\Contracts;

interface ValkeyClientInterface
{
    public function ping(): bool;

    public function get(string $key): ?string;

    public function set(string $key, string $value, int $ttlSeconds = 0): bool;

    public function setIfAbsent(string $key, string $value, int $ttlMilliseconds): bool;

    public function delete(string $key): bool;

    public function increment(string $key, int $amount = 1, int $ttlSeconds = 0): int;

    public function compareAndDelete(string $key, string $expectedValue): bool;

    public function pushSignal(string $key, string $payload, int $maxLength, int $ttlSeconds): int;

    public function popSignal(string $key): ?string;

    public function blockingPopSignal(string $key, int $timeoutSeconds): ?string;
}
