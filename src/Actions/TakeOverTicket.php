<?php

declare(strict_types=1);

namespace Selli\Ticketing\Actions;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Data\AssignTicketData;
use Selli\Ticketing\Enums\AiStatus;
use Selli\Ticketing\Events\HumanTookOver;
use Selli\Ticketing\Exceptions\CrossTenantException;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\AuditLogger;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Tenancy\TenantGuard;

/**
 * An agent takes a ticket over, at any moment: the AI stops writing on it for
 * good (its status becomes "suspended", checked under the ticket lock before
 * any AI write) and, unless told otherwise, an unassigned ticket is assigned to
 * the agent.
 */
class TakeOverTicket
{
    public function __construct(
        protected TenantGuard $tenants,
        protected AuditLogger $audit,
        protected Container $container,
    ) {}

    public function handle(Ticket $ticket, Model $agent, bool $assign = true): Ticket
    {
        if (! $this->tenants->belongsToTicketTenant($agent, $ticket)) {
            throw CrossTenantException::forAssignment('agent');
        }

        $result = DB::transaction(function () use ($ticket, $agent): array {
            /** @var Ticket $locked */
            $locked = Ticketing::ticketModel()::query()->withoutTenancy()
                ->lockForUpdate()
                ->whereKey($ticket->getKey())
                ->firstOrFail();

            $previous = $locked->ai_status;
            $handedOver = $previous === AiStatus::Active || $previous === AiStatus::Escalated;

            if ($handedOver) {
                $locked->forceFill(['ai_status' => AiStatus::Suspended])->save();

                $this->audit->record(
                    ticket: $locked,
                    event: 'ticket.taken_over',
                    actor: $agent,
                    context: ['previous_ai_status' => $previous->value],
                );
            }

            return [$locked, $previous, $handedOver];
        });

        /** @var array{0: Ticket, 1: AiStatus|null, 2: bool} $result */
        [$locked, $previous, $handedOver] = $result;

        if ($assign && $locked->assignee_id === null) {
            $locked = $this->container->make(AssignTicket::class)->handle(new AssignTicketData(
                ticket: $locked,
                assignee: $agent,
                actor: $agent,
            ));
        }

        if ($handedOver) {
            HumanTookOver::dispatch($locked, $agent, $previous);
        }

        return $locked;
    }
}
