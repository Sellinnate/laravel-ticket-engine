<?php

declare(strict_types=1);

namespace Selli\Ticketing\Gdpr;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\Ticketing;

/**
 * Applies the configured retention rules: each rule anonymises or deletes closed
 * tickets of a type (or "*") whose closed_at is older than N days. Anonymise
 * keeps the ticket (and its immutable audit) for statistics while scrubbing the
 * stored personal data; delete is a sanctioned erasure that removes the ticket
 * and its rows outright — including the audit trail, which retention/erasure is
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

            $tickets = $this->dueTickets($type, $cutoff)->get();

            DB::transaction(function () use ($tickets, $action): void {
                foreach ($tickets as $ticket) {
                    $action === 'delete' ? $this->delete($ticket) : $this->anonymize($ticket);
                }
            });

            $results[] = ['type' => $type, 'action' => $action, 'count' => $tickets->count()];
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

        $query->whereNotNull('closed_at')->where('closed_at', '<=', $cutoff);

        if ($type !== '*') {
            $query->whereHas('type', fn (Builder $relation) => $relation->where('key', $type));
        }

        return $query;
    }

    /** Scrub the denormalised PII (the email channel's from/from-name) on a ticket. */
    protected function anonymize(Ticket $ticket): void
    {
        $label = (string) config('ticketing.gdpr.anonymized_label', '[anonymized]');

        foreach ($ticket->messages()->withoutTenancy()->get() as $message) {
            $meta = $message->meta ?? [];
            $changed = false;

            foreach (['from', 'from_name'] as $key) {
                if (array_key_exists($key, $meta)) {
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
     * Hard-delete a ticket and its rows. Children are removed by raw deletes so
     * the audit model's immutability guard (which blocks deletes at runtime) is
     * bypassed here, where erasure is the intent.
     */
    protected function delete(Ticket $ticket): void
    {
        foreach (['ticket_messages', 'ticket_participants', 'ticket_activities', 'ticket_attachments', 'satisfaction_ratings'] as $table) {
            DB::table($this->table($table))->where('ticket_id', $ticket->getKey())->delete();
        }

        DB::table($ticket->getTable())->where($ticket->getKeyName(), $ticket->getKey())->delete();
    }

    protected function table(string $key): string
    {
        return ((string) config('ticketing.tables.prefix', '')).(string) config('ticketing.tables.'.$key, $key);
    }
}
