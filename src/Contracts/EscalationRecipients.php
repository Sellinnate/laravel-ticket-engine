<?php

declare(strict_types=1);

namespace Selli\Ticketing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Models\Ticket;

/**
 * Who is told when the AI (or code) hands a ticket to humans. The default
 * notifies the assignee and the leads of the ticket's team; bind your own to
 * notify, say, every supervisor of the tenant.
 */
interface EscalationRecipients
{
    /**
     * @return list<Model>
     */
    public function for(Ticket $ticket): array;
}
