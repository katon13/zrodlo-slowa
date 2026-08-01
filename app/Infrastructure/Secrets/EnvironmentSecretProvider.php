<?php
declare(strict_types=1);

namespace App\Infrastructure\Secrets;

use App\Contracts\SecretProviderInterface;

final class EnvironmentSecretProvider implements SecretProviderInterface
{
    public function get(string $name): ?string
    {
        if (preg_match('/^[A-Z][A-Z0-9_]*$/D', $name) !== 1) {
            throw new \InvalidArgumentException('Nieprawidłowa nazwa sekretu.');
        }
        $value = env($name, null);
        return $value === null ? null : (string)$value;
    }
}
