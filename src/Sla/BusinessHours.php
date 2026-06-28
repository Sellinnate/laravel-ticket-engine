<?php

declare(strict_types=1);

namespace Selli\Ticketing\Sla;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Computes SLA deadlines and elapsed time over *working* hours rather than
 * solar time. A ticket opened on Friday evening does not breach an "8 working
 * hours" SLA if the team works Mon–Fri 9–18. An H24 calendar is simply
 * always-open.
 *
 * The schedule is keyed by ISO weekday (1 = Monday … 7 = Sunday); each day holds
 * a list of [start, end] "HH:MM" windows. "24:00" denotes end-of-day.
 */
final class BusinessHours
{
    /**
     * @param  array<int, list<array{0: string, 1: string}>>  $schedule
     * @param  list<string>  $holidays  dates as "Y-m-d" in the business timezone
     */
    public function __construct(
        private readonly string $timezone,
        private readonly array $schedule,
        private readonly array $holidays = [],
    ) {}

    /**
     * A 24/7 calendar.
     */
    public static function alwaysOpen(string $timezone = 'UTC'): self
    {
        $week = [];
        for ($day = 1; $day <= 7; $day++) {
            $week[$day] = [['00:00', '24:00']];
        }

        return new self($timezone, $week);
    }

    /**
     * The deadline reached after consuming $minutes of working time from $start.
     */
    public function addMinutes(CarbonInterface $start, int $minutes): CarbonImmutable
    {
        $cursor = CarbonImmutable::instance($start)->setTimezone($this->timezone);

        if ($minutes <= 0) {
            return $cursor;
        }

        $remaining = $minutes;
        $guard = 0;

        while ($guard++ < 100_000) {
            if (! $this->isHoliday($cursor)) {
                foreach ($this->intervalsFor($cursor) as [$open, $close]) {
                    if ($cursor->lessThan($open)) {
                        $cursor = $open;
                    }

                    if ($cursor->greaterThanOrEqualTo($close)) {
                        continue;
                    }

                    $available = $this->minutes($cursor, $close);

                    if ($remaining <= $available) {
                        return $cursor->addMinutes($remaining);
                    }

                    $remaining -= $available;
                    $cursor = $close;
                }
            }

            $cursor = $cursor->addDay()->startOfDay();
        }

        throw new RuntimeException('Unable to compute SLA deadline within bounds.');
    }

    /**
     * Working minutes elapsed between two instants.
     */
    public function minutesBetween(CarbonInterface $start, CarbonInterface $end): int
    {
        $start = CarbonImmutable::instance($start)->setTimezone($this->timezone);
        $end = CarbonImmutable::instance($end)->setTimezone($this->timezone);

        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }

        $total = 0;
        $cursor = $start->startOfDay();
        $guard = 0;

        while ($cursor->lessThan($end) && $guard++ < 100_000) {
            if (! $this->isHoliday($cursor)) {
                foreach ($this->intervalsFor($cursor) as [$open, $close]) {
                    $segmentStart = $open->greaterThan($start) ? $open : $start;
                    $segmentEnd = $close->lessThan($end) ? $close : $end;

                    if ($segmentStart->lessThan($segmentEnd)) {
                        $total += $this->minutes($segmentStart, $segmentEnd);
                    }
                }
            }

            $cursor = $cursor->addDay()->startOfDay();
        }

        return $total;
    }

    /**
     * Whether the calendar is open at the given instant.
     */
    public function isOpenAt(CarbonInterface $at): bool
    {
        $at = CarbonImmutable::instance($at)->setTimezone($this->timezone);

        if ($this->isHoliday($at)) {
            return false;
        }

        foreach ($this->intervalsFor($at) as [$open, $close]) {
            if ($at->greaterThanOrEqualTo($open) && $at->lessThan($close)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function intervalsFor(CarbonImmutable $date): array
    {
        $windows = $this->schedule[$date->dayOfWeekIso] ?? [];
        $midnight = $date->startOfDay();
        $intervals = [];

        foreach ($windows as [$open, $close]) {
            $intervals[] = [
                $midnight->addMinutes($this->toMinutes($open)),
                $midnight->addMinutes($this->toMinutes($close)),
            ];
        }

        return $intervals;
    }

    private function toMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    private function minutes(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) $from->diffInMinutes($to);
    }

    private function isHoliday(CarbonImmutable $date): bool
    {
        return in_array($date->format('Y-m-d'), $this->holidays, true);
    }
}
