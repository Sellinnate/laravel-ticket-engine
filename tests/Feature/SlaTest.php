<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Enums\SlaTarget;
use Selli\Ticketing\Events\SlaBreached;
use Selli\Ticketing\Events\SlaThresholdReached;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\SlaClock;
use Selli\Ticketing\Models\SlaPolicy;
use Selli\Ticketing\Sla\SlaManager;
use Selli\Ticketing\Sla\SlaPolicyResolver;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-29 10:00:00', 'UTC')); // Monday
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function clockFor(int|string $ticketId, SlaTarget $target): ?SlaClock
{
    return SlaClock::query()->withoutTenancy()
        ->where('ticket_id', $ticketId)->where('target', $target->value)->first();
}

it('starts first-response and resolution clocks on open', function (): void {
    SlaPolicy::factory()->create(['first_response_minutes' => 60, 'resolution_minutes' => 480]);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    $fr = clockFor($ticket->getKey(), SlaTarget::FirstResponse);
    $res = clockFor($ticket->getKey(), SlaTarget::Resolution);

    expect($fr)->not->toBeNull()
        ->and($fr->due_at->equalTo(Carbon::parse('2026-06-29 11:00:00', 'UTC')))->toBeTrue()
        ->and($res->due_at->equalTo(Carbon::parse('2026-06-29 18:00:00', 'UTC')))->toBeTrue();
});

it('completes the first-response clock on the first agent reply', function (): void {
    SlaPolicy::factory()->create(['first_response_minutes' => 60, 'resolution_minutes' => 480]);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $agent = makeUser(['name' => 'Agent']);

    Ticketing::for($ticket)->postMessage($agent, 'On it');

    expect(clockFor($ticket->getKey(), SlaTarget::FirstResponse)->isCompleted())->toBeTrue();
});

it('completes the resolution clock on resolution', function (): void {
    SlaPolicy::factory()->create(['resolution_minutes' => 480]);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    Ticketing::for($ticket)->transition('resolve');

    expect(clockFor($ticket->getKey(), SlaTarget::Resolution)->isCompleted())->toBeTrue();
});

it('pauses and resumes the clock around a pause state, extending the deadline', function (): void {
    SlaPolicy::factory()->create(['first_response_minutes' => null, 'resolution_minutes' => 480]);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    // Move forward 60m, then wait on customer (pending is a pause state).
    Carbon::setTestNow(Carbon::parse('2026-06-29 11:00:00', 'UTC'));
    Ticketing::for($ticket)->transition('wait');

    expect(clockFor($ticket->getKey(), SlaTarget::Resolution)->isPaused())->toBeTrue();

    // Resume 140m later; the deadline should shift forward by the paused span.
    Carbon::setTestNow(Carbon::parse('2026-06-29 13:20:00', 'UTC'));
    Ticketing::for($ticket)->transition('resume');

    $res = clockFor($ticket->getKey(), SlaTarget::Resolution);

    // Remaining at pause was 420m; resumed at 13:20 → due 13:20 + 420m = 20:20.
    expect($res->isRunning())->toBeTrue()
        ->and($res->due_at->equalTo(Carbon::parse('2026-06-29 20:20:00', 'UTC')))->toBeTrue();
});

it('emits SlaBreached when a deadline passes', function (): void {
    SlaPolicy::factory()->create(['first_response_minutes' => null, 'resolution_minutes' => 480]);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    Event::fake([SlaBreached::class]);
    Carbon::setTestNow(Carbon::parse('2026-06-29 18:30:00', 'UTC')); // past 18:00 deadline

    app(SlaManager::class)->sweep();

    expect(clockFor($ticket->getKey(), SlaTarget::Resolution)->breached_at)->not->toBeNull();
    Event::assertDispatched(SlaBreached::class);
});

it('emits SlaThresholdReached before breaching', function (): void {
    SlaPolicy::factory()->create(['first_response_minutes' => null, 'resolution_minutes' => 480]);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    Event::fake([SlaThresholdReached::class]);
    Carbon::setTestNow(Carbon::parse('2026-06-29 16:40:00', 'UTC')); // 400/480 = 83%

    app(SlaManager::class)->sweep(75);

    expect(clockFor($ticket->getKey(), SlaTarget::Resolution)->threshold_notified)->toBeTrue();
    Event::assertDispatched(SlaThresholdReached::class);
});

