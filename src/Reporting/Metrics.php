<?php

declare(strict_types=1);

namespace Selli\Ticketing\Reporting;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Selli\Ticketing\Database\SqlDialect;
use Selli\Ticketing\Enums\AiStatus;
use Selli\Ticketing\Enums\EscalationReason;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Models\AiTurn;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Query\TicketQuery;
use Selli\Ticketing\Support\Ticketing;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Support KPIs for a period, aggregated in the database (no ticket is loaded
 * in memory), for the current tenant and an optional ticket filter:
 *
 *     Ticketing::metrics()->between($from, $to)->filter(['channel' => 'email'])->summary();
 *
 * Flow metrics count what happened in the period: tickets created, resolved,
 * first responses to the tickets created, resolutions of the tickets resolved.
 * The backlog is a snapshot of now. Durations are wall-clock seconds (SLA
 * clocks, with their business hours, are reported separately).
 */
class Metrics
{
    /**
     * The breakdown dimensions and the ticket columns they group on.
     *
     * @var array<string, list<string>>
     */
    public const DIMENSIONS = [
        'agent' => ['assignee_type', 'assignee_id'],
        'team' => ['team_id'],
        'channel' => ['channel'],
        'category' => ['category'],
        'priority' => ['priority'],
        'type' => ['ticket_type_id'],
    ];

    /**
     * The ticket filters metrics accept (see TicketQuery::filter()).
     *
     * @var list<string>
     */
    public const FILTERS = ['state', 'status', 'priority', 'type', 'category', 'channel', 'team', 'assignee', 'tag', 'q'];

    protected TicketQuery $query;

    protected CarbonInterface $from;

    protected CarbonInterface $to;

    /** Whether a ticket filter narrows the tickets (AI and CSAT then follow it). */
    protected bool $filtered = false;

    public function __construct()
    {
        $this->query = TicketQuery::make();
        $this->to = Carbon::now();
        $this->from = Carbon::now()->subDays(30)->startOfDay();
    }

    public function between(CarbonInterface $from, CarbonInterface $to): static
    {
        if ($from->greaterThan($to)) {
            throw new InvalidArgumentException('The period must start before it ends.');
        }

        $this->from = $from;
        $this->to = $to;

        return $this;
    }

    /**
     * Narrow the tickets with the {@see TicketQuery::filter()} keys (channel,
     * type, team, assignee, category, priority, tag, status…).
     *
     * @param  array<string, mixed>  $filters
     */
    public function filter(array $filters, ?Model $actor = null): static
    {
        // Only the keys that narrow tickets: the period is the metrics' own,
        // and an unknown key (a page number) must not change any figure.
        $filters = array_filter(
            array_intersect_key($filters, array_flip(self::FILTERS)),
            fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        );

        if ($filters !== []) {
            $this->query->filter($filters, $actor);
            $this->filtered = true;
        }

        return $this;
    }

    /**
     * Narrow the tickets with any TicketQuery call.
     *
     * @param  callable(TicketQuery): mixed  $callback
     */
    public function where(callable $callback): static
    {
        $callback($this->query);
        $this->filtered = true;

        return $this;
    }

    /**
     * Every metric at once.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'period' => ['from' => $this->from->toIso8601String(), 'to' => $this->to->toIso8601String()],
            'volume' => $this->volume(),
            'backlog' => $this->backlog(),
            'first_response' => $this->firstResponse(),
            'resolution' => $this->resolution(),
            'reopen_rate' => $this->reopenRate(),
            'sla' => $this->sla(),
            'ai' => $this->ai(),
            'csat' => $this->csat(),
            'knowledge_gaps' => $this->knowledgeGaps(),
        ];
    }

    /**
     * Tickets created, resolved and closed in the period, and created per day.
     *
     * @return array{created: int, resolved: int, closed: int, per_day: array<string, int>}
     */
    public function volume(): array
    {
        $day = SqlDialect::day('created_at');

        $perDay = $this->createdInPeriod()
            ->toBase()
            ->select([SqlDialect::alias($day, 'day')])
            ->selectRaw('count(*) as total')
            ->groupBy($day)
            ->orderBy($day)
            ->get();

        $series = [];
        foreach ($perDay as $row) {
            $series[(string) $row->day] = (int) $row->total;
        }

        return [
            'created' => array_sum($series),
            'resolved' => $this->tickets()->whereBetween('resolved_at', $this->period())->count(),
            'closed' => $this->tickets()->whereBetween('closed_at', $this->period())->count(),
            'per_day' => $series,
        ];
    }

