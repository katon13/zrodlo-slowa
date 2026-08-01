<?php
declare(strict_types=1);

namespace App\Contracts;

interface RateLimiterInterface
{
    public function available(): bool;

    public function tooManyAttempts(string $key, int $maximum): bool;

    public function hit(string $key, int $decaySeconds): int;

    public function clear(string $key): void;
}
