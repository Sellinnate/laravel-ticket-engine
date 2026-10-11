<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mail;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Contacts\ContactDirectory;
use Selli\Ticketing\Contracts\InboundRequesterResolver;

/**
 * Default when contacts.inbound_email is on: the sender becomes (or is found
 * as) a Contact in the receiving tenant, so the ticket has a real, notifiable
 * requester. A sender address that is not a valid email resolves to nobody.
 */
class ContactInboundRequesterResolver implements InboundRequesterResolver
{
    public function __construct(protected ContactDirectory $contacts) {}

    public function resolve(InboundEmail $email): ?Model
    {
        if (ContactDirectory::normaliseEmail($email->from) === null) {
            return null;
        }

        return $this->contacts->remember($email->from, $email->fromName);
    }
}
