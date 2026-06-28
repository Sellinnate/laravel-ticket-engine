<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Collaboration\MentionParser;
use Selli\Ticketing\Contracts\MentionResolver;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Events\MessagePosted;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Listeners\CollaborationSubscriber;
use Selli\Ticketing\Models\CannedResponse;
use Selli\Ticketing\Models\Macro;
use Selli\Ticketing\Models\Tag;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Models\TicketParticipant;

it('tags and untags a ticket idempotently', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    Ticketing::for($ticket)->tag(['Urgent', 'VIP']);
    Ticketing::for($ticket)->tag('Urgent'); // idempotent

    expect($ticket->tags()->count())->toBe(2)
        ->and(Tag::query()->count())->toBe(2);

    Ticketing::for($ticket)->untag('VIP');
    expect($ticket->fresh()->tags()->count())->toBe(1);
});

it('renders a canned response with placeholders', function (): void {
    $canned = CannedResponse::factory()->create(['body' => 'Hi {{requester.name}}, ticket {{ticket.reference}}.']);

    $rendered = $canned->render(['requester' => ['name' => 'Ada'], 'ticket' => ['reference' => 'SUP-1']]);

    expect($rendered)->toBe('Hi Ada, ticket SUP-1.');
});

it('applies a macro (reply + transition + tags)', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $agent = makeUser(['name' => 'Agent']);

    $macro = Macro::factory()->create(['actions' => [
        'reply' => ['body' => 'Resolved for you', 'visibility' => 'public'],
        'transition' => 'resolve',
        'tags' => ['handled'],
    ]]);

    Ticketing::for($ticket)->applyMacro($macro, $agent);

    $ticket = $ticket->fresh();
    expect($ticket->status)->toBe('resolved')
        ->and($ticket->messages()->count())->toBe(1)
        ->and($ticket->tags()->count())->toBe(1);
});

it('adds a mentioned actor as a collaborator', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $mentioned = makeUser(['name' => 'Bob']);

    $resolver = new class($mentioned) implements MentionResolver
    {
        public function __construct(private Model $user) {}

        public function resolve(string $handle): ?Model
        {
            return $handle === 'bob' ? $this->user : null;
        }
    };

    $subscriber = new CollaborationSubscriber(new MentionParser, $resolver);
    $message = TicketMessage::factory()->internal()->create(['ticket_id' => $ticket->getKey(), 'body' => 'cc @bob please review']);

    $subscriber->onMessage(new MessagePosted($ticket, $message));

    expect(TicketParticipant::query()
        ->where('ticket_id', $ticket->getKey())
        ->where('role', ParticipantRole::Collaborator->value)
        ->where('participant_id', (string) $mentioned->getKey())
        ->exists())->toBeTrue();
});

it('parses mention handles from a body', function (): void {
    expect((new MentionParser)->extract('hey @ada and @bob-1, not email@example.com'))
        ->toBe(['ada', 'bob-1']);
});
