<?php

declare(strict_types=1);

namespace Selli\Ticketing\Query;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Selli\Ticketing\Contracts\WorkflowDriver;
use Selli\Ticketing\Database\SqlDialect;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketParticipant;
use Selli\Ticketing\Support\Ticketing;

/**
 * A filterable, sortable listing of tickets for the current tenant — the one
 * filtering surface shared by the REST API, the MCP tools and the exports, so
 * every consumer gets the same semantics and the same indexes.
 *
 * Every filter compiles to SQL (nothing is filtered in PHP), the sort column is
 * whitelisted, and the query starts from the configured ticket model, so the
 * tenant global scope always applies.
 *
 * ```php
 * Ticketing::tickets()->state('open')->assignedTo($agent)->sort('priority')->paginate(25);
 * Ticketing::tickets()->filter($request->query())->paginate();
 * ```
 */
class TicketQuery
{
    /** Columns a listing may be sorted by. */
    public const SORTABLE = ['created_at', 'updated_at', 'priority', 'due_at', 'resolved_at', 'reference'];

    /** Sortable columns that may hold NULL (unusable as a cursor). */
    protected const NULLABLE_SORTS = ['due_at', 'resolved_at'];

    /** @var Builder<Ticket> */
    protected Builder $query;

    protected string $sortColumn = 'created_at';

    /** @var 'asc'|'desc' */
    protected string $sortDirection = 'desc';

    final public function __construct()
    {
        $this->query = Ticketing::ticketModel()::query();
    }

    public static function make(): static
    {
        return new static;
    }

    /**
     * Apply loosely-typed filters (an HTTP query string, MCP tool arguments).
     * Unknown keys are ignored; malformed values are ignored rather than
     * widening the result. `assignee=me` needs the acting model.
     *
     * Keys: state, status, priority, type, category, channel, team, assignee
     * (me|none|<morph>:<id>), tag, created_from, created_to, q, sort
     * (column, or -column for descending).
     *
     * @param  array<string, mixed>  $filters
     */
    public function filter(array $filters, ?Model $actor = null): static
    {
        if (is_string($state = $filters['state'] ?? null) && in_array($state, ['open', 'closed', 'paused'], true)) {
            $this->state($state);
        }

        foreach (['status', 'type', 'category', 'channel', 'tag'] as $key) {
            $values = self::stringList($filters[$key] ?? null);

            if ($values !== []) {
                match ($key) {
                    'status' => $this->status($values),
                    'type' => $this->type($values),
                    'category' => $this->category($values),
                    'channel' => $this->channel($values),
                    'tag' => $this->taggedWith($values),
                };
            }
        }

        $priorities = array_values(array_filter(array_map(
            static fn (string $value): ?Priority => is_numeric($value) ? Priority::tryFrom((int) $value) : null,
            self::stringList($filters['priority'] ?? null),
        )));

        if ($priorities !== []) {
            $this->priority($priorities);
        }

        if (is_scalar($team = $filters['team'] ?? null) && (string) $team !== '') {
            $this->teamKey((string) $team);
        }

        $this->applyAssigneeFilter($filters['assignee'] ?? null, $actor);

        $from = self::date($filters['created_from'] ?? null);
        $to = self::date($filters['created_to'] ?? null);

        if ($from !== null || $to !== null) {
            $this->createdBetween($from, $to);
        }

        if (is_string($term = $filters['q'] ?? null) && trim($term) !== '') {
            $this->search($term);
        }

        if (is_string($sort = $filters['sort'] ?? null) && $sort !== '') {
            $column = ltrim($sort, '-');

            if (in_array($column, self::SORTABLE, true)) {
                $this->sort($column, str_starts_with($sort, '-') ? 'desc' : 'asc');
            }
        }

        return $this;
    }

    /**
     * Tickets whose status maps to a system semantic (open, closed, paused) in
     * the workflow of their own type — exact even when two workflows reuse a
     * state name with different meanings.
     */
    public function state(string $semantic): static
    {
        if (! in_array($semantic, ['open', 'closed', 'paused'], true)) {
            throw new InvalidArgumentException("Unknown ticket state semantic [{$semantic}].");
        }

        /** @var WorkflowDriver $driver */
        $driver = app(WorkflowDriver::class);
        $typeModel = Ticketing::ticketTypeModel();

        $this->query->where(function (Builder $query) use ($driver, $semantic, $typeModel): void {
            // No workflow at all: match nothing rather than everything.
            $query->whereRaw('1 = 0');

            foreach ($this->workflowKeys() as $workflow) {
                try {
                    $states = $driver->statesForSemantic($workflow, $semantic);
                } catch (InvalidConfigurationException) {
                    // A type pointing at a workflow the driver no longer knows has
                    // no state with this meaning.
                    continue;
                }

                if ($states === []) {
                    continue;
                }

                $query->orWhere(function (Builder $branch) use ($workflow, $states, $typeModel): void {
                    $branch->whereIn('status', $states)
                        ->whereIn('ticket_type_id', $typeModel::query()->withoutTenancy()
                            ->where('workflow', $workflow)
                            ->select((new $typeModel)->getKeyName()));
                });
            }
        });

        return $this;
    }

