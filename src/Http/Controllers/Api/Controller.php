<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Controllers\Api;

use Closure;
use Illuminate\Validation\ValidationException;
use Selli\Ticketing\Exceptions\AttachmentRejectedException;
use Selli\Ticketing\Exceptions\CsatException;
use Selli\Ticketing\Exceptions\TicketingException;
use Selli\Ticketing\Exceptions\TransitionNotAllowedException;
use Selli\Ticketing\Exceptions\UnknownTicketTypeException;
use Selli\Ticketing\Exceptions\UnknownTransitionException;

/**
 * Base controller for the REST API.
 *
 * Domain actions raise {@see TicketingException}
 * subclasses for caller-correctable mistakes (an unknown type, a disallowed
 * transition, a CSAT/attachment rejection). Left uncaught those surface as a
 * 500; here we translate exactly that set into a 422 validation error keyed to
 * the offending field. Tenant/security errors (CrossTenant, MissingTenant) are
 * intentionally NOT caught — they must not be reported back as input problems.
 */
abstract class Controller
{
    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function guard(string $field, Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (UnknownTicketTypeException|UnknownTransitionException|TransitionNotAllowedException|CsatException|AttachmentRejectedException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }
}
