<?php
namespace App\Services;

use App\Contracts\EncryptionProviderInterface;
use App\Contracts\SecretProviderInterface;
use App\Infrastructure\Secrets\EnvironmentSecretProvider;

final class SecretCipher implements EncryptionProviderInterface
{
    private const PREFIX = 'v1:';
    private readonly string $key;

    public function __construct(string $applicationKey)
    {
        if (strlen($applicationKey) < 32 || preg_match('/change|replace|example|placeholder|wygeneruj|twoj/i', $applicationKey)) {
            throw new \RuntimeException('APP_KEY musi być losowym sekretem o długości co najmniej 32 znaków.');
        }
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new \RuntimeException('Rozszerzenie Sodium jest wymagane do ochrony sekretów.');
        }
        $this->key = hash('sha256', $applicationKey, true);
    }

    public static function fromEnvironment(): self
    {
        return self::fromSecretProvider(new EnvironmentSecretProvider());
    }

    public static function fromSecretProvider(SecretProviderInterface $secrets): self
    {
        return new self((string)($secrets->get('APP_KEY') ?? ''));
    }

    public function encrypt(string $plainText, string $purpose = 'application'): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipherText = sodium_crypto_secretbox($plainText, $nonce, $this->key);
        return self::PREFIX . sodium_bin2base64($nonce . $cipherText, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    public function decrypt(string $encoded, string $purpose = 'application'): string
    {
        if (!str_starts_with($encoded, self::PREFIX)) {
            return $encoded;
        }
        try {
            $binary = sodium_base642bin(
                substr($encoded, strlen(self::PREFIX)),
                SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING
            );
            $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            if (strlen($binary) <= $nonceLength) {
                throw new \RuntimeException('Zaszyfrowany sekret ma nieprawidłowy format.');
            }
            $plainText = sodium_crypto_secretbox_open(
                substr($binary, $nonceLength),
                substr($binary, 0, $nonceLength),
                $this->key
            );
            if (!is_string($plainText)) {
                throw new \RuntimeException('Nie udało się odszyfrować sekretu.');
            }
        } catch (\SodiumException|\ValueError $error) {
            throw new \RuntimeException('Zaszyfrowany sekret jest uszkodzony lub został zmieniony.', 0, $error);
        }
        return $plainText;
    }
}
