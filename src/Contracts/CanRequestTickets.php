<?php

declare(strict_types=1);

namespace Selli\Ticketing\Contracts;

/**
 * An actor that can open and own tickets (a customer, an employee, a system).
 *
 * Identity is agnostic: this contract can be implemented by the host's `User`
 * model, or by a distinct `Customer` model — the package never assumes a single
 * users table.
 */
interface CanRequestTickets
{
    /**
     * A human readable label for this requester, used in notifications/audit.
     */
    public function requesterLabel(): string;

    /**
     * The primary email of the requester, if any, used for the email channel.
     */
    public function requesterEmail(): ?string;
}
