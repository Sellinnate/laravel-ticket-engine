<?php

declare(strict_types=1);

namespace Selli\Ticketing\Notifications;

use Selli\Ticketing\Models\SlaClock;
use Selli\Ticketing\Models\Ticket;

/**
 * Sent to the assignee when an SLA target is approaching (threshold) or breached.
 */
class SlaNotification extends TicketNotification
{
    public function __construct(Ticket $ticket, public SlaClock $clock, public bool $breached)
    {
        parent::__construct($ticket);
    }

    public function key(): string
    {
        return $this->breached ? 'sla.breached' : 'sla.threshold_reached';
    }

    public function title(): string
    {
        $state = $this->breached ? 'breached' : 'approaching';

        return "SLA {$state} on ".$this->ticket->reference;
    }

    public function body(): string
    {
        $state = $this->breached ? 'has been breached' : 'is approaching its deadline';

        return 'The '.$this->clock->target->value.' SLA target '.$state.'.';
    }
}
