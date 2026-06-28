<?php

declare(strict_types=1);

namespace Selli\Ticketing\Listeners;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Collaboration\MentionParser;
use Selli\Ticketing\Contracts\MentionResolver;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Events\MessagePosted;
use Selli\Ticketing\Events\ParticipantAdded;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketParticipant;
use Selli\Ticketing\Support\Ticketing;

/**
 * Resolves @mentions in messages and adds the mentioned actors as collaborators.
 */
class CollaborationSubscriber
{
    public function __construct(
        protected MentionParser $parser,
        protected MentionResolver $resolver,
    ) {}

    public function onMessage(MessagePosted $event): void
    {
        foreach ($this->parser->extract($event->message->body) as $handle) {
            $actor = $this->resolver->resolve($handle);

            if ($actor instanceof Model) {
                $this->addCollaborator($event->ticket, $actor);
            }
        }
    }

    protected function addCollaborator(Ticket $ticket, Model $actor): void
    {
        $model = Ticketing::ticketParticipantModel();

        /** @var TicketParticipant $participant */
        $participant = $model::query()->withoutTenancy()->firstOrCreate(
            [
                'ticket_id' => $ticket->getKey(),
                'participant_type' => $actor->getMorphClass(),
                'participant_id' => $actor->getKey(),
                'role' => ParticipantRole::Collaborator->value,
            ],
            array_merge($ticket->tenantAttributes(), ['notify' => true]),
        );

        if ($participant->wasRecentlyCreated) {
            ParticipantAdded::dispatch($ticket, $participant);
        }
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [MessagePosted::class => 'onMessage'];
    }
}
