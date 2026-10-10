<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Controllers\Api;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Selli\Ticketing\Exceptions\AttachmentRejectedException;
use Selli\Ticketing\Exceptions\CsatException;
use Selli\Ticketing\Exceptions\EscalationNotAllowedException;
use Selli\Ticketing\Exceptions\TicketingException;
use Selli\Ticketing\Exceptions\TransitionNotAllowedException;
use Selli\Ticketing\Exceptions\UnknownTicketTypeException;
use Selli\Ticketing\Exceptions\UnknownTransitionException;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\Ticketing;

/**
 * Base controller for the REST API.
 *
 * Domain actions raise {@see TicketingException}
 * subclasses for caller-correctable mistakes (an unknown type, a disallowed
 * transition, a CSAT/attachment rejection). Left uncaught those surface as a
 * 500; here we translate exactly that set into a 422 validation error keyed to
 * the offending field. Tenant/security errors (CrossTenant, MissingTenant) are
 * intentionally NOT caught — they must not be reported back as input problems.
 *
 * @internal
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
        } catch (UnknownTicketTypeException|UnknownTransitionException|TransitionNotAllowedException|CsatException|AttachmentRejectedException|EscalationNotAllowedException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }

    /**
     * Resolve the {ticket} route key through the *configured* model (honouring a
     * host's useTicketModel()/config override) rather than a base-class implicit
     * binding. query() applies the tenant global scope, so a cross-tenant key
     * 404s via ModelNotFoundException. Done here, not via a global Route::bind,
     * so the package never leaks a binder onto the host's own {ticket} routes.
     */
    protected function resolveTicket(string $key): Ticket
    {
        self::assertKey($key);

        /** @var Ticket $ticket */
        $ticket = Ticketing::ticketModel()::query()->findOrFail($key);

        return $ticket;
    }

    /**
     * A route key that cannot be a primary key of the configured type is a 404
     * up front — PostgreSQL would otherwise fail the bigint cast with a 500.
     */
    protected static function assertKey(string $key): void
    {
        $valid = config('ticketing.ids.type') === 'ulid'
            ? preg_match('/^[0-9A-Za-z]{26}$/', $key) === 1
            : filter_var($key, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;

        if (! $valid) {
            throw (new ModelNotFoundException)->setModel(Ticketing::ticketModel());
        }
    }

    /**
     * A validated route/body key in the configured id type (int for
     * auto-increment ids), so responses serialise it consistently.
     */
    protected static function castKey(string $key): int|string
    {
        return config('ticketing.ids.type') === 'ulid' ? $key : (int) $key;
    }

    /**
     * The authenticated user as an Eloquent model (the package's actor type).
     */
    protected function actor(Request $request): ?Model
    {
        $user = $request->user();

        return $user instanceof Model ? $user : null;
    }

    /**
     * Authorize an ability against the ticket via the package's TicketPolicy
     * (or a host's own). Throws AuthorizationException → 403 when denied. A host
     * that turns policy registration off effectively allows everything here.
     */
    protected function authorizeTicket(?Authenticatable $user, string $ability, Ticket $ticket): void
    {
        Gate::forUser($user)->authorize($ability, $ticket);
    }

    /**
     * Authorize a ticket ability that has no specific model yet (viewAny/create).
     */
    protected function authorizeAny(?Authenticatable $user, string $ability): void
    {
        Gate::forUser($user)->authorize($ability, Ticketing::ticketModel());
    }
}
