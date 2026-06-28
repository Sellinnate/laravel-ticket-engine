<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Controllers\Api;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Selli\Ticketing\Contracts\CanActOnTickets;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Http\Requests\StoreTicketRequest;
use Selli\Ticketing\Http\Resources\TicketResource;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\Ticketing as TicketingManager;

/**
 * Tickets resource. Reads are tenant-scoped by the engine's global scope, so a
 * caller only ever sees its own tenant's tickets.
 */
class TicketController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeAny($request->user(), 'viewAny');

        $query = TicketingManager::ticketModel()::query();

        // A non-agent (requester) only ever lists their OWN tickets — the tenant
        // scope alone would otherwise leak every tenant ticket's metadata to any
        // requester. Agents list the whole tenant.
        $user = $request->user();
        if ($user instanceof Model && ! $user instanceof CanActOnTickets) {
            $query->where($this->ownTicketConstraint($user));
        }

        // Only scalar filter values: an array query param (?status[]=x) handed to
        // where() would bind an array as a scalar and blow up with a 500.
        foreach (['status', 'category'] as $field) {
            $value = $request->query($field);

            if (is_scalar($value)) {
                $query->where($field, (string) $value);
            }
        }

        if (is_scalar($priority = $request->query('priority'))) {
            $query->where('priority', (int) $priority);
        }

        $requested = $request->query('per_page', 25);
        $perPage = min(100, max(1, is_scalar($requested) ? (int) $requested : 25));

        return TicketResource::collection($query->latest()->paginate($perPage));
    }

    /**
     * Limit a listing to tickets the user is the subject of, or a participant on.
     *
     * @return Closure(Builder<Ticket>): void
     */
    protected function ownTicketConstraint(Model $user): Closure
    {
        return function (Builder $query) use ($user): void {
            $query
                ->where(function (Builder $subject) use ($user): void {
                    $subject->where('subject_type', $user->getMorphClass())
                        ->where('subject_id', $user->getKey());
                })
                ->orWhereHas('participants', function (Builder $participant) use ($user): void {
                    $participant->where('participant_type', $user->getMorphClass())
                        ->where('participant_id', $user->getKey());
                });
        };
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
        ));

        return (new TicketResource($ticket))->response()->setStatusCode(201);
    }
}
