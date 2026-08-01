<?php
declare(strict_types=1);

namespace App\Contracts;

interface StructuredLoggerInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $operation, array $context): void;
}
