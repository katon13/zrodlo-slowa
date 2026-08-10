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
}