    /**
     * @param  list<string>|string  $statuses
     */
    public function status(array|string $statuses): static
    {
        $this->query->whereIn('status', (array) $statuses);

        return $this;
    }

    /**
     * @param  list<Priority>|Priority  $priorities
     */
    public function priority(array|Priority $priorities): static
    {
        $values = array_map(static fn (Priority $priority): int => $priority->value, is_array($priorities) ? $priorities : [$priorities]);

        $this->query->whereIn('priority', $values);

        return $this;
    }

    /**
     * Tickets of the given type key(s).
     *
     * @param  list<string>|string  $keys
     */
    public function type(array|string $keys): static
    {
        $typeModel = Ticketing::ticketTypeModel();

        $this->query->whereIn('ticket_type_id', $typeModel::query()
            ->whereIn('key', (array) $keys)
            ->select((new $typeModel)->getKeyName()));

        return $this;
    }

    /**
     * @param  list<string>|string  $categories
     */
    public function category(array|string $categories): static
    {
        $this->query->whereIn('category', (array) $categories);

        return $this;
    }

    /**
     * @param  list<string>|string  $channels
     */
    public function channel(array|string $channels): static
    {
        $this->query->whereIn('channel', (array) $channels);

        return $this;
    }

    public function assignedTo(Model $agent): static
    {
        $this->query->where('assignee_type', $agent->getMorphClass())
            ->where('assignee_id', (string) $agent->getKey());

        return $this;
    }

    public function unassigned(): static
    {
        $this->query->whereNull('assignee_id');

        return $this;
    }

    public function forTeam(Team $team): static
    {
        $this->query->where('team_id', $team->getKey());

        return $this;
    }

    /**
     * Tickets of a team given its raw key. A key that cannot be a valid id for
     * the configured key type matches nothing (never all teams).
     */
    public function teamKey(string $key): static
    {
        // Auto-increment keys must be a positive integer within the 64-bit range
        // (a longer digit string would make PostgreSQL's bigint cast fail).
        $valid = config('ticketing.ids.type') === 'ulid'
            ? $key !== ''
            : filter_var($key, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;

        $valid ? $this->query->where('team_id', $key) : $this->query->whereRaw('1 = 0');

        return $this;
    }

    /**
     * Tickets carrying any of the given tag slugs.
     *
     * @param  list<string>|string  $slugs
     */
    public function taggedWith(array|string $slugs): static
    {
        $ticket = $this->query->getModel();
        $tag = new (Ticketing::tagModel());
        $prefix = (string) config('ticketing.tables.prefix', '');
        $pivot = $prefix.(string) config('ticketing.tables.taggables', 'taggables');

        // An explicit EXISTS rather than whereHas('tags'): the pivot's
        // taggable_id is a string (agnostic to host key types), and PostgreSQL
        // refuses to compare it with a numeric ticket key without a cast.
        $this->query->whereExists(function ($exists) use ($ticket, $tag, $pivot, $slugs): void {
            $exists->selectRaw('1')
                ->from($pivot)
                ->join($tag->getTable(), $tag->qualifyColumn($tag->getKeyName()), '=', $pivot.'.tag_id')
                ->where($pivot.'.taggable_type', $ticket->getMorphClass())
                ->where($pivot.'.taggable_id', '=', SqlDialect::castToString($ticket->getQualifiedKeyName()))
                ->whereIn($tag->qualifyColumn('slug'), (array) $slugs);
        });

        return $this;
    }

    /**
     * Tickets the model requested (requester participant).
     */
    public function requestedBy(Model $requester): static
    {
        $this->query->whereHas('participants', function (Builder $participant) use ($requester): void {
            /** @var Builder<TicketParticipant> $participant */
            $participant->where('role', ParticipantRole::Requester->value)
                ->where('participant_type', $requester->getMorphClass())
                ->where('participant_id', (string) $requester->getKey());
        });

        return $this;
    }

    /**
     * Tickets about the given host model (the polymorphic subject).
     */
    public function about(Model $subject): static
    {
        $this->query->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', (string) $subject->getKey());

        return $this;
    }

    /**
     * Tickets the model is on in any way — the subject, or a participant in any
     * role. This is what a requester (non-agent) may list.
     */
    public function involving(Model $actor): static
    {
        $this->query->where(function (Builder $query) use ($actor): void {
            $query
                ->where(function (Builder $subject) use ($actor): void {
                    $subject->where('subject_type', $actor->getMorphClass())
                        ->where('subject_id', (string) $actor->getKey());
                })
                ->orWhereHas('participants', function (Builder $participant) use ($actor): void {
                    $participant->where('participant_type', $actor->getMorphClass())
                        ->where('participant_id', (string) $actor->getKey());
                });
        });

        return $this;
    }

    public function createdBetween(?CarbonInterface $from, ?CarbonInterface $to): static
    {
        if ($from !== null) {
            $this->query->where('created_at', '>=', $from);
        }

        if ($to !== null) {
            $this->query->where('created_at', '<=', $to);
        }

        return $this;
    }

    /**
     * Exact reference match, or a case-insensitive substring of the title.
     */
    public function search(string $term): static
    {
        $term = trim($term);
        // "!" as the LIKE escape character: a backslash literal is parsed
        // differently by MySQL than by PostgreSQL/SQLite.
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';

        $this->query->where(function (Builder $query) use ($term, $like): void {
            $query->where('reference', $term)
                ->orWhereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$like]);
        });

