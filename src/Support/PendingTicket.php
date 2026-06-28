<?php

declare(strict_types=1);

namespace Selli\Ticketing\Support;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;

/**
 * Fluent helper returned by `Ticketing::for(...)`.
 *
 * When constructed from a subject (a Ticketable host model), `open()` creates a
 * ticket about it. When constructed from an existing Ticket, the action methods
 * operate on that ticket.
 */
class PendingTicket
{
    public function __construct(
        protected Ticketing $manager,
        protected mixed $target,
    ) {}

    /**
     * Open a ticket about the bound subject.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function open(
        string $type,
        string $title,
        mixed $requester = null,
        ?Priority $priority = null,
        ?string $category = null,
        array $attributes = [],
    ): Ticket {
        return $this->manager->open(
            type: $type,
            title: $title,
            requester: $requester,
            priority: $priority,
            subject: $this->target instanceof Ticket ? null : $this->target,
            category: $category,
            attributes: $attributes,
        );
    }

    /**
     * Post a message to the bound ticket.
     *
     * @param  array<string, mixed>  $meta
     */
    public function postMessage(
        mixed $author,
        string $body,
        MessageVisibility $visibility = MessageVisibility::Public,
        array $meta = [],
    ): TicketMessage {
        return $this->manager->postMessage(
            ticket: $this->ticket(),
            author: $author,
            body: $body,
            visibility: $visibility,
            meta: $meta,
        );
    }

    /**
     * Apply a workflow transition to the bound ticket.
     *
     * @param  array<string, mixed>  $params
     */
    public function transition(
        string $transition,
        ?Model $actor = null,
        ?string $note = null,
        array $params = [],
    ): Ticket {
        return $this->manager->transition(
            ticket: $this->ticket(),
            transition: $transition,
            actor: $actor,
            note: $note,
            params: $params,
        );
    }

    /**
     * Assign the bound ticket to a specific agent.
     */
    public function assignTo(Model $assignee, ?Model $actor = null): Ticket
    {
        return $this->manager->assign(ticket: $this->ticket(), assignee: $assignee, actor: $actor);
    }

    /**
     * Assign the bound ticket to a team, letting the strategy pick the agent.
     */
    public function assignToTeam(Team $team, ?string $strategy = null, ?Model $actor = null): Ticket
    {
        return $this->manager->assign(ticket: $this->ticket(), team: $team, strategy: $strategy, actor: $actor);
    }

    protected function ticket(): Ticket
    {
        if (! $this->target instanceof Ticket) {
            throw new \LogicException('This operation requires Ticketing::for() to be called with a Ticket instance.');
        }

        return $this->target;
    }
}