it('does not emit a threshold warning after a breach', function (): void {
    SlaPolicy::factory()->create(['first_response_minutes' => null, 'resolution_minutes' => 480]);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    // First sweep well past the deadline → breach (skips the 75% window).
    Carbon::setTestNow(Carbon::parse('2026-06-29 19:00:00', 'UTC'));
    app(SlaManager::class)->sweep(75);

    Event::fake([SlaThresholdReached::class]);
    app(SlaManager::class)->sweep(75); // second sweep

    Event::assertNotDispatched(SlaThresholdReached::class);
    expect(clockFor($ticket->getKey(), SlaTarget::Resolution)->threshold_notified)->toBeFalse();
});

it('clears the breach flag and skips paused clocks on recalculate', function (): void {
    $policy = SlaPolicy::factory()->create(['first_response_minutes' => null, 'resolution_minutes' => 60]);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    // Breach it.
    Carbon::setTestNow(Carbon::parse('2026-06-29 12:00:00', 'UTC'));
    app(SlaManager::class)->sweep();
    expect(clockFor($ticket->getKey(), SlaTarget::Resolution)->breached_at)->not->toBeNull();

    // Extend the policy and recalculate: the breach flag clears, deadline moves.
    $policy->update(['resolution_minutes' => 600]);
    app(SlaManager::class)->recalculate($ticket->fresh());

    $clock = clockFor($ticket->getKey(), SlaTarget::Resolution);
    expect($clock->breached_at)->toBeNull()
        ->and($clock->due_at->equalTo(Carbon::parse('2026-06-29 20:00:00', 'UTC')))->toBeTrue();
});

it('leaves paused clocks untouched on recalculate', function (): void {
    $policy = SlaPolicy::factory()->create(['first_response_minutes' => null, 'resolution_minutes' => 480]);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    Carbon::setTestNow(Carbon::parse('2026-06-29 11:00:00', 'UTC'));
    Ticketing::for($ticket)->transition('wait'); // pause

    $pausedDue = clockFor($ticket->getKey(), SlaTarget::Resolution)->due_at->copy();

    $policy->update(['resolution_minutes' => 60]);
    app(SlaManager::class)->recalculate($ticket->fresh());

    $clock = clockFor($ticket->getKey(), SlaTarget::Resolution);
    expect($clock->isPaused())->toBeTrue()
        ->and($clock->due_at->equalTo($pausedDue))->toBeTrue(); // unchanged
});

it('excludes paused time from the threshold calculation', function (): void {
    SlaPolicy::factory()->create(['first_response_minutes' => null, 'resolution_minutes' => 480]);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser()); // 10:00, due 18:00

    // Pause 11:00 → resume 13:20 (140m paused) → due shifts to 20:20.
    Carbon::setTestNow(Carbon::parse('2026-06-29 11:00:00', 'UTC'));
    Ticketing::for($ticket)->transition('wait');
    Carbon::setTestNow(Carbon::parse('2026-06-29 13:20:00', 'UTC'));
    Ticketing::for($ticket)->transition('resume');

    Event::fake([SlaThresholdReached::class]);

    // At 18:00, naive elapsed-since-start would be 480/620 ≈ 77% (would fire),
    // but real consumption excluding the pause is 340/480 ≈ 71% (must not fire).
    Carbon::setTestNow(Carbon::parse('2026-06-29 18:00:00', 'UTC'));
    app(SlaManager::class)->sweep(75);

    Event::assertNotDispatched(SlaThresholdReached::class);
    expect(clockFor($ticket->getKey(), SlaTarget::Resolution)->threshold_notified)->toBeFalse();
});

it('restarts the resolution clock on reopen', function (): void {
    SlaPolicy::factory()->create(['resolution_minutes' => 480]);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    Ticketing::for($ticket)->transition('resolve');

    Carbon::setTestNow(Carbon::parse('2026-06-30 09:00:00', 'UTC'));
    Ticketing::for($ticket)->transition('reopen');

    $res = clockFor($ticket->getKey(), SlaTarget::Resolution);

    expect($res->isCompleted())->toBeFalse()
        ->and($res->due_at->equalTo(Carbon::parse('2026-06-30 17:00:00', 'UTC')))->toBeTrue();
});

