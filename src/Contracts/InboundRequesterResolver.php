<?php

declare(strict_types=1);

namespace Selli\Ticketing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Mail\InboundEmail;

/**
 * Maps an inbound email's sender to a host requester model (so the ticket has a
 * real requester for notifications/authorization). Bind your own — e.g. find or
 * create a contact by email. The default resolves to null: the ticket is still
 * opened, with the sender address captured in the message meta.
 */
interface InboundRequesterResolver
{
    public function resolve(InboundEmail $email): ?Model;
}
