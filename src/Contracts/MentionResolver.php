<?php

declare(strict_types=1);

namespace Selli\Ticketing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves an @mention handle (e.g. "@ada") to a host actor model. Agnostic on
 * the user model — the host binds its own resolver. The default resolves nobody.
 */
interface MentionResolver
{
    public function resolve(string $handle): ?Model;
}
