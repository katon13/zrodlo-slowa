<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AppReferralArchitectureTest extends TestCase
{
    public function testPostgresqlMigrationContainsOnlyPromotionAndInvitationTables(): void
    {
        $root = dirname(__DIR__, 2);
        $sql = (string)file_get_contents($root . '/database/postgresql/migrations/20260810_007_app_referral_promotion.sql');
        $nonceSql = (string)file_get_contents($root . '/database/postgresql/migrations/20260810_008_app_referral_registration_nonce.sql');
        preg_match_all('/CREATE TABLE IF NOT EXISTS "([^"]+)"/', $sql, $matches);
        self::assertSame(['talent_promotions', 'app_referral_invitations'], $matches[1]);
        self::assertStringContainsString("'app_referral_bonus'", $sql);
        self::assertStringContainsString('"reward_points" integer NOT NULL', $sql);
        self::assertStringNotContainsString('CREATE TABLE IF NOT EXISTS `', $sql);
        self::assertStringNotContainsString('CREATE TABLE', $nonceSql);
        self::assertStringContainsString('registration_nonce_hash', $nonceSql);
    }

    public function testTalentExceptionIsRestrictedToReferralActivityAndReference(): void
    {
        $root = dirname(__DIR__, 2);
        $talent = (string)file_get_contents($root . '/app/Services/TalentService.php');
        self::assertStringContainsString('AppReferralService::ACTIVITY_TYPE', $talent);
        self::assertStringContainsString('AppReferralService::REFERENCE_TYPE', $talent);
        self::assertStringContainsString('appReferralSnapshotPoints', $talent);
        self::assertStringContainsString('inviter_reward_job_public_id', $talent);
        self::assertStringContainsString('invitee_reward_job_public_id', $talent);
        self::assertStringContainsString('? 0', $talent);
        self::assertStringContainsString('kontrolowany wy', $talent);
    }

    public function testRegistrationUsesShortNonceAndReferralOauthIsHidden(): void
    {
        $root = dirname(__DIR__, 2);
        $auth = (string)file_get_contents($root . '/app/Controllers/AuthController.php');
        $register = (string)file_get_contents($root . '/views/auth/register.php');
        $android = (string)file_get_contents(
            $root . '/mobile/zrodlo-slowa-android/app/src/main/java/pl/zrodloslowa/app/ui/navigation/ZrodloSlowaNavHost.kt'
        );
        $manifest = (string)file_get_contents(
            $root . '/mobile/zrodlo-slowa-android/app/src/main/AndroidManifest.xml'
        );

        self::assertStringContainsString("\$_GET['refn']", $auth);
        self::assertStringNotContainsString("\$_GET['ref']", $auth);
        self::assertStringContainsString('registration_nonce', $register);
        self::assertStringContainsString('empty($registration_nonce)', $register);
        self::assertStringContainsString('register?refn=', $android);
        self::assertStringNotContainsString('register?ref=', $android);
        self::assertStringContainsString('android:launchMode="singleTask"', $manifest);
    }

    public function testEligibilityRejectionHasNeutralFrontendResponse(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = (string)file_get_contents($root . '/app/Controllers/AppReferralController.php');

        self::assertStringContainsString('PRIVATE_ELIGIBILITY_REJECTION', $controller);
        self::assertStringContainsString('adres kwalifikuje się do promocji', $controller);
        self::assertStringContainsString('http_response_code(202)', $controller);
    }
}
