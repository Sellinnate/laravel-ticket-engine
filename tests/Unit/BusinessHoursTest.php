<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Selli\Ticketing\Sla\BusinessHours;

function officeHours(array $holidays = []): BusinessHours
{
    $week = [];
    for ($day = 1; $day <= 5; $day++) {
        $week[$day] = [['09:00', '18:00']]; // Mon–Fri 9–18 (9h = 540m)
    }

    return new BusinessHours('UTC', $week, $holidays);
}

// Anchor weekday assumptions used throughout.
$friday = CarbonImmutable::parse('2026-06-26 00:00:00', 'UTC');
expect($friday->dayOfWeekIso)->toBe(5);

it('treats an always-open calendar as solar time', function (): void {
    $bh = BusinessHours::alwaysOpen('UTC');
    $start = CarbonImmutable::parse('2026-06-26 16:00:00', 'UTC');

    expect($bh->addMinutes($start, 120)->toDateTimeString())->toBe('2026-06-26 18:00:00')
        ->and($bh->minutesBetween($start, $start->addHours(3)))->toBe(180);
});

it('returns the start unchanged for zero or negative minutes', function (): void {
    $bh = officeHours();
    $start = CarbonImmutable::parse('2026-06-26 10:00:00', 'UTC');

    expect($bh->addMinutes($start, 0)->toDateTimeString())->toBe('2026-06-26 10:00:00');
});

it('rolls a deadline over the weekend', function (): void {
    $bh = officeHours();
    // Friday 16:00 + 4 working hours → Fri 16–18 (2h) then Mon 09:00 + 2h = Mon 11:00.
    $start = CarbonImmutable::parse('2026-06-26 16:00:00', 'UTC');

    expect($bh->addMinutes($start, 240)->toDateTimeString())->toBe('2026-06-29 11:00:00');
});

it('clamps a start before opening to the first window', function (): void {
    $bh = officeHours();
    // Monday 07:00 + 60m → starts counting at 09:00 → 10:00.
    $start = CarbonImmutable::parse('2026-06-29 07:00:00', 'UTC');

    expect($bh->addMinutes($start, 60)->toDateTimeString())->toBe('2026-06-29 10:00:00');
});

it('moves a start after closing to the next working day', function (): void {
    $bh = officeHours();
    // Monday 19:00 + 60m → Tuesday 09:00 + 60m = 10:00.
    $start = CarbonImmutable::parse('2026-06-29 19:00:00', 'UTC');

    expect($bh->addMinutes($start, 60)->toDateTimeString())->toBe('2026-06-30 10:00:00');
});

it('skips holidays', function (): void {
    $bh = officeHours(['2026-06-29']); // Monday is a holiday
    // Friday 17:00 + 480m (8h): Fri 17–18 = 60m; Mon skipped; Tue 09:00 + 420m = 16:00.
    $start = CarbonImmutable::parse('2026-06-26 17:00:00', 'UTC');

    expect($bh->addMinutes($start, 480)->toDateTimeString())->toBe('2026-06-30 16:00:00');
});

it('counts only working minutes between two instants', function (): void {
    $bh = officeHours();
    // Friday 17:00 → Monday 11:00: Fri 17–18 (60m) + Mon 09–11 (120m) = 180m.
    $start = CarbonImmutable::parse('2026-06-26 17:00:00', 'UTC');
    $end = CarbonImmutable::parse('2026-06-29 11:00:00', 'UTC');

    expect($bh->minutesBetween($start, $end))->toBe(180);
});

it('reports whether the calendar is open at an instant', function (): void {
    $bh = officeHours(['2026-06-30']);

    expect($bh->isOpenAt(CarbonImmutable::parse('2026-06-29 10:00:00', 'UTC')))->toBeTrue()
        ->and($bh->isOpenAt(CarbonImmutable::parse('2026-06-29 20:00:00', 'UTC')))->toBeFalse()
        ->and($bh->isOpenAt(CarbonImmutable::parse('2026-06-27 10:00:00', 'UTC')))->toBeFalse() // Saturday
        ->and($bh->isOpenAt(CarbonImmutable::parse('2026-06-30 10:00:00', 'UTC')))->toBeFalse(); // holiday
});
