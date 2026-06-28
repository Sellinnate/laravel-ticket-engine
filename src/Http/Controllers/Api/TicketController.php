<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
        $query = TicketingManager::ticketModel()::query();

        foreach (['status', 'category'] as $field) {
            if (($value = $request->query($field)) !== null) {
                $query->where($field, $value);
            }
        }

        if (($priority = $request->query('priority')) !== null) {
            $query->where('priority', (int) $priority);
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        return TicketResource::collection($query->latest()->paginate($perPage));
    }

    public function show(Ticket $ticket): TicketResource
    {
        // Expose only public (customer-facing) messages over the generic API;
        // internal agent notes are never surfaced here.
        $ticket->load(['messages' => fn ($query) => $query->where('visibility', MessageVisibility::Public->value)]);

        return new TicketResource($ticket);
    }

    public function store(StoreTicketRequest $request): JsonResponse
    {
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
