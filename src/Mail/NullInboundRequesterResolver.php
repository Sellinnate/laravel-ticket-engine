<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mail;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Contracts\InboundRequesterResolver;

/**
 * Default: no host model is associated with the sender. The ticket is still
 * created and the sender address is kept in the message meta.
 */
class NullInboundRequesterResolver implements InboundRequesterResolver
{
    public function resolve(InboundEmail $email): ?Model
    {
        return null;
    }
}
