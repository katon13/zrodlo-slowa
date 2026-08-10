<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class Dors3MobileMigrationTest extends TestCase
{
    public function testMigrationContainsSeparateDeviceCredentialRequestAndSignatureTables(): void
    {
        $sql = (string)file_get_contents(dirname(__DIR__, 2) . '/database/postgresql/migrations/20260803_001_3dors_mobile.sql');
        foreach ([
            'security_mobile_devices',
            'security_mobile_credentials',
            'security_mobile_enrollments',
            'security_mobile_approval_requests',
            'security_mobile_signatures',
            'security_mobile_operation_policies',
            'security_mobile_deferred_operations',
            'security_mobile_rate_limits',
        ] as $table) {
            self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "' . $table . '"', $sql);
        }
        self::assertStringContainsString("CHECK (\"application_variant\" IN ('admin', 'author'))", $sql);
        self::assertStringContainsString("CHECK (\"status\" IN ('pending', 'approved', 'rejected', 'expired', 'consumed', 'cancelled'))", $sql);
        self::assertStringContainsString('uq_security_mobile_requests_pending_operation', $sql);
        self::assertStringNotContainsString('DROP TABLE', strtoupper($sql));
        self::assertStringNotContainsString('TRUNCATE ', strtoupper($sql));
    }

    public function testIndependentAuditMigrationAddsPanelBindingAndExplicitAttestationState(): void
    {
        $sql = (string)file_get_contents(dirname(__DIR__, 2) . '/database/postgresql/migrations/20260803_003_3dors_independent_audit.sql');
        self::assertStringContainsString('"device_completed_at"', $sql);
        self::assertStringContainsString('"panel_confirmed_by"', $sql);
        self::assertStringContainsString('"attestation_verified"', $sql);
        self::assertStringContainsString('dors3_expire_prior_author_agreements', $sql);
        self::assertStringNotContainsString('DROP TABLE', strtoupper($sql));
        self::assertStringNotContainsString('TRUNCATE ', strtoupper($sql));
    }

    public function testRecoveryReadyMigrationMovesFinancialOperationsToAdminWithoutMasterDeviceModel(): void
    {
        $sql = (string)file_get_contents(dirname(__DIR__, 2) . '/database/postgresql/migrations/20260807_004_3dors_recovery_ready.sql');
        self::assertStringContainsString("'payout_details.change'", $sql);
        self::assertStringContainsString("'wallet.own_operation'", $sql);
        self::assertStringContainsString("\"application_variant\" = 'admin'", $sql);
        self::assertStringNotContainsString('ADD COLUMN "DEVICE_PURPOSE"', strtoupper($sql));
        self::assertStringNotContainsString('DROP TABLE', strtoupper($sql));
        self::assertStringNotContainsString('TRUNCATE ', strtoupper($sql));
    }
}
