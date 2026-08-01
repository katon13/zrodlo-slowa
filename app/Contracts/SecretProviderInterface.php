<?php
declare(strict_types=1);

namespace App\Contracts;

interface SecretProviderInterface
{
    public function get(string $name): ?string;
}
