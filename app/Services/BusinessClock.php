<?php
declare(strict_types=1);

namespace App\Services;

final class BusinessClock
{
    private \DateTimeZone $timezone;

    public function __construct(string $timezone = 'Europe/Warsaw')
    {
        try {
            $this->timezone = new \DateTimeZone($timezone);
        } catch (\Throwable $error) {
            throw new \InvalidArgumentException('Nieprawidłowa biznesowa strefa czasu.', 0, $error);
        }
    }

    public static function fromEnvironment(): self
    {
        return new self((string)env('BUSINESS_TIMEZONE', 'Europe/Warsaw'));
    }

    public function dayKey(?\DateTimeImmutable $instant = null): string
    {
        return ($instant ?? new \DateTimeImmutable('now'))
            ->setTimezone($this->timezone)
            ->format('Y-m-d');
    }

    /** @return array{start:string,end:string} UTC timestamps for a half-open business day */
    public function dayBoundsUtc(?\DateTimeImmutable $instant = null): array
    {
        $local = ($instant ?? new \DateTimeImmutable('now'))->setTimezone($this->timezone);
        $start = $local->setTime(0, 0);
        $end = $start->modify('+1 day');
        $utc = new \DateTimeZone('UTC');

        return [
            'start' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            'end' => $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    public function timezoneName(): string
    {
        return $this->timezone->getName();
    }
}
