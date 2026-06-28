<?php

declare(strict_types=1);

namespace Selli\Ticketing\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Exceptions\CrossTenantException;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Models\Macro;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\AuditLogger;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Tenancy\TenantGuard;

/**
 * Applies a macro's set of operations to a ticket in one transaction: an
 * optional reply, team assignment, workflow transition and tags.
 */
class ApplyMacro
{
    public function __construct(
        protected Ticketing $manager,
        protected AuditLogger $audit,
        protected TenantGuard $tenant,
    ) {}

    public function handle(Ticket $ticket, Macro $macro, ?Model $actor = null): Ticket
    {
        DB::transaction(function () use ($ticket, $macro, $actor): void {
            // Re-read the ticket under a row lock so the macro's side effects
            // (reply/tags in particular) can't land on a ticket that was
            // soft-deleted — e.g. merged away — concurrently. findOrFail uses the
            // default scope, so a trashed ticket fails the whole macro closed.
            /** @var Ticket $ticket */
            $ticket = Ticketing::ticketModel()::query()->withoutTenancy()
                ->lockForUpdate()
                ->findOrFail($ticket->getKey());

            // Re-read the macro inside the transaction too, so a concurrent
            // deactivation / re-type / re-tenant is honoured rather than running
            // off the caller's stale instance. findOrFail fails closed if it was
            // deleted.
            /** @var Macro $macro */
            $macro = Ticketing::macroModel()::query()->withoutTenancy()
                ->findOrFail($macro->getKey());

            if (! $macro->is_active) {
                throw new InvalidConfigurationException("Macro [{$macro->key}] is inactive.");
            }

            // Validate ticket-dependent preconditions against the LOCKED row, so a
            // concurrent tenant/type change can't slip past a pre-lock check.
            if (! $this->tenant->belongsToTicketTenant($macro, $ticket)) {
                throw CrossTenantException::forAssignment('macro');
            }

            if ($macro->ticket_type_id !== null
                && (string) $macro->ticket_type_id !== (string) $ticket->ticket_type_id) {
                // A type-scoped macro must not apply to a ticket of another type.
                throw new InvalidConfigurationException(
                    "Macro [{$macro->key}] does not apply to this ticket type."
                );
            }

            $actions = $macro->actions;
            $reply = is_array($actions['reply'] ?? null) ? $actions['reply'] : [];

            if (! empty($reply['body'])) {
                $visibility = MessageVisibility::tryFrom((string) ($reply['visibility'] ?? 'public'));

                if ($visibility === null) {
                    // Fail closed on an unknown visibility rather than defaulting
                    // to public (which could leak an intended-internal reply).
                    throw new InvalidConfigurationException(
                        'Macro reply has an invalid visibility ['.(string) ($reply['visibility'] ?? '').'].'
                    );
                }

                $this->manager->postMessage($ticket, $actor, (string) $reply['body'], $visibility);
            }

            if (! empty($actions['assign_team_id'])) {
                $team = Ticketing::teamModel()::query()->withoutTenancy()->find($actions['assign_team_id']);

                if (! $team instanceof Team) {
                    // Fail closed: a macro that references a missing team must not
                    // silently proceed as if assignment succeeded.
                    throw new InvalidConfigurationException(
                        "Macro references unknown team [{$actions['assign_team_id']}]."
                    );
                }

                if (! $team->is_active) {
                    // Routing skips deactivated teams; a macro must not be able to
                    // assign a ticket to a team that open routing would never use.
                    throw new InvalidConfigurationException(
                        "Macro references inactive team [{$actions['assign_team_id']}]."
                    );
                }

                $this->manager->assign($ticket, team: $team, strategy: $actions['strategy'] ?? null, actor: $actor);
            }

            if (! empty($actions['transition'])) {
                $this->manager->transition($ticket, (string) $actions['transition'], $actor, $actions['note'] ?? null);
            }

            if (! empty($actions['tags'])) {
                $this->manager->tag($ticket, array_values((array) $actions['tags']));
            }

            $this->audit->record($ticket, 'macro.applied', $actor, context: ['macro' => $macro->key]);
        });

        return $ticket->fresh() ?? $ticket;
    }
}
