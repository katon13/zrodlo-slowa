<?php
declare(strict_types=1);

namespace App\Infrastructure\Valkey;

final readonly class LockHandle
{
    public function __construct(
        public string $key,
        public string $token,
    ) {}
}
