<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Selli\Ticketing\Contracts\CanActOnTickets;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Enums\TicketChannel;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Http\Requests\StoreTicketRequest;
use Selli\Ticketing\Http\Resources\TicketResource;
use Selli\Ticketing\Query\TicketQuery;

/**
 * Tickets resource. Reads are tenant-scoped by the engine's global scope, so a
 * caller only ever sees its own tenant's tickets.
 *
 * @internal
 */
class TicketController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeAny($request->user(), 'viewAny');

        $user = $request->user();
        $actor = $user instanceof Model ? $user : null;

        // Filters, sort and the per-page clamp live in TicketQuery, shared with
        // the MCP tools and exports. It only accepts scalar (or list-of-scalar)
        // values, so a malformed query string can never bind an array into SQL.
        $tickets = TicketQuery::make()->filter($request->query(), $actor);

        // A non-agent (requester) only ever lists their OWN tickets — the tenant
        // scope alone would otherwise leak every tenant ticket's metadata to any
        // requester. Agents list the whole tenant.
        if (! $user instanceof CanActOnTickets) {
            // A non-Eloquent requester has no morph identity to scope by, so it
            // must see nothing rather than the whole tenant (fail closed).
            $actor !== null
                ? $tickets->involving($actor)
                : $tickets->where(fn (Builder $query) => $query->whereRaw('1 = 0'));
        }

        $requested = $request->query('per_page', 25);

        return TicketResource::collection($tickets->paginate(is_scalar($requested) ? (int) $requested : 25));
    }

    public function show(Request $request, string $ticket): TicketResource
    {
        $ticket = $this->resolveTicket($ticket);
        $this->authorizeTicket($request->user(), 'view', $ticket);

        // Expose only public (customer-facing) messages over the generic API;
        // internal agent notes are never surfaced here.
        $ticket->load(['messages' => fn ($query) => $query->where('visibility', MessageVisibility::Public->value)]);

        return new TicketResource($ticket);
    }

    public function store(StoreTicketRequest $request): JsonResponse
    {
        $this->authorizeAny($request->user(), 'create');

        $ticket = $this->guard('type', fn () => Ticketing::open(
            type: (string) $request->string('type'),
            title: (string) $request->string('title'),
            requester: $request->user(),
            priority: $request->filled('priority') ? Priority::from($request->integer('priority')) : null,
            category: $request->input('category'),
            channel: TicketChannel::Api,
        ));

        return (new TicketResource($ticket))->response()->setStatusCode(201);
    }
}
