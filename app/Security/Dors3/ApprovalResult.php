<?php
declare(strict_types=1);

namespace App\Security\Dors3;

final class ApprovalResult
{
    public function __construct(
        public readonly bool $approved,
        public readonly string $authorizationPublicId,
        public readonly string $method,
        public readonly string $reason,
    ) {}
}
