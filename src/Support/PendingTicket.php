<?php

declare(strict_types=1);

namespace Selli\Ticketing\Support;

use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Enums\Priority;
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
        Priority $priority = Priority::Normal,
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

    protected function ticket(): Ticket
    {
        if (! $this->target instanceof Ticket) {
            throw new \LogicException('This operation requires Ticketing::for() to be called with a Ticket instance.');
        }

        return $this->target;
    }
}
