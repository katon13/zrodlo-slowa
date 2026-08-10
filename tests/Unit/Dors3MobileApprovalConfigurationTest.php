<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Security\Dors3\MobileApprovalConfiguration;
use PHPUnit\Framework\TestCase;

final class Dors3MobileApprovalConfigurationTest extends TestCase
{
    public function testTestModeMayLeaveAnOperationDisabled(): void
    {
        self::assertFalse(MobileApprovalConfiguration::isEnabled([
            'enabled' => true,
            'mode' => 'test',
            'author_app_enabled' => true,
            'article_submit_approval' => false,
        ], 'author', 'article_submit_approval'));
    }

    public function testRequiredModeRejectsAnUnprotectedOperation(): void
    {
        $this->expectException(\RuntimeException::class);

        MobileApprovalConfiguration::isEnabled([
            'enabled' => true,
            'mode' => 'required',
            'admin_app_enabled' => true,
            'payout_approval' => false,
        ], 'admin', 'payout_approval');
    }

    public function testRequiredVariantCannotBeGloballyDisabled(): void
    {
        $this->expectException(\RuntimeException::class);

        MobileApprovalConfiguration::isEnabled([
            'enabled' => false,
            'mode' => 'required',
            'admin_app_enabled' => true,
            'payout_approval' => true,
        ], 'admin', 'payout_approval');
    }

    public function testDisabledVariantDoesNotAffectTheOtherApplication(): void
    {
        self::assertFalse(MobileApprovalConfiguration::isEnabled([
            'enabled' => true,
            'mode' => 'required',
            'author_app_enabled' => false,
            'article_submit_approval' => false,
        ], 'author', 'article_submit_approval'));
    }

    public function testRequiredProtectedOperationIsEnabled(): void
    {
        self::assertTrue(MobileApprovalConfiguration::isEnabled([
            'enabled' => true,
            'mode' => 'required',
            'admin_app_enabled' => true,
            'payout_approval' => true,
        ], 'admin', 'payout_approval'));
    }

    public function testRequiredLoginVariantFailsClosedWhenGloballyDisabled(): void
    {
        $this->expectException(\RuntimeException::class);

        MobileApprovalConfiguration::isVariantEnabled([
            'enabled' => false,
            'mode' => 'required',
            'author_app_enabled' => true,
        ], 'author');
    }
}
