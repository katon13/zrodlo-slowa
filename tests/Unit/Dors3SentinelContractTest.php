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

        $operations = (string)file_get_contents(
            dirname(__DIR__, 2) . '/database/postgresql/migrations/20260811_013_sentinel_operations_and_archive.sql',
        );
        self::assertStringContainsString('"operation_key"', $operations);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "security_alert_events"', $operations);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "security_events_archive"', $operations);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "auth_login_events_archive"', $operations);
        self::assertStringContainsString('protect_security_event_archive', $operations);
        self::assertStringContainsString('FOR UPDATE OF e SKIP LOCKED', (string)file_get_contents(
            dirname(__DIR__, 2) . '/app/Services/Dors3SentinelArchiveService.php',
        ));
        $archiveService = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Services/Dors3SentinelArchiveService.php');
        self::assertStringContainsString("SET LOCAL lock_timeout = '250ms'", $archiveService);
        self::assertStringContainsString("SET LOCAL statement_timeout = '10s'", $archiveService);
        self::assertFileExists(dirname(__DIR__, 2) . '/scripts/worker_sentinel.php');
        self::assertStringContainsString('worker-sentinel:', (string)file_get_contents(dirname(__DIR__, 2) . '/compose.yaml'));
        $controller = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/Dors3SentinelController.php');
        self::assertStringContainsString('Dors3SentinelArchiveJobHandler::JOB_TYPE', $controller);
        self::assertStringNotContainsString('->archiveBefore(', $controller);
        $dashboardService = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Services/Dors3SentinelService.php');
        self::assertStringContainsString('private const MAX_LIST_COUNT = 10000', $dashboardService);
        self::assertStringContainsString('FROM pg_stat_user_tables', $dashboardService);
        self::assertStringContainsString('private const STORAGE_EXACT_COUNT_MAX_BYTES = 2097152', $dashboardService);
        self::assertStringContainsString('pg_relation_size(relid)', $dashboardService);
        self::assertStringContainsString("'rows_exact' => \$useExactCount", $dashboardService);
        self::assertStringContainsString("date_bin(INTERVAL '5 minutes'", $dashboardService);
        self::assertStringContainsString('s.user_id IS NOT NULL AND s.last_activity>=:minimum', $dashboardService);
        self::assertStringContainsString("public const PAGE_SIZES = [25, 50, 100]", $dashboardService);
        self::assertStringContainsString("public const VIEWS = ['active', 'open', 'acknowledged', 'resolved', 'sessions', 'login_attempts', 'logs', 'archive']", $dashboardService);
        self::assertStringContainsString("private const ACTIVITY_PREVIEW = 20", $dashboardService);
        self::assertStringNotContainsString('LIMIT 50', $dashboardService);
        $scaling = (string)file_get_contents(
            dirname(__DIR__, 2) . '/database/postgresql/migrations/20260811_014_sentinel_panel_scaling.sql',
        );
        self::assertStringContainsString('idx_security_alerts_status_severity_changed', $scaling);
        self::assertStringContainsString('idx_auth_login_events_sentinel_page', $scaling);
        self::assertStringContainsString('idx_security_events_action_actor_time', $scaling);
        $compose = (string)file_get_contents(dirname(__DIR__, 2) . '/compose.yaml');
        self::assertStringContainsString('max-size: "10m"', $compose);
        self::assertStringContainsString('max-file: "3"', $compose);
    }

    public function testPanelIsLocalizedAndDoesNotRenderRawAuditPayloads(): void
    {
        $catalog = json_decode(
            (string)file_get_contents(dirname(__DIR__, 2) . '/resources/lang/dors3.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        foreach (['pl', 'en', 'de', 'fr', 'it', 'es'] as $language) {
            self::assertNotEmpty($catalog[$language]['sentinel']['title'] ?? null);
            self::assertNotEmpty($catalog[$language]['sentinel']['notification_subject'] ?? null);
            self::assertNotEmpty($catalog[$language]['events']['actions']['sentinel.alert.resolved'] ?? null);
            self::assertNotEmpty($catalog[$language]['sentinel']['view_archive'] ?? null);
            self::assertNotEmpty($catalog[$language]['sentinel']['resolution_verified_safe'] ?? null);
            self::assertNotEmpty($catalog[$language]['events']['actions']['sentinel.logs.archive'] ?? null);
            self::assertNotEmpty($catalog[$language]['events']['actions']['sentinel.archive.queued'] ?? null);
            self::assertNotEmpty($catalog[$language]['resources']['security_event_archive'] ?? null);
            self::assertNotEmpty($catalog[$language]['sentinel']['archive_queued'] ?? null);
            self::assertNotEmpty($catalog[$language]['sentinel']['storage_title'] ?? null);
            self::assertNotEmpty($catalog[$language]['sentinel']['view_sessions'] ?? null);
            self::assertNotEmpty($catalog[$language]['sentinel']['view_login_attempts'] ?? null);
            self::assertNotEmpty($catalog[$language]['sentinel']['open_separate_window'] ?? null);
            self::assertNotEmpty($catalog[$language]['sentinel']['pulse_new_events'] ?? null);
            self::assertNotEmpty($catalog[$language]['sentinel']['exact_entries'] ?? null);
        }
        self::assertSame('STATUS WARTOWNIKA', $catalog['pl']['sentinel']['protection_status'] ?? null);
        self::assertSame('SENTINEL STATUS', $catalog['en']['sentinel']['protection_status'] ?? null);

        $view = (string)file_get_contents(dirname(__DIR__, 2) . '/views/admin/dors3_sentinel.php');
        self::assertStringNotContainsString("['metadata']", $view);
        self::assertStringNotContainsString("['before_state']", $view);
        self::assertStringNotContainsString("['after_state']", $view);
        self::assertStringNotContainsString('aria-label="Language"', $view);
        self::assertStringNotContainsString('aria-label="Pagination"', $view);
        self::assertStringContainsString("\$tr('alerts_human_description')", $view);
        self::assertStringContainsString("\$tr('resolution_reason')", $view);
        self::assertStringNotContainsString('name="reason"', $view);
        self::assertStringContainsString('target="_blank" rel="noopener"', $view);
        self::assertStringContainsString('data-sentinel-pulse', $view);
        self::assertStringContainsString('60000', $view);
        self::assertFileExists(dirname(__DIR__, 2) . '/views/layouts/sentinel_standalone.php');
        $standalone = (string)file_get_contents(dirname(__DIR__, 2) . '/views/layouts/sentinel_standalone.php');
        self::assertStringNotContainsString('site-header', $standalone);
        self::assertStringContainsString('noindex,nofollow', $standalone);
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
        self::assertStringContainsString('.zs-sentinel-dialog select,.zs-sentinel-dialog textarea{width:100%;border:1px solid #3a3a40!important;background:#0b0b0d!important', $sentinelCss);
        self::assertStringContainsString('.zs-sentinel-tabs a.is-active{color:#fff;background:#211114', $sentinelCss);
        self::assertStringContainsString('background:#101012!important;color:#ddd!important', $sentinelCss);
        self::assertStringContainsString('.zs-sentinel-table{font-size:12.5px}', $sentinelCss);
        self::assertStringContainsString('.zs-sentinel-compact-log>div,.zs-sentinel-login-group>summary{', $sentinelCss);
        self::assertStringContainsString('font-size:12.5px', $sentinelCss);
        self::assertStringNotContainsString('background:#fff', $sentinelCss);
    }
}
