<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CampaignEngagementArchitectureTest extends TestCase
{
    private const LANGUAGES = ['pl', 'en', 'de', 'fr', 'it', 'es'];

    public function testMigrationExtendsExistingCampaignSystemWithoutNewRewardTables(): void
    {
        $sql = (string)file_get_contents(
            dirname(__DIR__, 2) . '/database/postgresql/migrations/20260810_011_verified_campaign_engagement.sql'
        );
        self::assertStringContainsString('ALTER TABLE "campaigns"', $sql);
        self::assertStringContainsString('ALTER TABLE "campaign_events"', $sql);
        self::assertStringContainsString('"talent_points_snapshot"', $sql);
        self::assertStringContainsString('"idempotency_key"', $sql);
        self::assertStringContainsString('"verification_status"', $sql);
        self::assertStringNotContainsString('CREATE TABLE "verified_events"', $sql);
        self::assertStringNotContainsString('CREATE TABLE "campaign_wallet', $sql);

        $extension = (string)file_get_contents(
            dirname(__DIR__, 2) . '/database/postgresql/migrations/20260810_012_campaigns_and_bug_reports.sql'
        );
        self::assertStringContainsString('campaign_delivery_events', $extension);
        self::assertStringContainsString('bug_reports', $extension);
        self::assertStringContainsString("UPDATE \"campaigns\" SET \"type\"='ad_view'", $extension);
    }

    public function testCampaignFrontAndNotificationBadgeHaveAllPublicLanguages(): void
    {
        $catalog = json_decode(
            (string)file_get_contents(dirname(__DIR__, 2) . '/resources/lang/public.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $keys = [
            'campaign.index.heading',
            'campaign.index.lead',
            'campaign.principle.verified',
            'campaign.action.queued',
            'campaign.action.duplicate',
            'campaign.detail.trust_title',
            'campaign.proof.ad_click',
            'campaign.proof.ad_view',
            'notifications.unread_label',
            'notifications.mark_read',
        ];
        foreach ($keys as $key) {
            self::assertArrayHasKey($key, $catalog);
            foreach (self::LANGUAGES as $language) {
                self::assertNotSame('', trim((string)($catalog[$key][$language] ?? '')), $key . ':' . $language);
            }
        }
    }

    public function testWebConsumesBackendUnreadCount(): void
    {
        $controller = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/EarningsApiController.php');
        $web = (string)file_get_contents(dirname(__DIR__, 2) . '/views/layouts/main.php');
        self::assertStringContainsString("'unread_count'", $controller);
        self::assertStringContainsString('payload.unread_count', $web);
    }

    public function testOnlyFourWorkingCampaignTypesAreExposed(): void
    {
        $service = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Services/CampaignService.php');
        $form = (string)file_get_contents(dirname(__DIR__, 2) . '/views/admin/partials/campaign_form.php');
        self::assertStringContainsString("'ad_click' =>", $service);
        self::assertStringContainsString("'ad_view' =>", $service);
        self::assertStringContainsString("'sponsored_article' =>", $service);
        self::assertStringContainsString("'survey_ad' =>", $service);
        self::assertStringNotContainsString("'ppv' =>", $service);
        self::assertStringNotContainsString("'live' =>", $service);
        self::assertStringNotContainsString('reward_for_user', $form);
    }

    public function testCampaignSurveyAndBugPublicCopyIsTranslatedInEveryLanguage(): void
    {
        $root = dirname(__DIR__, 2);
        $catalog = json_decode((string)file_get_contents($root . '/resources/lang/public.json'), true, 512, JSON_THROW_ON_ERROR);
        $keys = [];
        foreach ([
            'views/campaigns/index.php',
            'views/campaigns/show.php',
            'views/partials/campaign_slot.php',
            'views/surveys/index.php',
            'views/surveys/show.php',
            'views/bug_reports/form.php',
        ] as $path) {
            preg_match_all("/t\\('((?:campaign|survey|bug_report)\\.[a-z0-9_.]+)'/", (string)file_get_contents($root . '/' . $path), $matches);
            $keys = array_merge($keys, $matches[1]);
        }
        foreach (array_values(array_filter(array_unique($keys), static fn(string $key): bool => !str_ends_with($key, '.'))) as $key) {
            self::assertArrayHasKey($key, $catalog, $key);
            foreach (self::LANGUAGES as $language) {
                self::assertNotSame('', trim((string)($catalog[$key][$language] ?? '')), $key . ':' . $language);
            }
        }
    }

    public function testBugReportIsVisibleToUsersAndEditorialReviewers(): void
    {
        $root = dirname(__DIR__, 2);
        $layout = (string)file_get_contents($root . '/views/layouts/main.php');
        $routes = (string)file_get_contents($root . '/public/index.php');
        $editorial = (string)file_get_contents($root . '/views/admin/editorial_list.php');
        self::assertStringContainsString('/report-bug', $layout);
        self::assertStringContainsString("'/report-bug'", $routes);
        self::assertStringContainsString("'/admin/bug-reports'", $routes);
        self::assertStringContainsString('/admin/bug-reports', $editorial);
        self::assertTrue(seo_reserved_slug('report-bug'));
    }
}
