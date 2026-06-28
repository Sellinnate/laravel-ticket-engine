<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketAttachment;

/**
 * Emitted after an attachment is added to a ticket or message.
 */
class AttachmentAdded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Ticket $ticket,
        public TicketAttachment $attachment,
    ) {}
}
