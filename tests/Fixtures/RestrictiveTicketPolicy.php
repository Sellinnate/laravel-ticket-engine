<?php

declare(strict_types=1);

namespace Selli\Ticketing\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Selli\Ticketing\Models\Ticket;

/**
 * A host policy that allows public comments but DENIES internal notes — used to
 * prove the API authorizes the commentInternal ability separately from comment.
 */
class RestrictiveTicketPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return true;
    }

    public function view(Authenticatable $user, Ticket $ticket): bool
    {
        return true;
    }

    public function comment(Authenticatable $user, Ticket $ticket): bool
    {
        return true;
    }

    public function commentInternal(Authenticatable $user, Ticket $ticket): bool
    {
        return false;
    }
}
