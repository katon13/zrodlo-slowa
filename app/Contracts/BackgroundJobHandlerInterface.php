<?php
declare(strict_types=1);

namespace App\Contracts;

interface BackgroundJobHandlerInterface
{
    public function supports(string $jobType): bool;

    /** @return array<string,mixed> */
    public function handle(array $job): array;
}
