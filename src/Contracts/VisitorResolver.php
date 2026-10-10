<?php

declare(strict_types=1);

namespace Selli\Ticketing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Conversations\VisitorIdentity;
use Selli\Ticketing\Models\WidgetKey;

/**
 * Maps a VERIFIED widget identity (valid HMAC signature) to a host model — the
 * signed-in user of the host app — so the conversation and its ticket belong to
 * that user. Return null to fall back to a Contact. Never called for unsigned
 * identities.
 */
interface VisitorResolver
{
    public function resolve(VisitorIdentity $identity, WidgetKey $key): ?Model;
}
