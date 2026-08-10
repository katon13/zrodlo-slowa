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
        $catalog = json_decode(
            (string)file_get_contents($root . '/resources/lang/public.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertStringContainsString('PRIVATE_ELIGIBILITY_REJECTION', $controller);
        self::assertStringContainsString("t('controller.appreferral.jezeli_adres_kwalifikuje_sie_do_promocji_zaproszenie_zo_8e26b5ed')", $controller);
        self::assertStringContainsString('adres kwalifikuje się do promocji', mb_strtolower((string)$catalog['controller.appreferral.jezeli_adres_kwalifikuje_sie_do_promocji_zaproszenie_zo_8e26b5ed']['pl']));
        self::assertStringContainsString('http_response_code(202)', $controller);
    }

    public function testReferralLandingAndPromotedBadgeAreTranslatedInEveryPublicLanguage(): void
    {
        $root = dirname(__DIR__, 2);
        $catalog = json_decode(
            (string)file_get_contents($root . '/resources/lang/public.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $landing = (string)file_get_contents($root . '/views/referral/landing.php');
        preg_match_all("/t\\('((?:referral\\.landing|referral\\.promoted)[a-z0-9_.]*)'/", $landing, $matches);
        $keys = array_values(array_unique(array_merge($matches[1], ['referral.promoted'])));

        foreach ($keys as $key) {
            self::assertArrayHasKey($key, $catalog, $key);
            foreach (['pl', 'en', 'de', 'fr', 'it', 'es'] as $language) {
                self::assertNotSame('', trim((string)($catalog[$key][$language] ?? '')), $key . ':' . $language);
            }
        }

        self::assertStringContainsString("t('referral.promoted'", (string)file_get_contents($root . '/views/wallet/show.php'));
        self::assertStringContainsString("\$tr('referral.promoted'", (string)file_get_contents($root . '/views/economy/show.php'));
    }
}
