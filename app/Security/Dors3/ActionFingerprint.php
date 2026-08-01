<?php
declare(strict_types=1);

namespace App\Security\Dors3;

final class ActionFingerprint
{
    public static function calculate(ApprovalContext $context, string $requestId, int $expiresAt): string
    {
        $payload = $context->toArray();
        $payload['request_id'] = $requestId;
        $payload['expires_at'] = $expiresAt;
        return hash('sha256', self::canonicalJson($payload));
    }

    /** @param array<string, mixed> $payload */
    public static function canonicalJson(array $payload): string
    {
        $normalized = self::normalize($payload);
        return json_encode(
            $normalized,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            if (is_float($value) && !is_finite($value)) {
                throw new \InvalidArgumentException('Fingerprint nie obsługuje wartości nieskończonych.');
            }
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }
        return $value;
    }
}
