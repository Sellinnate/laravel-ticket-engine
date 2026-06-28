<?php

declare(strict_types=1);

namespace Selli\Ticketing\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Selli\Ticketing\Concerns\HasTickets;
use Selli\Ticketing\Models\Ticket;

/**
 * A host-application model that can be the subject of tickets.
 *
 * The package never requires anything on the subject's schema — no column, no
 * foreign key. The link lives entirely in the `tickets` table. A model becomes
 * "ticketable" by implementing this contract and (optionally) using the
 * {@see HasTickets} trait of convenience.
 */
interface Ticketable
{
    /**
     * The tickets for which this model is the primary subject.
     *
     * @return MorphMany<Ticket, covariant \Illuminate\Database\Eloquent\Model>
     */
    public function tickets(): MorphMany;

    /**
     * A human readable label for this subject, used in notifications and audit.
     */
    public function ticketableLabel(): string;
}
