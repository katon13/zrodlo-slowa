<?php
declare(strict_types=1);

namespace App\Security\Dors3;

final class ApprovalResponse
{
    public function __construct(
        public readonly ApprovalRequest $request,
        public readonly string $proof,
    ) {}
}
