<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BusinessClock;
use PHPUnit\Framework\TestCase;

final class BusinessClockTest extends TestCase
{
    public function testWarsawDayKeyDiffersFromUtcAroundMidnight(): void
    {
        $clock = new BusinessClock('Europe/Warsaw');
        $instant = new \DateTimeImmutable('2026-08-01 22:30:00', new \DateTimeZone('UTC'));

        self::assertSame('2026-08-02', $clock->dayKey($instant));
    }

    public function testDayBoundsRespectDaylightSavingTransitions(): void
    {
        $clock = new BusinessClock('Europe/Warsaw');

        self::assertSame(
            ['start' => '2026-03-28 23:00:00', 'end' => '2026-03-29 22:00:00'],
            $clock->dayBoundsUtc(new \DateTimeImmutable('2026-03-29 12:00:00', new \DateTimeZone('UTC')))
        );
        self::assertSame(
            ['start' => '2026-10-24 22:00:00', 'end' => '2026-10-25 23:00:00'],
            $clock->dayBoundsUtc(new \DateTimeImmutable('2026-10-25 12:00:00', new \DateTimeZone('UTC')))
        );
    }

    public function testInvalidTimezoneIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BusinessClock('Not/A-Timezone');
    }
}
