<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class Dors3SentinelContractTest extends TestCase
{
    public function testMigrationKeepsAlertsSeparateAndUsesActivationWatermark(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 2) . '/database/postgresql/migrations/20260808_006_3dors_sentinel.sql');
        self::assertIsString($sql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "security_alerts"', $sql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "security_alert_transitions"', $sql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "security_sentinel_state"', $sql);
        self::assertStringContainsString('UNIQUE ("source_event_id")', $sql);
        self::assertStringNotContainsString('UPDATE "security_events" SET', $sql);
    }

    public function testPanelIsLocalizedAndDoesNotRenderRawAuditPayloads(): void
    {
        $catalog = json_decode(
            (string)file_get_contents(dirname(__DIR__, 2) . '/resources/lang/dors3.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        foreach (['pl', 'en'] as $language) {
            self::assertNotEmpty($catalog[$language]['sentinel']['title'] ?? null);
            self::assertNotEmpty($catalog[$language]['sentinel']['notification_subject'] ?? null);
            self::assertNotEmpty($catalog[$language]['events']['actions']['sentinel.alert.resolved'] ?? null);
        }
        self::assertSame('STATUS WARTOWNIKA', $catalog['pl']['sentinel']['protection_status'] ?? null);
        self::assertSame('SENTINEL STATUS', $catalog['en']['sentinel']['protection_status'] ?? null);

        $view = (string)file_get_contents(dirname(__DIR__, 2) . '/views/admin/dors3_sentinel.php');
        self::assertStringNotContainsString("['metadata']", $view);
        self::assertStringNotContainsString("['before_state']", $view);
        self::assertStringNotContainsString("['after_state']", $view);
        self::assertStringNotContainsString('aria-label="Language"', $view);
        self::assertStringNotContainsString('aria-label="Pagination"', $view);
    }

    public function testPanelDarkModeOverridesSharedLightBadgesAndUsesReadableMicrocopy(): void
    {
        $css = (string)file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');
        $sentinelCss = strstr($css, '/* 3DORS WARTOWNIK', false);
        self::assertIsString($sentinelCss);
        $sentinelCss = explode('/* PROMOCJA TALENT / REFERRAL', $sentinelCss, 2)[0];

        self::assertStringContainsString('.zs-sentinel-language a.is-active{background:#18181b', $sentinelCss);
        self::assertStringContainsString('.zs-sentinel-alert-actions button.is-resolve{border-color:#23784f!important;background:#101713!important', $sentinelCss);
        self::assertStringContainsString('.zs-sentinel-page .zs-dors3-event-badge.is-success{border-color:#23784f!important;background:#101713!important', $sentinelCss);
        self::assertStringContainsString('.zs-sentinel-section-head button,.zs-sentinel-filters button{background:#151518!important', $sentinelCss);
        self::assertStringContainsString('background:#101012!important;color:#ddd!important', $sentinelCss);
        self::assertStringContainsString('.zs-sentinel-table{font-size:12.5px}', $sentinelCss);
        self::assertStringContainsString('.zs-sentinel-compact-log>div{', $sentinelCss);
        self::assertStringContainsString('font-size:12.5px', $sentinelCss);
        self::assertStringNotContainsString('background:#fff', $sentinelCss);
    }
}
