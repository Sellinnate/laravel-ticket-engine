<?php

declare(strict_types=1);

namespace Selli\Ticketing\Gdpr;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketType;
use Selli\Ticketing\Support\Ticketing;

/**
 * Applies the configured retention rules: each rule anonymises or deletes closed
 * tickets of a type (or "*") whose closed_at is older than N days. Anonymise
 * keeps the ticket (and its immutable audit) for statistics while scrubbing the
 * stored personal data; delete is a sanctioned erasure that removes the ticket
 * and every row it owns — including the audit trail, which retention/erasure is
 * the one legitimate reason to drop (unlike runtime tampering).
 */
class ApplyRetention
{
    /**
     * @return list<array{type: string, action: string, count: int}>
     */
    public function handle(): array
    {
        $results = [];

        /** @var array<int, array{type?: string, after_days?: int, action?: string}> $rules */
        $rules = (array) config('ticketing.gdpr.retention', []);

        foreach ($rules as $rule) {
            $afterDays = (int) ($rule['after_days'] ?? 0);

            if ($afterDays <= 0) {
                continue;
            }

            $type = isset($rule['type']) ? (string) $rule['type'] : '*';
            $action = ($rule['action'] ?? 'anonymize') === 'delete' ? 'delete' : 'anonymize';
            $cutoff = Carbon::now()->subDays($afterDays);

            $count = 0;

            DB::transaction(function () use ($type, $cutoff, $action, &$count): void {
                // Re-select (and lock) inside the transaction so a ticket reopened
                // between selection and action isn't erased on a stale snapshot.
                $tickets = $this->dueTickets($type, $cutoff)->lockForUpdate()->get();

                foreach ($tickets as $ticket) {
                    $action === 'delete' ? $this->delete($ticket) : $this->anonymize($ticket);
                }

                $count = $tickets->count();
            });

            $results[] = ['type' => $type, 'action' => $action, 'count' => $count];
        }

        return $results;
    }

    /**
     * @return Builder<Ticket>
     */
    protected function dueTickets(string $type, Carbon $cutoff): Builder
    {
        /** @var Builder<Ticket> $query */
        $query = Ticketing::ticketModel()::withoutTenancy();

        // Reach soft-deleted tickets too — a trashed-but-not-erased ticket still
        // holds PII that retention must act on.
        if (RequesterTickets::softDeletes(Ticketing::ticketModel())) {
            $query->withTrashed();
        }

        $query->whereNotNull('closed_at')->where('closed_at', '<=', $cutoff);

        if ($type !== '*') {
            // withoutTenancy on the type subquery too: prune runs in the console
            // with no tenant context, so a tenant-scoped TicketType would
            // otherwise only match shared types and skip most tickets.
            $query->whereHas('type', function (Builder $relation) use ($type): void {
                /** @var Builder<TicketType> $relation */
                $relation->withoutTenancy()->where('key', $type);
            });
        }

        return $query;
    }

    /** Scrub the denormalised PII (the email channel's from/from-name) on a ticket. */
    protected function anonymize(Ticket $ticket): void
    {
        $label = (string) config('ticketing.gdpr.anonymized_label', '[anonymized]');

        $relation = $ticket->messages()->withoutTenancy();

        if (RequesterTickets::softDeletes(Ticketing::ticketMessageModel())) {
            $relation->withTrashed();
        }

        foreach ($relation->get() as $message) {
            $meta = $message->meta ?? [];
            $changed = false;

            foreach (['from', 'from_name'] as $key) {
                if (array_key_exists($key, $meta) && $meta[$key] !== $label) {
                    $meta[$key] = $label;
                    $changed = true;
                }
            }

            if ($changed) {
                $message->meta = $meta;
                $message->save();
            }
        }
    }

    /**
     * Hard-delete a ticket and every row it owns. Child tables are resolved from
     * the actual model bindings (honouring useTicketMessageModel() etc.), so a
     * host override doesn't leave orphans. Raw deletes are used so the audit
     * model's runtime immutability guard is bypassed here, where erasure is the
     * intent. Polymorphic rows (attachments on the ticket AND its messages, tag
     * pivots) are matched on their morph keys, not a ticket_id.
     */
    protected function delete(Ticket $ticket): void
    {
        $messageModel = new (Ticketing::ticketMessageModel());
        $messageMorph = $messageModel->getMorphClass();
        $messageIds = DB::table($messageModel->getTable())
            ->where('ticket_id', $ticket->getKey())
            ->pluck($messageModel->getKeyName());

        // Polymorphic attachments: on the ticket itself, and on each of its messages.
        DB::table($this->tableFor(Ticketing::ticketAttachmentModel()))
            ->where(function (\Illuminate\Database\Query\Builder $query) use ($ticket, $messageMorph, $messageIds): void {
                $query->where('attachable_type', $ticket->getMorphClass())->where('attachable_id', $ticket->getKey());

                if ($messageIds->isNotEmpty()) {
                    $query->orWhere(function (\Illuminate\Database\Query\Builder $messages) use ($messageMorph, $messageIds): void {
                        $messages->where('attachable_type', $messageMorph)->whereIn('attachable_id', $messageIds);
                    });
                }
            })
            ->delete();

        // Polymorphic tag pivots on the ticket.
        DB::table($this->configTable('taggables'))
            ->where('taggable_type', $ticket->getMorphClass())
            ->where('taggable_id', $ticket->getKey())
            ->delete();

        // Every ticket_id-owned child, by its bound model's table.
        foreach ([
            Ticketing::ticketMessageModel(),
            Ticketing::ticketParticipantModel(),
            Ticketing::ticketActivityModel(),
            Ticketing::slaClockModel(),
            Ticketing::ticketLinkModel(),
            Ticketing::satisfactionRatingModel(),
        ] as $model) {
            DB::table($this->tableFor($model))->where('ticket_id', $ticket->getKey())->delete();
        }

        DB::table($ticket->getTable())->where($ticket->getKeyName(), $ticket->getKey())->delete();
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function tableFor(string $model): string
    {
        return (new $model)->getTable();
    }

    protected function configTable(string $key): string
    {
        return ((string) config('ticketing.tables.prefix', '')).(string) config('ticketing.tables.'.$key, $key);
    }
}
