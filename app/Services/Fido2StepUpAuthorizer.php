<?php
declare(strict_types=1);

namespace App\Services;

use App\Security\Dors3\ApprovalContext;
use App\Security\Dors3\ApprovalRequest;
use App\Security\Dors3\ApprovalResponse;
use App\Security\Dors3\ApprovalResult;
use App\Security\Dors3\CriticalOperationAuthorizerInterface;

final class Fido2StepUpAuthorizer implements CriticalOperationAuthorizerInterface
{
    public function begin(ApprovalContext $context): ApprovalRequest
    {
        throw new \RuntimeException('FIDO2 pozostaje wyłączone do etapu po zakupie klucza.');
    }

    public function verify(ApprovalResponse $response): ApprovalResult
    {
        throw new \RuntimeException('FIDO2 pozostaje wyłączone do etapu po zakupie klucza.');
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function providerName(): string
    {
        return 'fido2';
    }
}
