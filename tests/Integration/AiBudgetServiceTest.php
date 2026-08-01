<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Services\AiBudgetService;
use App\Services\AiFoundationService;

final class AiBudgetServiceTest extends DatabaseTestCase
{
    public function testReservationIsAtomicAndOverflowIsRejected(): void
    {
        $service = new AiBudgetService($this->database);
        $estimate = $service->estimate(1000, 2, 5);
        $reserved = $service->reserveAndRun($estimate, 100, static fn($db, $amount): int => $amount);
        self::assertSame(10, $reserved);
        self::assertSame(10, (int)$service->current()['reserved_minor']);

        $this->expectException(\RuntimeException::class);
        $service->reserveAndRun(91, 100, static fn(): bool => true);
    }

    public function testPartialSettingsUpdateDoesNotResetHiddenCostSetting(): void
    {
        $service = new AiFoundationService($this->database);
        $service->updateSettings([
            'ai.translation.estimated_cost_per_1k_chars_minor' => '7',
        ]);
        $service->updateSettings([
            'ai.enabled' => '1',
        ]);

        $settings = $service->settings();
        self::assertSame('1', $settings['ai.enabled']);
        self::assertSame('7', $settings['ai.translation.estimated_cost_per_1k_chars_minor']);
    }

    public function testAiNumericSettingOutsidePanelRangeIsRejected(): void
    {
        $service = new AiFoundationService($this->database);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('poza dozwolonym zakresem');
        $service->updateSettings([
            'ai.translation.daily_jobs_limit' => '1001',
        ]);
    }
}