    /**
     * Tickets not closed right now, unassigned ones, and their age.
     *
     * @return array{open: int, unassigned: int, age: array<string, int>}
     */
    public function backlog(): array
    {
        $now = Carbon::now();
        $buckets = ['< 1 day' => 1, '1-3 days' => 3, '3-7 days' => 7, '7-30 days' => 30];
        $age = [];
        $newer = $now->copy();

        foreach ($buckets as $label => $days) {
            $older = $now->copy()->subDays($days);
            $age[$label] = $this->open()->where('created_at', '>', $older)->where('created_at', '<=', $newer)->count();
            $newer = $older;
        }

        $age['> 30 days'] = $this->open()->where('created_at', '<=', $newer)->count();

        return [
            'open' => array_sum($age),
            'unassigned' => $this->open()->whereNull('assignee_id')->count(),
            'age' => $age,
        ];
    }

    /**
     * Time to the first agent reply of the tickets created in the period.
     *
     * @return array{count: int, mean_seconds: float|null, median_seconds: float|null}
     */
    public function firstResponse(): array
    {
        return $this->durations($this->createdInPeriod(), 'created_at', 'first_response_at');
    }

    /**
     * Time to resolution of the tickets resolved in the period.
     *
     * @return array{count: int, mean_seconds: float|null, median_seconds: float|null}
     */
    public function resolution(): array
    {
        return $this->durations($this->tickets()->whereBetween('resolved_at', $this->period()), 'created_at', 'resolved_at');
    }

    /**
     * Share of the tickets resolved in the period that had been reopened.
     */
    public function reopenRate(): ?float
    {
        $resolved = $this->tickets()->whereBetween('resolved_at', $this->period());
        $total = (clone $resolved)->count();

        return $total === 0 ? null : round((clone $resolved)->where('reopened_count', '>', 0)->count() / $total, 4);
    }

    /**
     * SLA compliance per target, over the clocks of the tickets created in the
     * period: met (completed in time), breached, still running.
     *
     * @return array<string, array{met: int, breached: int, running: int, compliance: float|null}>
     */
    public function sla(): array
    {
        $model = Ticketing::slaClockModel();

        $rows = $model::query()
            ->whereIn('ticket_id', $this->createdInPeriod()->select((new (Ticketing::ticketModel()))->getKeyName()))
            ->toBase()
            ->select('target')
            // A deadline missed between two sweeps is completed late without a
            // breach marker: the completion is compared with the deadline too.
            ->selectRaw('sum(case when breached_at is not null or completed_at > due_at then 1 else 0 end) as breached')
            ->selectRaw('sum(case when breached_at is null and completed_at is not null and completed_at <= due_at then 1 else 0 end) as met')
            ->selectRaw('sum(case when breached_at is null and completed_at is null then 1 else 0 end) as running')
            ->groupBy('target')
            ->orderBy('target')
            ->get();

        $targets = [];
        foreach ($rows as $row) {
            $met = (int) $row->met;
            $breached = (int) $row->breached;
            $targets[(string) $row->target] = [
                'met' => $met,
                'breached' => $breached,
                'running' => (int) $row->running,
                'compliance' => $met + $breached === 0 ? null : round($met / ($met + $breached), 4),
            ];
        }

        return $targets;
    }

    /**
     * The AI's share of the work: turns by outcome, tickets it handled,
     * resolved and escalated (with reasons), conversations, tokens and cost.
     *
     * @return array<string, mixed>
     */
    public function ai(): array
    {
        $turns = $this->aiTurns();

        $outcomes = [];
        foreach ((clone $turns)->toBase()->select('outcome')->selectRaw('count(*) as total')->groupBy('outcome')->get() as $row) {
            $outcomes[(string) ($row->outcome ?? 'running')] = (int) $row->total;
        }

        $cost = [];
        foreach ((clone $turns)->toBase()->select('currency')->selectRaw('sum(cost) as total')->groupBy('currency')->get() as $row) {
            if ($row->currency !== null) {
                $cost[(string) $row->currency] = round((float) $row->total, 6);
            }
        }

        $handled = $this->createdInPeriod()->whereNotNull('ai_status');
        $total = (clone $handled)->count();
        $resolved = (clone $handled)->where('ai_status', AiStatus::Resolved->value)->count();
        $escalated = (clone $handled)->whereNotNull('escalated_at')->count();

        $reasons = [];
        foreach ($this->tickets()->whereBetween('escalated_at', $this->period())->whereNotNull('escalation_reason')
            ->toBase()->select('escalation_reason')->selectRaw('count(*) as total')->groupBy('escalation_reason')->get() as $row) {
            $reasons[(string) $row->escalation_reason] = (int) $row->total;
        }

        arsort($reasons);

        return [
            'turns' => array_sum($outcomes),
            'outcomes' => $outcomes,
            'tickets' => $total,
            'resolved_by_ai' => $resolved,
            'escalated' => $escalated,
            'resolution_rate' => $total === 0 ? null : round($resolved / $total, 4),
            'escalation_rate' => $total === 0 ? null : round($escalated / $total, 4),
            'escalation_reasons' => $reasons,
            'conversations' => $this->conversations(),
            'input_tokens' => (int) (clone $turns)->sum('input_tokens'),
            'output_tokens' => (int) (clone $turns)->sum('output_tokens'),
            'cost' => $cost,
        ];
    }

