<?php

declare(strict_types=1);

namespace Selli\Ticketing\Gdpr;

use Illuminate\Database\Eloquent\SoftDeletingScope;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\AuditLogger;
use Selli\Ticketing\Support\Ticketing;

/**
 * The durable markers, in the audit trail, of a ticket holding an erased
 * person's words: copies made later (a search or vector index) must leave it
 * out. Recorded by the core whatever integration is on — anonymisation
 * (`requester.anonymized`), retention (`ticket.anonymized`), the RAG bridge
 * (`rag.excluded`) — and carried, as `ticket.erasure_inherited`, to the
 * tickets that receive a marked ticket's messages (merge, split), so the
 * marker outlives the source's deletion.
 *
 * @internal
 */
class ErasureMarkers
{
    public const INHERITED = 'ticket.erasure_inherited';

    public const EVENTS = ['requester.anonymized', 'ticket.anonymized', 'rag.excluded', self::INHERITED];

    public function __construct(
        protected AuditLogger $audit,
    ) {}

    /**
     * Mark a ticket that received the messages of a marked one — or of one
     * descending from a marked one.
     *
     * @param  list<int|string>  $sourceIds
     */
    public function inherit(Ticket $target, array $sourceIds): void
    {
        if ($sourceIds === [] || $this->marked([$target->getKey()]) !== []) {
            return;
        }

        $sources = $this->among($sourceIds);

        if ($sources !== []) {
            $this->audit->record(ticket: $target, event: self::INHERITED, context: ['source_ids' => $sources]);
        }
    }

    /**
     * Before a marked ticket and its audit trail are deleted: hand the marker
     * on to the tickets that took its messages (merged into, split off), whose
     * own trail only points back at it.
     */
    public function handOn(Ticket $ticket): void
    {
        if ($this->among([$ticket->getKey()]) === []) {
            return;
        }

        $heirs = [];

        foreach (Ticketing::ticketActivityModel()::query()->withoutTenancy()
            ->where('ticket_id', $ticket->getKey())
            ->whereIn('event', ['ticket.merged_into', 'ticket.split'])
            ->get(['context']) as $row) {
            $context = is_array($row->context) ? $row->context : [];
            $heir = $context['target_id'] ?? $context['created_id'] ?? null;

            if (is_int($heir) || is_string($heir)) {
                $heirs[(string) $heir] = $heir;
            }
        }

        $marked = array_fill_keys($this->marked(array_values($heirs)), true);

        foreach ($heirs as $key => $id) {
            $heir = isset($marked[$key]) ? null : Ticketing::ticketModel()::query()->withoutTenancy()
                ->withoutGlobalScope(SoftDeletingScope::class)
                ->find($id);

            if ($heir instanceof Ticket) {
                $this->audit->record(ticket: $heir, event: self::INHERITED, context: ['source_ids' => [(string) $ticket->getKey()]]);
            }
        }
    }

    /**
     * The tickets, among these, that carry a marker or descend — by merge or
     * split, however many times — from one that does.
     *
     * @param  list<int|string>  $ticketIds
     * @return list<string>
     */
    public function among(array $ticketIds): array
    {
        $activities = Ticketing::ticketActivityModel();
        $ids = array_values(array_unique(array_map(fn (int|string $id): string => (string) $id, $ticketIds)));
        $known = array_fill_keys($ids, true);
        $parents = [];
        $frontier = $ids;

        // Until the lineage is exhausted (the known set stops cycles).
        while ($frontier !== []) {
            $next = [];

            foreach ($activities::query()->withoutTenancy()->whereIn('ticket_id', $frontier)->whereIn('event', ['ticket.merged_from', 'ticket.split_from'])->get(['ticket_id', 'context']) as $row) {
                $source = is_array($row->context) ? ($row->context['source_id'] ?? null) : null;

                if (is_int($source) || is_string($source)) {
                    $parents[(string) $row->ticket_id][] = (string) $source;

                    if (! isset($known[(string) $source])) {
                        $known[(string) $source] = true;
                        $next[] = (string) $source;
                    }
                }
            }

            $frontier = $next;
        }

        $marked = array_fill_keys($this->marked(array_keys($known)), true);

        $isMarked = function (string $id, array $seen) use (&$isMarked, $marked, $parents): bool {
            if (isset($marked[$id])) {
                return true;
            }

            foreach ($parents[$id] ?? [] as $parent) {
                if (! isset($seen[$parent]) && $isMarked($parent, $seen + [$parent => true])) {
                    return true;
                }
            }

            return false;
        };

        return array_values(array_filter($ids, fn (string $id): bool => $isMarked($id, [$id => true])));
    }

    /**
     * The tickets, among these, that carry a marker themselves.
     *
     * @param  list<int|string>  $ticketIds
     * @return list<string>
     */
    protected function marked(array $ticketIds): array
    {
        if ($ticketIds === []) {
            return [];
        }

        return array_values(array_unique(Ticketing::ticketActivityModel()::query()->withoutTenancy()
            ->whereIn('ticket_id', $ticketIds)
            ->whereIn('event', self::EVENTS)
            ->pluck('ticket_id')
            ->map(fn (mixed $key): string => (string) $key)
            ->all()));
    }
}
