<?php
declare(strict_types=1);

namespace App\Security\Dors3;

final class ApprovalRequest
{
    public function __construct(
        public readonly string $publicId,
        public readonly ApprovalContext $context,
        public readonly string $actionFingerprint,
        public readonly string $method,
        public readonly string $requestId,
        public readonly int $expiresAt,
    ) {}

    public function withContext(ApprovalContext $context): self
    {
        return new self(
            $this->publicId,
            $context,
            $this->actionFingerprint,
            $this->method,
            $this->requestId,
            $this->expiresAt,
        );
    }
}
