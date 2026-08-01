<?php
declare(strict_types=1);

namespace App\Contracts;

interface EncryptionProviderInterface
{
    public function encrypt(string $plainText, string $purpose = 'application'): string;

    public function decrypt(string $encoded, string $purpose = 'application'): string;
}
