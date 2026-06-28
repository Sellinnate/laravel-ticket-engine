<?php

declare(strict_types=1);

namespace Selli\Ticketing\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Selli\Ticketing\Contracts\Ticketable;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\Ticketing;

/**
 * Convenience trait for host models that are the subject of tickets.
 *
 * Provides the inverse polymorphic relation plus an `openTicket()` helper so a
 * subject reads idiomatically: `$order->openTicket(type: 'incident', ...)`.
 *
 * @phpstan-require-implements Ticketable
 */
trait HasTickets
{
    /**
     * The tickets for which this model is the primary subject.
     *
     * @return MorphMany<Ticket, $this>
     */
    public function tickets(): MorphMany
    {
        return $this->morphMany(Ticketing::ticketModel(), 'subject');
    }

    /**
     * Open a new ticket with this model as its subject.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function openTicket(
        string $type,
        string $title,
        mixed $requester = null,
        ?Priority $priority = null,
        array $attributes = [],
    ): Ticket {
        return app(Ticketing::class)->for($this)->open(
            type: $type,
            title: $title,
            requester: $requester,
            priority: $priority,
            attributes: $attributes,
        );
    }

    /**
     * A human readable label for this subject. Override for a richer label.
     */
    public function ticketableLabel(): string
    {
        $key = method_exists($this, 'getKey') ? $this->getKey() : null;

        return class_basename($this).($key !== null ? " #{$key}" : '');
    }
}
