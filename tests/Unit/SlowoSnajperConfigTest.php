<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\SlowoSnajperConfig;
use PHPUnit\Framework\TestCase;

final class SlowoSnajperConfigTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zrodlo-snajper-' . bin2hex(random_bytes(8));
        mkdir($this->root . DIRECTORY_SEPARATOR . 'config', 0775, true);
    }

    protected function tearDown(): void
    {
        $file = $this->root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'slowo_snajper.json';
        if (is_file($file)) {
            unlink($file);
        }
        $configDir = $this->root . DIRECTORY_SEPARATOR . 'config';
        if (is_dir($configDir)) {
            rmdir($configDir);
        }
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }

    public function testSavingAntiFraudSectionDoesNotDisableMainProtectionFlags(): void
    {
        $service = SlowoSnajperConfig::fromRoot($this->root);
        self::assertTrue($service->enabled());
        self::assertTrue($service->strictMode());
        self::assertTrue($service->auditEnabled());

        $service->saveFromAdmin([
            'anti_fraud' => [
                'enabled' => '0',
                'block_suspicious_rewards' => '0',
            ],
            'sensitivity' => [
                'risk_score_warn' => '75',
            ],
        ]);

        self::assertTrue($service->enabled());
        self::assertTrue($service->strictMode());
        self::assertTrue($service->auditEnabled());
        self::assertFalse($service->antiFraudFlag('enabled'));
        self::assertSame(75, $service->sensitivity('risk_score_warn', 0));
    }

    public function testEarningsWorkerAndPresenceSettingsHaveSafeBounds(): void
    {
        $service = SlowoSnajperConfig::fromRoot($this->root);
        $service->saveFromAdmin([
            'earnings_worker' => [
                'enabled' => '1',
                'wake_on_event' => '1',
                'safety_sweep_seconds' => '2',
                'fallback_poll_seconds' => '9999',
                'batch_limit' => '1000',
                'heartbeat_seconds' => '1',
                'presence' => [
                    'enabled' => '1',
                    'visible_tab_only' => '1',
                    'ping_seconds' => '10',
                    'ttl_seconds' => '5',
                ],
            ],
        ]);

        self::assertTrue($service->earningsWorkerEnabled());
        self::assertTrue($service->earningsWakeOnEvent());
        self::assertSame(30, $service->earningsSafetySweepSeconds());
        self::assertSame(300, $service->earningsFallbackPollSeconds());
        self::assertSame(100, $service->earningsBatchLimit());
        self::assertSame(10, $service->earningsHeartbeatSeconds());
        self::assertSame(30, $service->earningsPresencePingSeconds());
        self::assertSame(40, $service->earningsPresenceTtlSeconds());
        self::assertTrue($service->earningsRequiresPresence('day_visit_bonus'));
        self::assertFalse($service->earningsRequiresPresence('article_read_bonus'));
        self::assertTrue($service->articleReadProofEnabled());
        self::assertSame(30, $service->articleReadMinimumVisibleSeconds());
        self::assertSame(60, $service->articleReadMinimumProgressPercent());
        self::assertSame(1800, $service->articleReadProofTtlSeconds());
    }
}
