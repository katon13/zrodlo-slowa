<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\FinancialReconciliationSourceInterface;
use App\Security\Authentication\AuthenticationContext;
use App\Security\Authorization\PermissionCatalog;
use App\Services\FinancialReconciliationService;
use PHPUnit\Framework\TestCase;

final class SecurityExtensionPointsTest extends TestCase
{
    public function testOrdinaryUserCannotUseAiPermissions(): void
    {
        self::assertFalse(PermissionCatalog::allows(['reader'], PermissionCatalog::AI_VIEW));
        self::assertFalse(PermissionCatalog::allows(['author'], PermissionCatalog::AI_JOB_PLAN));
        self::assertTrue(PermissionCatalog::allows(['editor'], PermissionCatalog::AI_JOB_PLAN));
        self::assertFalse(PermissionCatalog::allows(['editor'], PermissionCatalog::AI_SETTINGS_MANAGE));
        self::assertTrue(PermissionCatalog::allows(['admin'], PermissionCatalog::AI_SETTINGS_MANAGE));
    }

    public function testAuthenticationContextSupportsFutureStepUpChecks(): void
    {
        $context = new AuthenticationContext('password', ['password', 'totp'], 1_000, 1_050);
        self::assertTrue($context->satisfiesStepUp(600, 1_500));
        self::assertFalse($context->satisfiesStepUp(300, 1_500));
        self::assertSame($context->toArray(), AuthenticationContext::fromArray($context->toArray())?->toArray());
    }

    public function testReconciliationIsProviderNeutralAndReportsDifferences(): void
    {
        $left = $this->source(['source' => 'db', 'captured_at' => 'a', 'wallet_count' => 2, 'paid_payout_minor' => 100]);
        $right = $this->source(['source' => 'processor', 'captured_at' => 'b', 'wallet_count' => 2, 'paid_payout_minor' => 90]);

        $result = (new FinancialReconciliationService())->compare($left, $right);

        self::assertFalse($result['ok']);
        self::assertSame([
            'left' => 100,
            'right' => 90,
        ], $result['differences']['paid_payout_minor']);
        self::assertArrayNotHasKey('captured_at', $result['differences']);
    }

    private function source(array $snapshot): FinancialReconciliationSourceInterface
    {
        return new class($snapshot) implements FinancialReconciliationSourceInterface {
            public function __construct(private readonly array $value) {}
            public function snapshot(): array
            {
                return $this->value;
            }
        };
    }
}
