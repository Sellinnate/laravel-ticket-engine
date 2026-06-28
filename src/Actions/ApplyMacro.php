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
        if (! $this->tenant->belongsToTicketTenant($macro, $ticket)) {
            throw CrossTenantException::forAssignment('macro');
        }

        $actions = $macro->actions;
        $reply = is_array($actions['reply'] ?? null) ? $actions['reply'] : [];

        DB::transaction(function () use ($ticket, $macro, $actor, $actions, $reply): void {
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

                if ($team instanceof Team) {
                    $this->manager->assign($ticket, team: $team, strategy: $actions['strategy'] ?? null, actor: $actor);
                }
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
