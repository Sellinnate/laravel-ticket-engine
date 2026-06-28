<?php

declare(strict_types=1);

namespace Selli\Ticketing\Enums;

/**
 * The role a participant plays on a ticket.
 *
 * A ticket can have many participants with distinct roles without binding to a
 * single user model — this is what lets the package mount on both a B2C SaaS
 * and an internal back-office with two different user populations.
 */
enum ParticipantRole: string
{
    /** The actor who opened/owns the request. */
    case Requester = 'requester';

    /** The agent currently responsible for working the ticket. */
    case Assignee = 'assignee';

    /** Receives notifications without being responsible. */
    case Watcher = 'watcher';

    /** An additional agent who may act on the ticket. */
    case Collaborator = 'collaborator';

    /** Carbon-copied actor, notified on public correspondence. */
    case Cc = 'cc';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
