<?php

declare(strict_types=1);

namespace Selli\Ticketing\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Events\TicketOpened;
use Selli\Ticketing\Events\TicketSplit;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketType;
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
    ) {}

    /**
     * @param  list<int|string>  $messageIds
     */
    public function handle(Ticket $source, array $messageIds, ?string $title = null, ?Model $actor = null): Ticket
    {
        // Run in the source ticket's tenant so reference allocation and all
        // writes use the correct tenant regardless of ambient context.
        $sourceTenant = $source->getAttribute($source->getTenantColumn());

        $created = $this->tenant->forTenant($sourceTenant, fn (): Ticket => $this->split($source, $messageIds, $title, $actor));

        TicketOpened::dispatch($created);
        TicketSplit::dispatch($source, $created, $actor);

        return $created;
    }

    /**
     * @param  list<int|string>  $messageIds
     */
    protected function split(Ticket $source, array $messageIds, ?string $title, ?Model $actor): Ticket
    {
        return DB::transaction(function () use ($source, $messageIds, $title, $actor): Ticket {
            $ticketModel = Ticketing::ticketModel();
            $messageModel = Ticketing::ticketMessageModel();
            $linkModel = Ticketing::ticketLinkModel();

            // Lock the source so concurrent split/merge/message moves serialise.
            $source = $ticketModel::query()->withoutTenancy()->lockForUpdate()->findOrFail($source->getKey());

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

            $linkModel::query()->create(array_merge($source->tenantAttributes(), [
                'ticket_id' => $created->getKey(),
                'linkable_type' => $source->getMorphClass(),
                'linkable_id' => $source->getKey(),
                'role' => 'references',
            ]));

            $this->audit->record($source, 'ticket.split', $actor, context: ['created_id' => $created->getKey(), 'messages' => $messageIds]);
            $this->audit->record($created, 'ticket.split_from', $actor, context: ['source_id' => $source->getKey()]);

            return $created;
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
