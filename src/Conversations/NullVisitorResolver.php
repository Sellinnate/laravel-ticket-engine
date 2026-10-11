<?php

declare(strict_types=1);

namespace Selli\Ticketing\Conversations;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Contracts\VisitorResolver;
use Selli\Ticketing\Models\WidgetKey;

/**
 * Default: verified visitors become (verified) Contacts, not host models.
 */
class NullVisitorResolver implements VisitorResolver
{
    public function resolve(VisitorIdentity $identity, WidgetKey $key): ?Model
    {
        return null;
    }
}
