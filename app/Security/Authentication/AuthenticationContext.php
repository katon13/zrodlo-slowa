<?php
declare(strict_types=1);

namespace App\Security\Authentication;

final class AuthenticationContext
{
    /**
     * @param list<string> $factors
     */
    public function __construct(
        public readonly string $method,
        public readonly array $factors,
        public readonly int $authenticatedAt,
        public readonly ?int $stronglyVerifiedAt = null,
    ) {
        if ($authenticatedAt <= 0 || $factors === []) {
            throw new \InvalidArgumentException('Nieprawidłowy kontekst uwierzytelnienia.');
        }
    }

    public function satisfiesStepUp(int $maxAgeSeconds, ?int $now = null): bool
    {
        $now ??= time();
        return $this->stronglyVerifiedAt !== null
            && $maxAgeSeconds > 0
            && $this->stronglyVerifiedAt <= $now
            && $this->stronglyVerifiedAt + $maxAgeSeconds >= $now;
    }

    /**
     * @return array{method:string,factors:list<string>,authenticated_at:int,strongly_verified_at:?int}
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'factors' => array_values(array_unique($this->factors)),
            'authenticated_at' => $this->authenticatedAt,
            'strongly_verified_at' => $this->stronglyVerifiedAt,
        ];
    }

    public static function fromArray(array $value): ?self
    {
        $factors = array_values(array_filter(
            array_map('strval', (array)($value['factors'] ?? [])),
            static fn(string $factor): bool => $factor !== ''
        ));
        $authenticatedAt = (int)($value['authenticated_at'] ?? 0);
        if ($authenticatedAt <= 0 || $factors === []) {
            return null;
        }
        $stronglyVerifiedAt = isset($value['strongly_verified_at'])
            ? (int)$value['strongly_verified_at']
            : null;
        return new self(
            (string)($value['method'] ?? 'unknown'),
            $factors,
            $authenticatedAt,
            $stronglyVerifiedAt !== null && $stronglyVerifiedAt > 0 ? $stronglyVerifiedAt : null
        );
    }
}
