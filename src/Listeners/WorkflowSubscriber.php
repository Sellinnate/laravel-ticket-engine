<?php

declare(strict_types=1);

namespace Selli\Ticketing\Listeners;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Actions\TransitionTicket;
use Selli\Ticketing\Ai\AiRequestFactory;
use Selli\Ticketing\Contracts\WorkflowDriver;
use Selli\Ticketing\Data\TransitionData;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Events\MessagePosted;
use Selli\Ticketing\Exceptions\TicketingException;
use Selli\Ticketing\Models\Contact;
use Selli\Ticketing\Support\Ticketing;

/**
 * Reopens a resolved/closed ticket when its requester replies within the
 * configured window (`workflow.reopen_on_reply`), through the workflow's own
 * reopen transition — so guards, audit, SLA and events behave as for a manual
 * reopen. A reply after the window, or on a state the transition cannot leave,
 * changes nothing.
 *
 * @internal
 */
class WorkflowSubscriber implements Subscriber
{
    public function __construct(
        protected Container $container,
        protected AiRequestFactory $requests,
    ) {}

    public function onMessage(MessagePosted $event): void
    {
        $message = $event->message;
        $ticket = $event->ticket;

        if ($message->visibility !== MessageVisibility::Public || $message->author_id === null) {
            return;
        }

        $author = $message->author;

        if (! $author instanceof Model || (! $author instanceof Contact && ! $this->requests->isRequester($ticket, $author))) {
            return;
        }

        $transition = (string) config('ticketing.workflow.reopen_on_reply.transition', 'reopen');
        $days = (int) config('ticketing.workflow.reopen_on_reply.within_days', 7);
        $closedAt = $ticket->resolved_at ?? $ticket->closed_at;

        if ($closedAt === null || ($days > 0 && $closedAt->lt(now()->subDays($days)))) {
            return;
        }

        $workflow = $this->workflowOf($ticket->ticket_type_id);

        /** @var WorkflowDriver $driver */
        $driver = $this->container->make(WorkflowDriver::class);

        if (! $driver->matchesSemantic($workflow, $ticket->status, 'closed') || ! $driver->canApply($workflow, $ticket->status, $transition)) {
            return;
        }

        try {
            $this->container->make(TransitionTicket::class)->handle(new TransitionData(
                ticket: $ticket,
                transition: $transition,
                actor: $author,
                note: 'Reopened by a reply from the requester.',
            ));
        } catch (TicketingException $exception) {
            // A guard refused it, or a concurrent transition won: leave it.
            report($exception);
        }
    }

    protected function workflowOf(int|string|null $typeId): string
    {
        $workflow = Ticketing::ticketTypeModel()::query()->withoutTenancy()->whereKey($typeId)->value('workflow');

        return is_string($workflow) && $workflow !== '' ? $workflow : 'default';
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [MessagePosted::class => 'onMessage'];
    }
}
