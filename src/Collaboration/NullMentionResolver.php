<?php

declare(strict_types=1);

namespace Selli\Ticketing\Collaboration;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Contracts\MentionResolver;

/**
 * Default mention resolver: resolves nobody. Host apps bind their own.
 */
class NullMentionResolver implements MentionResolver
{
    public function resolve(string $handle): ?Model
    {
        return null;
    }
}
