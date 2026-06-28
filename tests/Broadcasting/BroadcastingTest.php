<?php

declare(strict_types=1);

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Selli\Ticketing\Broadcasting\Channels;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Events\TicketBroadcasted;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Tenancy\TenantContext;

/**
 * Capture every TicketBroadcasted the real subscriber dispatches this test. An
 * ArrayObject (not a plain array) so later appends are visible to the caller.
 *
 * @return ArrayObject<int, TicketBroadcasted>
 */
function captureBroadcasts(): ArrayObject
{
    /** @var ArrayObject<int, TicketBroadcasted> $captured */
    $captured = new ArrayObject;
    Event::listen(TicketBroadcasted::class, function (TicketBroadcasted $event) use ($captured): void {
        $captured->append($event);
    });

    return $captured;
}

it('broadcasts a minimal opened event when broadcasting is enabled', function (): void {
    $captured = captureBroadcasts();

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    expect($captured)->toHaveCount(1)
        ->and($captured[0]->action)->toBe('opened')
        ->and($captured[0]->broadcastWith()['ticket_id'])->toBe($ticket->getKey());
});

it('broadcasts a public reply to everyone but an internal note to agents only', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $captured = captureBroadcasts();

    Ticketing::for($ticket)->postMessage(makeUser(), 'public reply');
    Ticketing::for($ticket)->postMessage(makeUser(), 'internal note', MessageVisibility::Internal);

    $messageEvents = array_values(array_filter($captured->getArrayCopy(), fn (TicketBroadcasted $e): bool => $e->action === 'message.posted'));

    expect($messageEvents)->toHaveCount(2)
        ->and($messageEvents[0]->audience)->toBe(TicketBroadcasted::AUDIENCE_ALL)
        ->and($messageEvents[1]->audience)->toBe(TicketBroadcasted::AUDIENCE_AGENTS);

    // The internal note must not name the per-ticket channel a requester watches.
    $internalChannels = array_map(fn ($c): string => $c->name, $messageEvents[1]->broadcastOn());
    expect($internalChannels)->not->toContain('private-'.Channels::ticket((string) $ticket->getKey()));
});

it('broadcasts assignment, priority and lifecycle transitions', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $captured = captureBroadcasts();

    Ticketing::assign(ticket: $ticket, assignee: makeUser());
    Ticketing::changePriority($ticket, Priority::High);
    Ticketing::transition(ticket: $ticket, transition: 'resolve');
    Ticketing::transition(ticket: $ticket, transition: 'close');
    Ticketing::transition(ticket: $ticket, transition: 'reopen');

    $actions = array_map(fn (TicketBroadcasted $e): string => $e->action, $captured->getArrayCopy());

    expect($actions)->toContain('assigned')
        ->toContain('priority.changed')
        ->toContain('transitioned')
        ->toContain('resolved')
        ->toContain('closed')
        ->toContain('reopened');
});

it('registers the three private channels with authorization that delegates to the authorizer', function (): void {
    $context = app(TenantContext::class);
    $ticket = $context->forTenant(5, fn () => Ticketing::open(type: 'support', title: 'x', requester: makeUser(['tenant_id' => 5])));

    // Reach the callbacks the provider registered on the broadcaster at boot.
    $channels = (function (): array {
        /** @var Broadcaster $this */
        return $this->channels;
    })->call(Broadcast::driver());

    $patterns = Channels::patterns();
    expect($channels)->toHaveKeys([$patterns['tenantTickets'], $patterns['agent'], $patterns['ticket']]);

    $agent = makeUser(['tenant_id' => 5]);
    $this->actingAs($agent);

    $type = Channels::token($agent->getMorphClass());

    expect(($channels[$patterns['ticket']])($agent, $ticket->getKey()))->toBeTrue()
        ->and(($channels[$patterns['tenantTickets']])($agent, 5))->toBeTrue()
        ->and(($channels[$patterns['tenantTickets']])($agent, 9))->toBeFalse()
        ->and(($channels[$patterns['agent']])($agent, 5, $type, $agent->getKey()))->toBeTrue();
});
