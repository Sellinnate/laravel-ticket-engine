<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Selli\Ticketing\Automation\ActionRunner;
use Selli\Ticketing\Automation\ConditionEvaluator;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Events\PriorityChanged;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Jobs\DeliverWebhook;
use Selli\Ticketing\Models\AutomationRule;
use Selli\Ticketing\Models\Macro;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Tenancy\TenantContext;

it('runs a matching rule action on a trigger event', function (): void {
    AutomationRule::factory()->create([
        'event' => 'ticket.opened',
        'actions' => [['type' => 'tag', 'tags' => ['auto']]],
    ]);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    expect($ticket->fresh()->tags()->pluck('slug')->all())->toContain('auto');
});

it('only fires when the conditions match', function (): void {
    AutomationRule::factory()->create([
        'event' => 'ticket.opened',
        'conditions' => [['field' => 'priority_name', 'operator' => '=', 'value' => 'urgent']],
        'actions' => [['type' => 'tag', 'tags' => ['vip']]],
    ]);

    $normal = Ticketing::open(type: 'support', title: 'n', requester: makeUser());
    $urgent = Ticketing::open(type: 'support', title: 'u', requester: makeUser(), priority: Priority::Urgent);

    expect($normal->fresh()->tags()->count())->toBe(0)
        ->and($urgent->fresh()->tags()->pluck('slug')->all())->toContain('vip');
});

it('supports any-match conditions', function (): void {
    AutomationRule::factory()->create([
        'event' => 'ticket.opened',
        'match' => 'any',
        'conditions' => [
            ['field' => 'priority_name', 'operator' => '=', 'value' => 'urgent'],
            ['field' => 'category', 'operator' => '=', 'value' => 'billing'],
        ],
        'actions' => [['type' => 'tag', 'tags' => ['flag']]],
    ]);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser(), category: 'billing');

    expect($ticket->fresh()->tags()->pluck('slug')->all())->toContain('flag');
});

it('runs rules in priority order and halts on stop_processing', function (): void {
    AutomationRule::factory()->create([
        'event' => 'ticket.opened', 'priority' => 1, 'stop_processing' => true,
        'actions' => [['type' => 'tag', 'tags' => ['first']]],
    ]);
    AutomationRule::factory()->create([
        'event' => 'ticket.opened', 'priority' => 2,
        'actions' => [['type' => 'tag', 'tags' => ['second']]],
    ]);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    $slugs = $ticket->fresh()->tags()->pluck('slug')->all();
    expect($slugs)->toContain('first')->and($slugs)->not->toContain('second');
});

it('changes priority via a rule and emits PriorityChanged', function (): void {
    Event::fake([PriorityChanged::class]);
    AutomationRule::factory()->create([
        'event' => 'ticket.opened',
        'actions' => [['type' => 'priority', 'value' => 'urgent']],
    ]);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    expect($ticket->fresh()->priority)->toBe(Priority::Urgent);
    Event::assertDispatched(PriorityChanged::class);
});

it('dispatches an outbound webhook from a rule', function (): void {
    Bus::fake([DeliverWebhook::class]);
    AutomationRule::factory()->create([
        'event' => 'ticket.opened',
        'actions' => [['type' => 'webhook', 'url' => 'https://example.test/hook']],
    ]);

    Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    Bus::assertDispatched(DeliverWebhook::class, fn (DeliverWebhook $job): bool => $job->url === 'https://example.test/hook');
});

it('does not fire rules from another tenant', function (): void {
    $context = app(TenantContext::class);
    $context->forTenant(9, fn () => AutomationRule::factory()->create([
        'tenant_id' => 9, 'event' => 'ticket.opened',
        'actions' => [['type' => 'tag', 'tags' => ['nine']]],
    ]));

    $ticket = $context->forTenant(5, fn () => Ticketing::open(type: 'support', title: 'x', requester: makeUser(['tenant_id' => 5])));

    expect($context->forTenant(5, fn () => $ticket->fresh()->tags()->count()))->toBe(0);
});

