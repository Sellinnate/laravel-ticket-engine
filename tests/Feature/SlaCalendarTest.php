<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Selli\Ticketing\Enums\SlaTarget;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\BusinessHours;
use Selli\Ticketing\Models\Holiday;
use Selli\Ticketing\Models\SlaClock;
use Selli\Ticketing\Models\SlaPolicy;
use Selli\Ticketing\Models\TicketType;

afterEach(fn () => Carbon::setTestNow());

it('computes SLA deadlines over a business-hours calendar with holidays', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-26 16:00:00', 'UTC')); // Friday 16:00

    $calendar = BusinessHours::factory()->create(); // Mon–Fri 9–18 UTC
    Holiday::query()->create([
        'business_hours_id' => $calendar->getKey(),
        'date' => '2026-06-29', // Monday is a holiday
        'name' => 'Company day',
    ]);

    SlaPolicy::factory()->create([
        'first_response_minutes' => null,
        'resolution_minutes' => 240,
        'business_hours_id' => $calendar->getKey(),
    ]);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    $clock = SlaClock::query()->withoutTenancy()
        ->where('ticket_id', $ticket->getKey())->where('target', SlaTarget::Resolution->value)->first();

    // Fri 16–18 (120m); Mon skipped (holiday); Tue 09:00 + 120m = 11:00.
    expect($clock->due_at->equalTo(Carbon::parse('2026-06-30 11:00:00', 'UTC')))->toBeTrue();
});

it('resolves SLA policy and calendar relations', function (): void {
    $calendar = BusinessHours::factory()->create();
    $type = TicketType::factory()->create();
    $policy = SlaPolicy::factory()->create([
        'ticket_type_id' => $type->getKey(),
        'business_hours_id' => $calendar->getKey(),
    ]);

    expect($policy->type->is($type))->toBeTrue()
        ->and($policy->businessHours->is($calendar))->toBeTrue()
        ->and($calendar->holidays()->count())->toBe(0);
});

it('starts no clocks when no policy matches', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    expect(SlaClock::query()->withoutTenancy()->where('ticket_id', $ticket->getKey())->count())->toBe(0);
});

it('reports clock states', function (): void {
    $clock = SlaClock::factory()->create();

    expect($clock->isRunning())->toBeTrue()
        ->and($clock->isPaused())->toBeFalse()
        ->and($clock->isCompleted())->toBeFalse();

    $clock->paused_at = now();
    expect($clock->isPaused())->toBeTrue()->and($clock->isRunning())->toBeFalse();

    $clock->completed_at = now();
    expect($clock->isCompleted())->toBeTrue();
});
