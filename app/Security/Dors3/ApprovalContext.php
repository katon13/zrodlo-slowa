<?php
declare(strict_types=1);

namespace App\Security\Dors3;

final class ApprovalContext
{
    /**
     * @param array<string, mixed> $details
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function __construct(
        public readonly string $operation,
        public readonly int $actorId,
        public readonly string $resourceType,
        public readonly string $resourceId,
        public readonly array $details = [],
        public readonly ?array $before = null,
        public readonly ?array $after = null,
    ) {
        if ($actorId <= 0 || trim($operation) === '' || trim($resourceType) === '' || trim($resourceId) === '') {
            throw new \InvalidArgumentException('Kontekst operacji krytycznej jest niepełny.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'actor_id' => $this->actorId,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'details' => $this->details,
            'before' => $this->before,
            'after' => $this->after,
        ];
    }

    public static function fromArray(array $value): self
    {
        return new self(
            (string)($value['operation'] ?? ''),
            (int)($value['actor_id'] ?? 0),
            (string)($value['resource_type'] ?? ''),
            (string)($value['resource_id'] ?? ''),
            is_array($value['details'] ?? null) ? $value['details'] : [],
            is_array($value['before'] ?? null) ? $value['before'] : null,
            is_array($value['after'] ?? null) ? $value['after'] : null,
        );
    }
}
