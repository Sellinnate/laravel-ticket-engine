<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Selli\Ticketing\Enums\SlaTarget;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\BusinessHours;
use Selli\Ticketing\Models\Holiday;
use Selli\Ticketing\Models\SlaClock;
use Selli\Ticketing\Models\SlaPolicy;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketType;
use Selli\Ticketing\Sla\CalendarResolver;
use Selli\Ticketing\Sla\SlaPolicyResolver;
use Selli\Ticketing\Tenancy\TenantContext;

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

it('persists the budget and calendar onto the clock', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-26 16:00:00', 'UTC'));
    $calendar = BusinessHours::factory()->create();
    SlaPolicy::factory()->create(['first_response_minutes' => null, 'resolution_minutes' => 240, 'business_hours_id' => $calendar->getKey()]);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $clock = SlaClock::query()->withoutTenancy()->where('ticket_id', $ticket->getKey())->first();

    expect($clock->budget_minutes)->toBe(240)
        ->and($clock->business_hours_id)->toBe($calendar->getKey());
});

it('uses the clock calendar for pause math even after the policy calendar changes', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-26 16:00:00', 'UTC')); // Friday 16:00
    $calendar = BusinessHours::factory()->create(); // Mon–Fri 9–18
    $policy = SlaPolicy::factory()->create(['first_response_minutes' => null, 'resolution_minutes' => 240, 'business_hours_id' => $calendar->getKey()]);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser()); // due Mon 11:00 (office)

    // Detach the calendar from the policy (would resolve to 24/7) WITHOUT recalc.
    $policy->update(['business_hours_id' => null]);

    Carbon::setTestNow(Carbon::parse('2026-06-26 16:30:00', 'UTC'));
    Ticketing::for($ticket)->transition('wait'); // pause

    $clock = SlaClock::query()->withoutTenancy()->where('ticket_id', $ticket->getKey())->first();

    // Remaining over OFFICE hours: Fri 16:30–18:00 (90m) + Mon 09:00–11:00 (120m) = 210m.
    // (A 24/7 fallback would give a far larger value.)
    expect($clock->remaining_minutes)->toBe(210);
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

it('applies tenant-specific holidays without ambient tenant context', function (): void {
    $context = app(TenantContext::class);

    $calendar = $context->forTenant(3, function () {
        $calendar = BusinessHours::factory()->create(); // tenant 3, Mon–Fri 9–18
        Holiday::query()->create(['business_hours_id' => $calendar->getKey(), 'date' => '2026-06-29', 'tenant_id' => 3]);

        return $calendar;
    });

    // Resolve the calendar with NO ambient tenant context (CLI/queue).
    $working = app(CalendarResolver::class)->forModel($calendar);

    // Monday 2026-06-29 10:00 would normally be open, but the tenant holiday closes it.
    expect($working->isOpenAt(CarbonImmutable::parse('2026-06-29 10:00:00', 'UTC')))->toBeFalse();
});

it('resolves tenant-specific policies without ambient tenant context', function (): void {
    $context = app(TenantContext::class);

    $ticket = $context->forTenant(3, function () {
        SlaPolicy::factory()->create(['tenant_id' => 3, 'name' => 't3', 'resolution_minutes' => 480]);

        return Ticketing::open(type: 'support', title: 'x', requester: makeUser(['tenant_id' => 3]));
    });

    // No ambient tenant context (queue/CLI).
    $loaded = Ticket::query()->withoutTenancy()->find($ticket->getKey());
    $policy = app(SlaPolicyResolver::class)->resolve($loaded);

    expect($policy)->not->toBeNull()
        ->and($policy->name)->toBe('t3');
});

it('throws when a policy references a missing calendar', function (): void {
    app(CalendarResolver::class)->forBusinessHoursId(999999);
})->throws(InvalidConfigurationException::class);

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