it('picks the most specific policy', function (): void {
    SlaPolicy::factory()->create(['name' => 'catch-all', 'resolution_minutes' => 999]);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser(), priority: Priority::High);

    // A type+priority specific policy for this ticket.
    SlaPolicy::factory()->create([
        'name' => 'specific',
        'ticket_type_id' => $ticket->ticket_type_id,
        'priority' => Priority::High->value,
        'resolution_minutes' => 30,
    ]);

    $policy = app(SlaPolicyResolver::class)->resolve($ticket->fresh());

    expect($policy->name)->toBe('specific');
});

it('emits a breach only once across repeated sweeps', function (): void {
    SlaPolicy::factory()->create(['first_response_minutes' => null, 'resolution_minutes' => 480]);
    Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    Event::fake([SlaBreached::class]);
    Carbon::setTestNow(Carbon::parse('2026-06-29 18:30:00', 'UTC'));

    app(SlaManager::class)->sweep();
    app(SlaManager::class)->sweep();

    Event::assertDispatchedTimes(SlaBreached::class, 1);
});

it('stops orphaned clocks when the ticket is gone', function (): void {
    SlaPolicy::factory()->create(['first_response_minutes' => null, 'resolution_minutes' => 480]);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    $ticket->delete(); // soft delete
    Carbon::setTestNow(Carbon::parse('2026-06-29 18:30:00', 'UTC'));

    app(SlaManager::class)->sweep();

    expect(clockFor($ticket->getKey(), SlaTarget::Resolution)->isCompleted())->toBeTrue();
});

it('stops clocks on recalculate when no active policy matches', function (): void {
    $policy = SlaPolicy::factory()->create(['resolution_minutes' => 480]);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    $policy->update(['is_active' => false]); // no active policy now
    app(SlaManager::class)->recalculate($ticket->fresh());

    expect(clockFor($ticket->getKey(), SlaTarget::Resolution)->isCompleted())->toBeTrue();
});

it('stops a clock whose target was removed from the policy on recalculate', function (): void {
    $policy = SlaPolicy::factory()->create(['first_response_minutes' => 60, 'resolution_minutes' => 480]);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    expect(clockFor($ticket->getKey(), SlaTarget::FirstResponse)->isCompleted())->toBeFalse();

    $policy->update(['first_response_minutes' => null]);
    app(SlaManager::class)->recalculate($ticket->fresh());

    expect(clockFor($ticket->getKey(), SlaTarget::FirstResponse)->isCompleted())->toBeTrue()
        ->and(clockFor($ticket->getKey(), SlaTarget::Resolution)->isCompleted())->toBeFalse();
});

it('reads the threshold percent from config when the option is omitted', function (): void {
    config()->set('ticketing.sla.default_threshold_percent', 50);
    SlaPolicy::factory()->create(['first_response_minutes' => null, 'resolution_minutes' => 480]);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    Event::fake([SlaThresholdReached::class]);
    Carbon::setTestNow(Carbon::parse('2026-06-29 14:00:00', 'UTC')); // 240/480 = 50%

    $this->artisan('ticketing:escalate')->assertSuccessful();

    // 50% would not trip the default 75% gate, proving the config value was used.
    Event::assertDispatched(SlaThresholdReached::class);
});

it('runs the escalate command', function (): void {
    SlaPolicy::factory()->create(['first_response_minutes' => null, 'resolution_minutes' => 1]);
    Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    Carbon::setTestNow(Carbon::parse('2026-06-29 12:00:00', 'UTC'));

    $this->artisan('ticketing:escalate')->assertSuccessful();
});

it('recalculates SLA deadlines via the command', function (): void {
    $policy = SlaPolicy::factory()->create(['first_response_minutes' => null, 'resolution_minutes' => 480]);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    $policy->update(['resolution_minutes' => 120]);

    $this->artisan('ticketing:recalculate-sla')->assertSuccessful();

    expect(clockFor($ticket->getKey(), SlaTarget::Resolution)->due_at->equalTo(Carbon::parse('2026-06-29 12:00:00', 'UTC')))->toBeTrue();
});
