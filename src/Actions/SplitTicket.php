<?php

declare(strict_types=1);

namespace Selli\Ticketing\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Events\TicketOpened;
use Selli\Ticketing\Events\TicketSplit;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketType;
use Selli\Ticketing\Sla\SlaManager;
use Selli\Ticketing\Support\AuditLogger;
use Selli\Ticketing\Support\ReferenceGenerator;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Tenancy\TenantContext;

/**
 * Extracts one or more messages from a ticket into a new (fresh) ticket, keeping
 * the two linked. The new ticket starts at its workflow's initial state and
 * fires {@see TicketOpened} so SLA/routing run for it, plus {@see TicketSplit}.
 */
class SplitTicket
{
    public function __construct(
        protected AuditLogger $audit,
        protected ReferenceGenerator $references,
        protected TenantContext $tenant,
        protected SlaManager $sla,
    ) {}

    /**
     * @param  list<int|string>  $messageIds
     */
    public function handle(Ticket $source, array $messageIds, ?string $title = null, ?Model $actor = null): Ticket
    {
        // Run in the source ticket's tenant so reference allocation and all
        // writes use the correct tenant regardless of ambient context.
        $sourceTenant = $source->getAttribute($source->getTenantColumn());

        // Run the writes AND the event dispatch under the source tenant, so the
        // TicketOpened listeners (SLA bootstrap, routing) read the correct
        // tenant's configuration regardless of the ambient context (queue/CLI).
        // split() returns the freshly reloaded source (locked inside the
        // transaction) so listeners never see the caller's stale in-memory
        // instance whose relations predate the moved messages.
        return $this->tenant->forTenant($sourceTenant, function () use ($source, $messageIds, $title, $actor): Ticket {
            [$created, $reloadedSource] = $this->split($source, $messageIds, $title, $actor);

            TicketOpened::dispatch($created);
            TicketSplit::dispatch($reloadedSource, $created, $actor);

            return $created;
        });
    }

    /**
     * @param  list<int|string>  $messageIds
     * @return array{0: Ticket, 1: Ticket}
     */
    protected function split(Ticket $source, array $messageIds, ?string $title, ?Model $actor): array
    {
        return DB::transaction(function () use ($source, $messageIds, $title, $actor): array {
            $ticketModel = Ticketing::ticketModel();
            $messageModel = Ticketing::ticketMessageModel();
            $linkModel = Ticketing::ticketLinkModel();
            $participantModel = Ticketing::ticketParticipantModel();

            // Lock the source so concurrent split/merge/message moves serialise.
            $source = $ticketModel::query()->withoutTenancy()->lockForUpdate()->findOrFail($source->getKey());

            // Require a non-empty set where every requested message belongs to
            // the source, so we never create an empty ticket nor silently drop
            // ids that point at another ticket.
            $unique = array_values(array_unique($messageIds));
            $movable = $messageModel::query()->withoutTenancy()
                ->where('ticket_id', $source->getKey())
                ->whereIn((new $messageModel)->getKeyName(), $unique)
                ->count();

            if ($unique === [] || $movable !== count($unique)) {
                throw new \InvalidArgumentException('Every message to split must belong to the source ticket.');
            }

            $typeKey = $this->typeKey($source);

            /** @var Ticket $created */
            $created = $ticketModel::query()->create(array_merge($source->tenantAttributes(), [
                'reference' => $this->references->generate($typeKey),
                'ticket_type_id' => $source->ticket_type_id,
                'subject_type' => $source->subject_type,
                'subject_id' => $source->subject_id,
                'category' => $source->category,
                'priority' => $source->priority,
                // A split ticket is a fresh request: start at the initial state
                // with clean lifecycle timestamps.
                'status' => $this->initialState($source),
                'title' => $title ?? ($source->title.' (split)'),
            ]));

            $messageModel::query()->withoutTenancy()
                ->where('ticket_id', $source->getKey())
                ->whereIn((new $messageModel)->getKeyName(), $messageIds)
                ->update(['ticket_id' => $created->getKey()]);

            // Carry the requester(s) over so SLA next-response handling and
            // notifications work on the split ticket too.
            $requesters = $participantModel::query()->withoutTenancy()
                ->where('ticket_id', $source->getKey())
                ->where('role', ParticipantRole::Requester->value)
                ->get();

            foreach ($requesters as $requester) {
                $participantModel::query()->withoutTenancy()->firstOrCreate(
                    [
                        'ticket_id' => $created->getKey(),
                        'participant_type' => $requester->participant_type,
                        'participant_id' => $requester->participant_id,
                        'role' => ParticipantRole::Requester->value,
                    ],
                    array_merge($created->tenantAttributes(), ['notify' => true]),
                );
            }

            $linkModel::query()->create(array_merge($source->tenantAttributes(), [
                'ticket_id' => $created->getKey(),
                'linkable_type' => $source->getMorphClass(),
                'linkable_id' => $source->getKey(),
                'role' => 'references',
            ]));

            $this->audit->record($source, 'ticket.split', $actor, context: ['created_id' => $created->getKey(), 'messages' => $messageIds]);
            $this->audit->record($created, 'ticket.split_from', $actor, context: ['source_id' => $source->getKey()]);

            // Stamp first_response_at from the moved thread INSIDE the transaction
            // (before the post-commit TicketOpened) so the split is atomic: if it
            // throws, the whole move rolls back rather than leaving a moved
            // conversation whose SLA bootstrap never ran.
            $this->sla->reconcileFirstResponse($created);

            return [$created, $source];
        });
    }

    protected function typeKey(Ticket $source): string
    {
        $type = $this->type($source);

        return $type !== null && is_string($type->key) && $type->key !== '' ? $type->key : 'support';
    }

    protected function initialState(Ticket $source): string
    {
        $type = $this->type($source);
        $workflow = $type !== null && is_string($type->workflow) && $type->workflow !== '' ? $type->workflow : 'default';
        $initial = config("ticketing.workflow.workflows.{$workflow}.initial");

        if (! is_string($initial) || $initial === '') {
            throw new InvalidConfigurationException("Workflow [{$workflow}] has no initial state.");
        }

        return $initial;
    }

    protected function type(Ticket $source): ?TicketType
    {
        $model = Ticketing::ticketTypeModel();

        return $model::query()->withoutTenancy()->whereKey($source->ticket_type_id)->first();
    }
}