    /**
     * Satisfaction: surveys sent and answered in the period, response rate,
     * and per scale the average and the distribution of the ratings.
     *
     * @return array{requested: int, submitted: int, response_rate: float|null, scales: array<string, array{submitted: int, average: float|null, distribution: array<int, int>}>}
     */
    public function csat(): array
    {
        $model = Ticketing::satisfactionRatingModel();
        $ratings = fn () => $model::query()->when($this->filtered, fn (Builder $query) => $query->whereIn('ticket_id', $this->ticketKeys()));

        $requested = $ratings()->whereBetween('requested_at', $this->period());
        $requestedCount = (clone $requested)->count();
        $answered = (clone $requested)->whereNotNull('submitted_at')->count();

        $submitted = $ratings()->whereBetween('submitted_at', $this->period())->whereNotNull('rating');

        $scales = [];
        foreach ((clone $submitted)->toBase()->select(['scale', 'rating'])->selectRaw('count(*) as total')->groupBy('scale', 'rating')->orderBy('scale')->orderBy('rating')->get() as $row) {
            $scale = (string) $row->scale;
            $scales[$scale] ??= ['submitted' => 0, 'average' => null, 'distribution' => []];
            $scales[$scale]['distribution'][(int) $row->rating] = (int) $row->total;
            $scales[$scale]['submitted'] += (int) $row->total;
        }

        foreach ($scales as $scale => $data) {
            $sum = 0;
            foreach ($data['distribution'] as $rating => $count) {
                $sum += $rating * $count;
            }

            $scales[$scale]['average'] = $data['submitted'] === 0 ? null : round($sum / $data['submitted'], 2);
        }

        return [
            'requested' => $requestedCount,
            'submitted' => array_sum(array_column($scales, 'submitted')),
            'response_rate' => $requestedCount === 0 ? null : round($answered / $requestedCount, 4),
            'scales' => $scales,
        ];
    }

    /**
     * The questions the AI could not answer (no sources, low confidence),
     * grouped by their normalised fingerprint, most asked first.
     *
     * @return list<array{question: string|null, asked: int, last_asked_at: string|null}>
     */
    public function knowledgeGaps(int $limit = 20): array
    {
        $gaps = $this->aiTurns()
            ->whereIn('escalation_reason', [EscalationReason::NoSources->value, EscalationReason::LowConfidence->value])
            ->whereNotNull('question_fingerprint');

        $groups = (clone $gaps)->toBase()
            ->select('question_fingerprint')
            ->selectRaw('count(*) as asked')
            ->selectRaw('max(created_at) as last_asked_at')
            ->groupBy('question_fingerprint')
            ->orderByDesc('asked')
            ->orderByDesc('last_asked_at')
            ->limit(max(1, $limit))
            ->get();

        // One sample per group (the latest wording), read by the database: a
        // question asked thousands of times is never loaded whole.
        $samples = [];

        foreach ($groups as $group) {
            $fingerprint = (string) $group->question_fingerprint;
            $question = (clone $gaps)->where('question_fingerprint', $fingerprint)->whereNotNull('question')
                ->orderByDesc('created_at')->limit(1)->value('question');

            $samples[$fingerprint] = is_string($question) ? $question : null;
        }

        return array_values($groups->map(fn (object $row): array => [
            'question' => $samples[(string) $row->question_fingerprint] ?? null,
            'asked' => (int) $row->asked,
            'last_asked_at' => $row->last_asked_at !== null ? Carbon::parse((string) $row->last_asked_at)->toIso8601String() : null,
        ])->all());
    }

