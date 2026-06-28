<?php

declare(strict_types=1);

namespace Selli\Ticketing\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Events\TicketMerged;
use Selli\Ticketing\Exceptions\CrossTenantException;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\AuditLogger;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Tenancy\TenantGuard;

/**
 * Unifies duplicate tickets: moves messages, attachments and requester
 * participants from each source into the target, records the merge in the audit
 * trail of both, soft-deletes the sources and emits {@see TicketMerged}.
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
        $targetKey = $target->getKey();

        /** @var list<int|string> $sourceKeys */
        $sourceKeys = [];
        foreach ($sources as $source) {
            if ($source->getKey() !== $targetKey) {
                $sourceKeys[] = $source->getKey();
            }
        }
        $sourceKeys = array_values(array_unique($sourceKeys));

        $result = DB::transaction(function () use ($targetKey, $sourceKeys, $actor): array {
            $ticketModel = Ticketing::ticketModel();

            // Lock all involved rows in a canonical (sorted) order so two
            // concurrent merges of the overlapping tickets cannot deadlock.
            $locked = $this->lockInOrder(array_merge([$targetKey], $sourceKeys));
            $target = $locked[$this->keyString($targetKey)] ?? $ticketModel::query()->withoutTenancy()->findOrFail($targetKey);

            $mergedIds = [];

            foreach ($sourceKeys as $sourceKey) {
                $source = $locked[$this->keyString($sourceKey)] ?? null;

                if ($source === null) {
                    continue; // already merged away
                }

                if (! $this->tenant->belongsToTicketTenant($source, $target)) {
                    throw CrossTenantException::forAssignment('ticket');
                }

                $this->moveContent($source, $target);
                $this->copyRequesters($source, $target);

                $this->audit->record($target, 'ticket.merged_from', $actor, context: ['source_id' => $source->getKey()]);
                $this->audit->record($source, 'ticket.merged_into', $actor, context: ['target_id' => $target->getKey()]);

                $source->delete();
                $mergedIds[] = $source->getKey();
            }

            return [$target, $mergedIds];
        });

        /** @var array{0: Ticket, 1: list<int|string>} $result */
        [$target, $mergedIds] = $result;

        TicketMerged::dispatch($target, $mergedIds, $actor);

        return $target;
    }

    /**
     * @param  list<int|string>  $keys
     * @return array<string, Ticket>
     */
    protected function lockInOrder(array $keys): array
    {
        $keys = array_values(array_unique($keys));
        sort($keys);

        $model = Ticketing::ticketModel();
        $locked = [];

        foreach ($keys as $key) {
            $ticket = $model::query()->withoutTenancy()->lockForUpdate()->find($key);

            if ($ticket instanceof Ticket) {
                $locked[$this->keyString($key)] = $ticket;
            }
        }

        return $locked;
    }

    protected function moveContent(Ticket $source, Ticket $target): void
    {
        // Re-parent the rows AND realign their tenant to the target's, so a
        // merge from a shared/null-tenant source doesn't leave child rows out of
        // the target's scope.
        $tenantColumn = $target->getTenantColumn();
        $tenantValue = $target->getAttribute($tenantColumn);

        $messageModel = Ticketing::ticketMessageModel();
        $messageMorph = (new $messageModel)->getMorphClass();
        $messageKey = (new $messageModel)->getKeyName();

        /** @var list<int|string> $messageIds */
        $messageIds = $messageModel::query()->withoutTenancy()
            ->where('ticket_id', $source->getKey())
            ->pluck($messageKey)
            ->all();

        $messageModel::query()->withoutTenancy()
            ->where('ticket_id', $source->getKey())
            ->update(['ticket_id' => $target->getKey(), $tenantColumn => $tenantValue]);

        $attachmentModel = Ticketing::ticketAttachmentModel();

        // Attachments on the source ticket → re-parent to the target.
        $attachmentModel::query()->withoutTenancy()
            ->where('attachable_type', $target->getMorphClass())
            ->where('attachable_id', $source->getKey())
            ->update(['attachable_id' => $target->getKey(), $tenantColumn => $tenantValue]);

        // Attachments on the moved messages → keep their message, realign tenant.
        if ($messageIds !== []) {
            $attachmentModel::query()->withoutTenancy()
                ->where('attachable_type', $messageMorph)
                ->whereIn('attachable_id', $messageIds)
                ->update([$tenantColumn => $tenantValue]);
        }
    }

    protected function copyRequesters(Ticket $source, Ticket $target): void
    {
        $model = Ticketing::ticketParticipantModel();

        $requesters = $model::query()->withoutTenancy()
            ->where('ticket_id', $source->getKey())
            ->where('role', ParticipantRole::Requester->value)
            ->get();

        foreach ($requesters as $requester) {
            $model::query()->withoutTenancy()->firstOrCreate(
                [
                    'ticket_id' => $target->getKey(),
                    'participant_type' => $requester->participant_type,
                    'participant_id' => $requester->participant_id,
                    'role' => ParticipantRole::Requester->value,
                ],
                array_merge($target->tenantAttributes(), ['notify' => true]),
            );
        }
    }

    protected function keyString(int|string $key): string
    {
        return (string) $key;
    }
}
