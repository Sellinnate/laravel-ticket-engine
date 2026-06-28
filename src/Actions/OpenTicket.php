<?php

declare(strict_types=1);

namespace Selli\Ticketing\Actions;

use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Data\OpenTicketData;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Events\TicketOpened;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketType;
use Selli\Ticketing\Support\AuditLogger;
use Selli\Ticketing\Support\ReferenceGenerator;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Support\TicketTypeRegistry;
use Selli\Ticketing\Tenancy\TenantContext;

/**
 * Creates a ticket: resolves its type & initial workflow state, generates a
 * unique per-tenant reference, registers the requester, writes the audit entry
 * and emits {@see TicketOpened}. The unit of work the facade and helpers wrap.
 */
class OpenTicket
{
    public function __construct(
        protected TicketTypeRegistry $types,
        protected ReferenceGenerator $references,
        protected AuditLogger $audit,
        protected TenantContext $tenant,
    ) {}

    public function handle(OpenTicketData $data): Ticket
    {
        if ($data->tenantId !== null) {
            return $this->tenant->forTenant(
                $data->tenantId,
                fn (): Ticket => $this->create($data),
            );
        }

        return $this->create($data);
    }

    protected function create(OpenTicketData $data): Ticket
    {
        $type = $this->types->resolve($data->type);
        $initialState = $this->initialState($type->workflow);

        $ticket = DB::transaction(function () use ($data, $type, $initialState): Ticket {
            $ticket = $this->persistTicket($data, $type, $initialState);

            if ($data->requester !== null) {
                $this->attachRequester($ticket, $data);
            }

            $this->audit->record(
                ticket: $ticket,
                event: 'ticket.opened',
                actor: $data->requester,
                context: ['type' => $type->key, 'status' => $initialState],
            );

            return $ticket;
        });

        TicketOpened::dispatch($ticket, $data->requester);

        return $ticket;
    }

    protected function persistTicket(
        OpenTicketData $data,
        TicketType $type,
        string $initialState,
    ): Ticket {
        $model = Ticketing::ticketModel();

        $base = $data->attributes;
        // Callers may not set engine-managed columns nor smuggle a tenant in
        // through extra attributes (which would bypass tenant scoping).
        unset(
            $base['reference'],
            $base['status'],
            $base['ticket_type_id'],
            $base[$this->tenant->column()],
        );

        $payload = array_merge($base, [
            'ticket_type_id' => $type->getKey(),
            'title' => $data->title,
            // Fall back to the type's configured default priority when the
            // caller did not specify one.
            'priority' => $data->priority ?? $type->default_priority,
            'category' => $data->category,
            'status' => $initialState,
            // References are allocated atomically (see ReferenceGenerator), so a
            // single insert is collision-free under concurrency.
            'reference' => $this->references->generate($type->key),
        ]);

        if ($data->subject !== null) {
            $payload['subject_type'] = $data->subject->getMorphClass();
            $payload['subject_id'] = $data->subject->getKey();
        }

        /** @var Ticket $ticket */
        $ticket = $model::query()->create($payload);

        return $ticket;
    }

    protected function attachRequester(Ticket $ticket, OpenTicketData $data): void
    {
        $model = Ticketing::ticketParticipantModel();

        $model::query()->firstOrCreate([
            'ticket_id' => $ticket->getKey(),
            'participant_type' => $data->requester?->getMorphClass(),
            'participant_id' => $data->requester?->getKey(),
            'role' => ParticipantRole::Requester->value,
        ], array_merge($ticket->tenantAttributes(), [
            'notify' => true,
        ]));
    }

    protected function initialState(string $workflow): string
    {
        $initial = config("ticketing.workflow.workflows.{$workflow}.initial");

        if (! is_string($initial) || $initial === '') {
            throw new InvalidConfigurationException(
                "Workflow [{$workflow}] has no initial state configured."
            );
        }

        return $initial;
    }
}