    /**
     * Per agent, team, channel, category, priority or type: tickets created
     * and resolved in the period, open now, and mean first response and
     * resolution times.
     *
     * @return list<array{key: string|null, label: string|null, created: int, resolved: int, open: int, mean_first_response_seconds: float|null, mean_resolution_seconds: float|null}>
     */
    public function breakdown(string $dimension): array
    {
        $columns = self::DIMENSIONS[$dimension] ?? throw new InvalidArgumentException("Unknown breakdown dimension [{$dimension}].");

        // Indexed by "k:<key>" (or "none"): a numeric key would turn into an
        // integer array index otherwise.
        /** @var array<string, array{key: string|null, values: array<string, int|float|null>}> $groups */
        $groups = [];

        /**
         * @param  Builder<Ticket>  $query
         * @param  array<string, Expression>  $aggregates  field => aggregate
         */
        $merge = function (Builder $query, array $aggregates) use ($columns, &$groups): void {
            $base = $query->toBase()->select($columns);

            foreach ($aggregates as $field => $aggregate) {
                $base->addSelect(SqlDialect::alias($aggregate, $field));
            }

            foreach ($base->groupBy($columns)->get() as $row) {
                $values = array_map(fn (string $column): mixed => $row->{$column}, $columns);
                $key = in_array(null, $values, true) ? null : implode(':', array_map(fn (mixed $value): string => (string) $value, $values));
                $index = $key === null ? 'none' : 'k:'.$key;
                $groups[$index] ??= ['key' => $key, 'values' => []];

                foreach (array_keys($aggregates) as $field) {
                    $value = $row->{$field};
                    $groups[$index]['values'][$field] = str_starts_with($field, 'mean_')
                        ? ($value === null ? null : round((float) $value, 1))
                        : (int) $value;
                }
            }
        };

        $count = DB::raw('count(*)');

        $merge($this->createdInPeriod(), [
            'created' => $count,
            'mean_first_response_seconds' => SqlDialect::average(SqlDialect::secondsBetween('created_at', 'first_response_at')),
        ]);
        $merge($this->tickets()->whereBetween('resolved_at', $this->period()), [
            'resolved' => $count,
            'mean_resolution_seconds' => SqlDialect::average(SqlDialect::secondsBetween('created_at', 'resolved_at')),
        ]);
        $merge($this->open(), ['open' => $count]);

        $keys = [];
        foreach ($groups as $group) {
            if ($group['key'] !== null) {
                $keys[] = $group['key'];
            }
        }

        $labels = $this->labels($dimension, $keys);
        $rows = [];

        foreach ($groups as $group) {
            $key = $group['key'];
            $values = $group['values'];
            $rows[] = [
                'key' => $key,
                'label' => $key === null ? null : ($labels['k:'.$key] ?? $key),
                'created' => (int) ($values['created'] ?? 0),
                'resolved' => (int) ($values['resolved'] ?? 0),
                'open' => (int) ($values['open'] ?? 0),
                'mean_first_response_seconds' => isset($values['mean_first_response_seconds']) ? (float) $values['mean_first_response_seconds'] : null,
                'mean_resolution_seconds' => isset($values['mean_resolution_seconds']) ? (float) $values['mean_resolution_seconds'] : null,
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$b['created'], $b['open']] <=> [$a['created'], $a['open']]);

        return $rows;
    }

    /**
     * The tickets of the period (created in it) as a streamed CSV download.
     *
     * @param  list<string>|null  $columns  default: {@see CsvExport::TICKET_COLUMNS}
     */
    public function exportTickets(?array $columns = null, string $filename = 'tickets.csv'): StreamedResponse
    {
        return CsvExport::tickets($this->createdInPeriod(), $columns, $filename);
    }

