<?php
declare(strict_types=1);

namespace App\Services;

final class AuditArtifactSanitizer
{
    private const REDACTED = '[REDACTED]';

    /** @var list<string> */
    private const ALLOWED_ENVIRONMENT_EXAMPLES = [
        '.env.example',
        '.env.install.example',
        '.env.local.example',
        '.env.test.example',
        '.env.production.example',
    ];

    /** @var list<string> */
    private const DIRECT_SENSITIVE_KEYS = [
        'email',
        'admin_email',
        'payer_email',
        'phone',
        'phone_number',
        'display_name',
        'full_name',
        'first_name',
        'last_name',
        'password',
        'password_hash',
        'token',
        'api_token',
        'access_token',
        'refresh_token',
        'secret',
        'private_key',
    ];

    public static function isBlockedEnvironmentFileName(string $fileName): bool
    {
        $normalized = strtolower(trim($fileName));
        if ($normalized !== '.env' && !str_starts_with($normalized, '.env.')) {
            return false;
        }
        return !in_array($normalized, self::ALLOWED_ENVIRONMENT_EXAMPLES, true);
    }

    public function sanitize(string $content): string
    {
        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $this->encodeJson($this->sanitizeValue($decoded));
        }

        $content = (string)preg_replace(
            '/-----BEGIN(?: [A-Z0-9]+)* PRIVATE KEY-----.*?-----END(?: [A-Z0-9]+)* PRIVATE KEY-----/s',
            '[PRIVATE_KEY_REDACTED]',
            $content,
        );

        $lines = preg_split('/\R/', $content);
        if ($lines === false) {
            $lines = [$content];
        }
        foreach ($lines as &$line) {
            $jsonStart = strpos($line, '{');
            if ($jsonStart !== false) {
                $prefix = substr($line, 0, $jsonStart);
                $candidate = substr($line, $jsonStart);
                $lineDecoded = json_decode($candidate, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($lineDecoded)) {
                    $line = $prefix . $this->encodeJson($this->sanitizeValue($lineDecoded));
                    continue;
                }
            }

            $line = $this->sanitizePlainText($line);
        }
        unset($line);

        $result = implode(PHP_EOL, $lines);
        return $this->sanitizePlainText($result);
    }

    public function containsSensitiveData(string $content): bool
    {
        $patterns = [
            '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/iu',
            '/-----BEGIN(?: [A-Z0-9]+)* PRIVATE KEY-----/u',
            '/\b(?:APP_KEY|PASSWORD_PEPPER|FINANCE_HMAC_KEY|ADMIN_PASSWORD|DB_PASSWORD)\s*=\s*(?!\[SECRET_REDACTED\])\S+/iu',
            '/\bAuthorization\s*:\s*Bearer\s+(?!\[TOKEN_REDACTED\])\S+/iu',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content) === 1) {
                return true;
            }
        }
        return false;
    }

    private function sanitizePlainText(string $content): string
    {
        $content = (string)preg_replace(
            '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/iu',
            '[EMAIL_REDACTED]',
            $content,
        );
        $content = (string)preg_replace(
            '/(\b(?:APP_KEY|PASSWORD_PEPPER|FINANCE_HMAC_KEY|ADMIN_PASSWORD|DB_PASSWORD)\s*=\s*)\S+/iu',
            '$1[SECRET_REDACTED]',
            $content,
        );
        $content = (string)preg_replace(
            '/(\bAuthorization\s*:\s*Bearer\s+)\S+/iu',
            '$1[TOKEN_REDACTED]',
            $content,
        );
        return $content;
    }

    private function sanitizeValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            return self::REDACTED;
        }
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $childKey => $childValue) {
                $sanitized[$childKey] = $this->sanitizeValue(
                    $childValue,
                    is_string($childKey) ? $childKey : null,
                );
            }
            return $sanitized;
        }
        if (is_string($value)) {
            return $this->sanitizePlainText($value);
        }
        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower((string)preg_replace('/[^a-z0-9]+/i', '_', $key));
        if (in_array($normalized, self::DIRECT_SENSITIVE_KEYS, true)) {
            return true;
        }
        return preg_match(
            '/^(?:user|actor_user|admin|wallet|transaction|tx|device|credential|recipient_user|payer_user)_id$/',
            $normalized,
        ) === 1;
    }

    private function encodeJson(array $value): string
    {
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        return $encoded;
    }
}
