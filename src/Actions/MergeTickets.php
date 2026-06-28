<?php

declare(strict_types=1);

namespace Selli\Ticketing\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Events\TicketMerged;
use Selli\Ticketing\Exceptions\CrossTenantException;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\AuditLogger;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Tenancy\TenantGuard;

/**
 * Unifies duplicate tickets: moves messages and attachments from each source
 * into the target, records the merge in the audit trail of both, soft-deletes
 * the sources and emits {@see TicketMerged}.
 */
class MergeTickets
{
    public function __construct(
        protected AuditLogger $audit,
        protected TenantGuard $tenant,
    ) {}

    /**
     * @param  iterable<Ticket>  $sources
     */
    public function handle(Ticket $target, iterable $sources, ?Model $actor = null): Ticket
    {
        $sourceIds = [];

        DB::transaction(function () use ($target, $sources, $actor, &$sourceIds): void {
            $ticketModel = Ticketing::ticketModel();
            $messageModel = Ticketing::ticketMessageModel();
            $attachmentModel = Ticketing::ticketAttachmentModel();
            $ticketMorph = $target->getMorphClass();

            // Lock the target first to serialise concurrent merges.
            $target = $ticketModel::query()->withoutTenancy()->lockForUpdate()->findOrFail($target->getKey());

            foreach ($sources as $source) {
                if ($source->getKey() === $target->getKey()) {
                    continue;
                }

                // Lock the source row; skip if it was already merged away.
                $source = $ticketModel::query()->withoutTenancy()->lockForUpdate()->find($source->getKey());

                if ($source === null) {
                    continue;
                }

                // Never merge tickets across tenants.
                if (! $this->tenant->belongsToTicketTenant($source, $target)) {
                    throw CrossTenantException::forAssignment('ticket');
                }

                $messageModel::query()->withoutTenancy()
                    ->where('ticket_id', $source->getKey())
                    ->update(['ticket_id' => $target->getKey()]);

                $attachmentModel::query()->withoutTenancy()
                    ->where('attachable_type', $ticketMorph)
                    ->where('attachable_id', $source->getKey())
                    ->update(['attachable_id' => $target->getKey()]);

                $this->audit->record($target, 'ticket.merged_from', $actor, context: ['source_id' => $source->getKey()]);
                $this->audit->record($source, 'ticket.merged_into', $actor, context: ['target_id' => $target->getKey()]);

                $source->delete(); // soft delete

                $sourceIds[] = $source->getKey();
            }
        });

        TicketMerged::dispatch($target, $sourceIds, $actor);

        return $target;
    }
}
