<?php
declare(strict_types=1);

namespace App\Security\Dors3;

interface CriticalOperationAuthorizerInterface
{
    public function begin(ApprovalContext $context): ApprovalRequest;

    public function verify(ApprovalResponse $response): ApprovalResult;

    public function isAvailable(): bool;

    public function providerName(): string;
}
