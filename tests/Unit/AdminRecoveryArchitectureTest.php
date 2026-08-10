<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminRecoveryArchitectureTest extends TestCase
{
    public function testCliRecoveryRevokesCompleteMobileAdminStateWithoutDeletingHistory(): void
    {
        $recovery = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Services/AdminRecoveryService.php');
        $mobileReset = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Services/AdminMobileSecurityResetService.php');

        self::assertStringContainsString('AdminMobileSecurityResetService', $recovery);
        foreach ([
            'security_mobile_devices',
            'security_mobile_credentials',
            'security_mobile_enrollments',
            'security_mobile_approval_requests',
            'security_mobile_deferred_operations',
            'api_token_hash=NULL',
        ] as $requiredReset) {
            self::assertStringContainsString($requiredReset, $mobileReset);
        }
        self::assertStringNotContainsString('DELETE FROM security_mobile_', $mobileReset);
        self::assertStringContainsString("application_variant=\'admin\'", $mobileReset);
        self::assertStringNotContainsString('device_purpose', $mobileReset);
    }

    public function testWebRecoveryIsCapabilityBoundAndNeverCreatesAdminSession(): void
    {
        $service = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Services/AdminWebRecoveryService.php');
        $controller = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/AdminWebRecoveryController.php');

        self::assertStringContainsString("'scope' => 'security_replacement_only'", $service);
        self::assertStringContainsString("'ordinary_admin_session_created' => false", $service);
        self::assertStringContainsString("'session_binding' => \$sessionBinding", $service);
        self::assertStringContainsString('AdminMobileSecurityResetService', $service);
        self::assertStringContainsString("method=\'recovery\'", $service);
        self::assertStringNotContainsString('->login(', $service);
        self::assertStringNotContainsString('->login(', $controller);
    }

    public function testPlanRejectsMasterKeepsCheckpointDeferredAndUsesOneLedgerForSafetyFund(): void
    {
        $plan = (string)file_get_contents(dirname(__DIR__, 2) . '/docs/3DORS_MOBILE_MASTER_RECOVERY_PLAN_ARCHITEKTONICZNY.md');
        self::assertStringContainsString('Wycofano koncepcję `MASTER / OPERATIONAL` w całości.', $plan);
        self::assertStringContainsString('Nie jest backupem', $plan);
        self::assertStringContainsString('osobnym backendem ani drugim ledgerem', $plan);
        self::assertStringContainsString('Safety Fund — wdrożony model redakcyjny', $plan);
        self::assertStringContainsString('- nie rejestruje 3DORS;', $plan);
    }
}