    /**
     * A breakdown as a streamed CSV download.
     */
    public function exportBreakdown(string $dimension, ?string $filename = null): StreamedResponse
    {
        $rows = $this->breakdown($dimension);

        return CsvExport::rows(
            ['key', 'label', 'created', 'resolved', 'open', 'mean_first_response_seconds', 'mean_resolution_seconds'],
            $rows,
            $filename ?? "tickets-by-{$dimension}.csv",
        );
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    protected function period(): array
    {
        return [$this->from, $this->to];
    }

    /**
     * The filtered tickets, unordered.
     *
     * @return Builder<Ticket>
     */
    protected function tickets(): Builder
    {
        return $this->query->builder()->reorder();
    }

    /**
     * @return Builder<Ticket>
     */
    protected function createdInPeriod(): Builder
    {
        return $this->tickets()->whereBetween('created_at', $this->period());
    }

    /**
     * @return Builder<Ticket>
     */
    protected function open(): Builder
    {
        return (clone $this->query)->notState('closed')->builder()->reorder();
    }

    /**
     * @return Builder<Ticket>
     */
    protected function ticketKeys(): Builder
    {
        return $this->tickets()->select((new (Ticketing::ticketModel()))->getKeyName());
    }

    /**
     * The AI turns of the period (of the filtered tickets, when filtered).
     *
     * @return Builder<AiTurn>
     */
    protected function aiTurns(): Builder
    {
        $model = Ticketing::aiTurnModel();

        return $model::query()
            ->whereBetween('created_at', $this->period())
            ->when($this->filtered, fn (Builder $query) => $query->whereIn('ticket_id', $this->ticketKeys()));
    }

    /**
     * Widget conversations of the period — only without a ticket filter, which
     * a conversation that never became a ticket cannot match.
     *
     * @return array{started: int, resolved_by_ai: int, escalated: int}|null
     */
    protected function conversations(): ?array
    {
        if ($this->filtered) {
            return null;
        }

        $model = Ticketing::conversationModel();
        $started = $model::query()->whereBetween('created_at', $this->period());

        return [
            'started' => (clone $started)->count(),
            'resolved_by_ai' => (clone $started)->where('resolved_by_ai', true)->count(),
            'escalated' => (clone $started)->whereNotNull('escalated_at')->count(),
        ];
    }

    /**
     * Count, mean and median of a duration, aggregated by the database (the
     * median is read at the middle offset of the ordered values).
     *
     * @param  Builder<Ticket>  $query
     * @return array{count: int, mean_seconds: float|null, median_seconds: float|null}
     */
    protected function durations(Builder $query, string $from, string $to): array
    {
        $duration = SqlDialect::secondsBetween($from, $to);
        $values = (clone $query)->whereNotNull($from)->whereNotNull($to);
        $count = (clone $values)->count();

        if ($count === 0) {
            return ['count' => 0, 'mean_seconds' => null, 'median_seconds' => null];
        }

        $mean = (clone $values)->toBase()->select([SqlDialect::alias(SqlDialect::average($duration), 'mean')])->value('mean');

        $middle = (clone $values)->toBase()
            ->select([SqlDialect::alias($duration, 'duration')])
            ->orderBy($duration)
            ->offset(intdiv($count - 1, 2))
            ->limit($count % 2 === 0 ? 2 : 1)
            ->pluck('duration')
            ->map(fn (mixed $value): float => (float) $value);

        return [
            'count' => $count,
            'mean_seconds' => is_numeric($mean) ? round((float) $mean, 1) : null,
            'median_seconds' => $middle->isEmpty() ? null : round((float) $middle->avg(), 1),
        ];
    }

    /**
     * Human labels for breakdown keys (indexed "k:<key>"): team names, type
     * names, agent names, priority names.
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    protected function labels(string $dimension, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return match ($dimension) {
            'team' => $this->names(Ticketing::teamModel(), $keys, 'name'),
            'type' => $this->names(Ticketing::ticketTypeModel(), $keys, 'name'),
            'priority' => array_combine(
                array_map(fn (string $key): string => 'k:'.$key, $keys),
                array_map(fn (string $key): string => Priority::tryFrom((int) $key)?->label() ?? $key, $keys),
            ),
            'agent' => $this->agentNames($keys),
            default => [],
        };
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    protected function names(string $model, array $keys, string $attribute): array
    {
        $instance = new $model;
        $query = $model::query();

        if (method_exists($query, 'withoutTenancy')) {
            $query = $query->withoutTenancy();
        }

        $names = [];
        foreach ($query->whereIn($instance->getKeyName(), $keys)->get() as $row) {
            $value = $row->getAttribute($attribute);
            $names['k:'.$row->getKey()] = is_string($value) && $value !== '' ? $value : (string) $row->getKey();
        }

        return $names;
    }

    /**
     * "<morph type>:<id>" keys → the agent's name (or email) when the model
     * has one.
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    protected function agentNames(array $keys): array
    {
        $byType = [];
        foreach ($keys as $key) {
            $separator = strrpos($key, ':');

            if ($separator !== false) {
                $byType[substr($key, 0, $separator)][] = substr($key, $separator + 1);
            }
        }

        $labels = [];
        foreach ($byType as $type => $ids) {
            $class = Relation::getMorphedModel($type) ?? $type;

            if (! is_subclass_of($class, Model::class)) {
                continue;
            }

            $instance = new $class;
            foreach ($class::query()->whereIn($instance->getKeyName(), $ids)->get() as $agent) {
                $name = $agent->getAttribute('name') ?? $agent->getAttribute('email');
                $labels['k:'.$type.':'.$agent->getKey()] = is_string($name) && $name !== '' ? $name : (string) $agent->getKey();
            }
        }

        return $labels;
    }
}