        return $this;
    }

    public function sort(string $column, string $direction = 'desc'): static
    {
        if (! in_array($column, self::SORTABLE, true)) {
            throw new InvalidArgumentException("Tickets cannot be sorted by [{$column}].");
        }

        $this->sortColumn = $column;
        $this->sortDirection = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        return $this;
    }

    /**
     * Constrain the underlying builder directly (an escape hatch for host
     * filters the query object does not model).
     *
     * @param  callable(Builder<Ticket>): mixed  $callback
     */
    public function where(callable $callback): static
    {
        $callback($this->query);

        return $this;
    }

    /**
     * The sorted Eloquent builder (the key breaks ties so pages are stable).
     *
     * @return Builder<Ticket>
     */
    public function builder(): Builder
    {
        $query = clone $this->query;
        $key = $query->getModel()->getKeyName();

        return $query->orderBy($this->sortColumn, $this->sortDirection)
            ->orderBy($key, $this->sortDirection);
    }

    /**
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function paginate(int $perPage = 25): LengthAwarePaginator
    {
        return $this->builder()->paginate(self::clampPerPage($perPage));
    }

    /**
     * Keyset pagination for deep lists and infinite scroll (no OFFSET scan).
     * Needs a sort column that is never NULL — a cursor cannot advance past
     * NULLs — so it refuses due_at/resolved_at.
     *
     * @return CursorPaginator<int, Ticket>
     */
    public function cursorPaginate(int $perPage = 25): CursorPaginator
    {
        if (in_array($this->sortColumn, self::NULLABLE_SORTS, true)) {
            throw new InvalidArgumentException("Cursor pagination cannot sort by the nullable [{$this->sortColumn}] column; use paginate() or another sort.");
        }

        return $this->builder()->cursorPaginate(self::clampPerPage($perPage));
    }

    /**
     * @return Collection<int, Ticket>
     */
    public function get(): Collection
    {
        return $this->builder()->get();
    }

    public function count(): int
    {
        return (clone $this->query)->count();
    }

    /**
     * Every workflow a ticket can be in: the configured ones plus whatever the
     * stored ticket types reference, so a custom (e.g. database-backed)
     * workflow driver is covered too.
     *
     * @return list<string>
     */
    protected function workflowKeys(): array
    {
        $typeModel = Ticketing::ticketTypeModel();

        $stored = $typeModel::query()->withoutTenancy()->distinct()->pluck('workflow')->all();
        $configured = array_keys((array) config('ticketing.workflow.workflows', []));

        $keys = [];

        foreach (array_merge($configured, $stored) as $key) {
            if (is_string($key) && $key !== '') {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    protected function applyAssigneeFilter(mixed $assignee, ?Model $actor): void
    {
        if (! is_string($assignee) || $assignee === '') {
            return;
        }

        if ($assignee === 'none') {
            $this->unassigned();

            return;
        }

        if ($assignee === 'me') {
            // Without an acting model "me" names nobody: fail closed.
            $actor instanceof Model ? $this->assignedTo($actor) : $this->query->whereRaw('1 = 0');

            return;
        }

        $separator = strrpos($assignee, ':');

        if ($separator === false) {
            return;
        }

        $this->query->where('assignee_type', substr($assignee, 0, $separator))
            ->where('assignee_id', substr($assignee, $separator + 1));
    }

    protected static function clampPerPage(int $perPage): int
    {
        return min(100, max(1, $perPage));
    }

    /**
     * A scalar or a list of scalars as a list of non-empty strings; a comma
     * separated string ("open,pending") is split.
     *
     * @return list<string>
     */
    protected static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return is_scalar($value) ? [(string) $value] : [];
        }

        $out = [];

        foreach ($value as $item) {
            if (is_scalar($item) && trim((string) $item) !== '') {
                $out[] = trim((string) $item);
            }
        }

        return $out;
    }

    protected static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $date = Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }

        // Out-of-range years would overflow some engines' timestamp types.
        return $date->year >= 1970 && $date->year <= 9999 ? $date : null;
    }
}