it('bounds re-entrant cascades with the depth guard', function (): void {
    config()->set('ticketing.automation.max_depth', 3);
    AutomationRule::factory()->create([
        'event' => 'message.posted',
        'actions' => [['type' => 'reply', 'body' => 'auto', 'visibility' => 'internal']],
    ]);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    Ticketing::for($ticket)->postMessage(makeUser(), 'hello'); // triggers the cascade

    // It must terminate (not hang) with a bounded number of messages.
    expect($ticket->fresh()->messages()->count())->toBeLessThanOrEqual(6);
});

it('fails closed on an unknown condition field or operator', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $evaluator = new ConditionEvaluator;

    expect(fn () => $evaluator->matches($ticket, [['field' => 'nope', 'operator' => '=', 'value' => 1]]))
        ->toThrow(InvalidConfigurationException::class);
    expect(fn () => $evaluator->matches($ticket, [['field' => 'status', 'operator' => 'bogus', 'value' => 1]]))
        ->toThrow(InvalidConfigurationException::class);
});

it('fails closed on an unknown action type', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $runner = app(ActionRunner::class);

    $runner->run($ticket, [['type' => 'launch_missiles']], null, 'ticket.opened');
})->throws(InvalidConfigurationException::class);

it('rejects an inactive team in an assign action', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $team = Team::factory()->create(['is_active' => false]);
    $runner = app(ActionRunner::class);

    $runner->run($ticket, [['type' => 'assign', 'team_id' => $team->getKey()]], null, 'ticket.opened');
})->throws(InvalidConfigurationException::class);

it('does not emit PriorityChanged when the priority is unchanged', function (): void {
    Event::fake([PriorityChanged::class]);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser(), priority: Priority::Normal);

    Ticketing::changePriority($ticket, Priority::Normal);

    Event::assertNotDispatched(PriorityChanged::class);
});

it('runs the full set of action types', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $team = Team::factory()->create(['is_active' => true]);
    $macro = Macro::factory()->create(['actions' => ['tags' => ['from-macro']]]);
    $runner = app(ActionRunner::class);

    $runner->run($ticket, [
        ['type' => 'assign', 'team_id' => $team->getKey()],
        ['type' => 'priority', 'value' => Priority::High->value], // by int
        ['type' => 'reply', 'body' => 'auto reply', 'visibility' => 'public'],
        ['type' => 'tag', 'tags' => ['handled']],
        ['type' => 'apply_macro', 'macro_id' => $macro->getKey()],
        ['type' => 'transition', 'transition' => 'resolve'],
    ], null, 'ticket.opened');

    $ticket = $ticket->fresh();
    expect((string) $ticket->team_id)->toBe((string) $team->getKey())
        ->and($ticket->priority)->toBe(Priority::High)
        ->and($ticket->status)->toBe('resolved')
        ->and($ticket->messages()->count())->toBe(1)
        ->and($ticket->tags()->pluck('slug')->all())->toContain('handled')
        ->and($ticket->tags()->pluck('slug')->all())->toContain('from-macro');
});

it('validates required action params', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $runner = app(ActionRunner::class);

    expect(fn () => $runner->run($ticket, [['type' => 'assign']], null, 'ticket.opened'))
        ->toThrow(InvalidConfigurationException::class)
        ->and(fn () => $runner->run($ticket, [['type' => 'reply', 'body' => '']], null, 'ticket.opened'))
        ->toThrow(InvalidConfigurationException::class)
        ->and(fn () => $runner->run($ticket, [['type' => 'reply', 'body' => 'hi', 'visibility' => 'bogus']], null, 'ticket.opened'))
        ->toThrow(InvalidConfigurationException::class)
        ->and(fn () => $runner->run($ticket, [['type' => 'webhook']], null, 'ticket.opened'))
        ->toThrow(InvalidConfigurationException::class)
        ->and(fn () => $runner->run($ticket, [['type' => 'priority', 'value' => 'nope']], null, 'ticket.opened'))
        ->toThrow(InvalidConfigurationException::class)
        ->and(fn () => $runner->run($ticket, [['type' => 'apply_macro', 'macro_id' => 999999]], null, 'ticket.opened'))
        ->toThrow(InvalidConfigurationException::class);
});
