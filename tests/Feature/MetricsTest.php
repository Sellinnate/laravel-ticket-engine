<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Query\TicketQuery;
use Selli\Ticketing\Support\TicketTypeRegistry;
use Selli\Ticketing\Tenancy\TenantContext;

/**
 * Insert a ticket row as it would look after its life so far.
 *
 * @param  array<string, mixed>  $attributes
 */
function pastTicket(array $attributes = []): int|string
{
    static $sequence = 0;
    $sequence++;

    $created = Carbon::parse($attributes['created_at'] ?? '2026-03-02 09:00:00');

    $ticket = new Ticket;
    $ticket->forceFill(array_merge([
        'reference' => 'MET-'.$sequence,
        'ticket_type_id' => app(TicketTypeRegistry::class)->resolve($attributes['type'] ?? 'support')->getKey(),
        'title' => 'Ticket '.$sequence,
        'priority' => 20,
        'status' => 'open',
        'channel' => 'web',
        'reopened_count' => 0,
        'updated_at' => $created,
    ], array_diff_key($attributes, ['type' => true]), ['created_at' => $created]));
    $ticket->saveQuietly();

    return $ticket->getKey();
}

function period(): array
{
    return [Carbon::parse('2026-03-01 00:00:00'), Carbon::parse('2026-03-31 23:59:59')];
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-03-31 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('counts the tickets created, resolved and closed in the period, per day', function (): void {
    pastTicket(['created_at' => '2026-03-02 09:00:00']);
    pastTicket(['created_at' => '2026-03-02 17:00:00', 'status' => 'resolved', 'resolved_at' => '2026-03-03 10:00:00']);
    pastTicket(['created_at' => '2026-03-05 08:00:00', 'status' => 'closed', 'closed_at' => '2026-03-06 08:00:00']);
    pastTicket(['created_at' => '2026-02-20 08:00:00', 'status' => 'resolved', 'resolved_at' => '2026-03-01 08:00:00']);
    pastTicket(['created_at' => '2026-04-02 08:00:00']);

    $volume = Ticketing::metrics()->between(...period())->volume();

    expect($volume)->toBe([
        'created' => 3,
        'resolved' => 2,
        'closed' => 1,
        'per_day' => ['2026-03-02' => 2, '2026-03-05' => 1],
    ]);
});

it('reports the backlog by age, whatever the period', function (): void {
    pastTicket(['created_at' => '2026-03-31 08:00:00']);
    pastTicket(['created_at' => '2026-03-29 08:00:00', 'status' => 'pending', 'assignee_type' => 'user', 'assignee_id' => '1']);
    pastTicket(['created_at' => '2026-03-20 08:00:00']);
    pastTicket(['created_at' => '2026-01-01 08:00:00']);
    pastTicket(['created_at' => '2026-03-30 08:00:00', 'status' => 'resolved']);
    pastTicket(['created_at' => '2026-03-30 08:00:00', 'status' => 'closed']);

    expect(Ticketing::metrics()->between(...period())->backlog())->toBe([
        'open' => 4,
        'unassigned' => 3,
        'age' => ['< 1 day' => 1, '1-3 days' => 1, '3-7 days' => 0, '7-30 days' => 1, '> 30 days' => 1],
    ]);
});

it('measures first response and resolution: count, mean and median', function (): void {
    // First responses after 1h, 2h, 6h (created in the period); one unanswered.
    pastTicket(['created_at' => '2026-03-02 08:00:00', 'first_response_at' => '2026-03-02 09:00:00', 'status' => 'resolved', 'resolved_at' => '2026-03-03 08:00:00']);
    pastTicket(['created_at' => '2026-03-02 08:00:00', 'first_response_at' => '2026-03-02 10:00:00', 'status' => 'resolved', 'resolved_at' => '2026-03-02 12:00:00']);
    pastTicket(['created_at' => '2026-03-02 08:00:00', 'first_response_at' => '2026-03-02 14:00:00']);
    pastTicket(['created_at' => '2026-03-02 08:00:00']);
    // Resolved in the period, created before it: counts for resolution only.
    pastTicket(['created_at' => '2026-02-26 08:00:00', 'first_response_at' => '2026-02-26 08:30:00', 'status' => 'resolved', 'resolved_at' => '2026-03-02 08:00:00']);

    $metrics = Ticketing::metrics()->between(...period());

    expect($metrics->firstResponse())->toBe(['count' => 3, 'mean_seconds' => 10800.0, 'median_seconds' => 7200.0])
        // 24h, 4h, 96h → mean 41.33h, median 24h
        ->and($metrics->resolution())->toBe(['count' => 3, 'mean_seconds' => 148800.0, 'median_seconds' => 86400.0]);
});

it('takes the median of an even count as the mean of the two middle values', function (): void {
    foreach (['09:00:00', '10:00:00', '12:00:00', '16:00:00'] as $time) {
        pastTicket(['created_at' => '2026-03-02 08:00:00', 'first_response_at' => '2026-03-02 '.$time]);
    }

    expect(Ticketing::metrics()->between(...period())->firstResponse()['median_seconds'])->toBe(10800.0)
        ->and(Ticketing::metrics()->between(Carbon::parse('2025-01-01'), Carbon::parse('2025-01-31'))->firstResponse())
        ->toBe(['count' => 0, 'mean_seconds' => null, 'median_seconds' => null]);
});

it('reports the reopen rate of the tickets resolved in the period', function (): void {
    pastTicket(['status' => 'resolved', 'resolved_at' => '2026-03-10 08:00:00', 'reopened_count' => 1]);
    pastTicket(['status' => 'resolved', 'resolved_at' => '2026-03-10 08:00:00']);
    pastTicket(['status' => 'resolved', 'resolved_at' => '2026-03-10 08:00:00']);
    pastTicket(['status' => 'resolved', 'resolved_at' => '2026-03-11 08:00:00']);

    expect(Ticketing::metrics()->between(...period())->reopenRate())->toBe(0.25)
        ->and(Ticketing::metrics()->between(Carbon::parse('2025-01-01'), Carbon::parse('2025-01-31'))->reopenRate())->toBeNull();
});

it('reports SLA compliance per target', function (): void {
    $clock = function (int|string $ticket, string $target, ?string $breached, ?string $completed): void {
        DB::table((new (Ticketing::slaClockModel()))->getTable())->insert([
            'ticket_id' => $ticket, 'target' => $target, 'started_at' => '2026-03-02 08:00:00', 'due_at' => '2026-03-02 12:00:00',
            'breached_at' => $breached, 'completed_at' => $completed, 'threshold_notified' => false,
            'created_at' => '2026-03-02 08:00:00', 'updated_at' => '2026-03-02 08:00:00',
        ]);
    };

    $clock(pastTicket(), 'first_response', null, '2026-03-02 09:00:00');
    $clock(pastTicket(), 'first_response', null, '2026-03-02 10:00:00');
    $clock(pastTicket(), 'first_response', '2026-03-02 12:00:00', '2026-03-02 13:00:00');
    $clock(pastTicket(), 'resolution', null, null);
    $clock(pastTicket(['created_at' => '2026-02-01 08:00:00']), 'resolution', '2026-02-02 08:00:00', null);

    expect(Ticketing::metrics()->between(...period())->sla())->toBe([
        'first_response' => ['met' => 2, 'breached' => 1, 'running' => 0, 'compliance' => 0.6667],
        'resolution' => ['met' => 0, 'breached' => 0, 'running' => 1, 'compliance' => null],
    ]);
});

it('reports the AI share of the work, its escalations and its cost', function (): void {
    $turn = function (array $attributes): void {
        static $n = 0;
        $n++;
        DB::table((new (Ticketing::aiTurnModel()))->getTable())->insert(array_merge([
            'trigger_key' => 'ticket_message:'.$n, 'mode' => 'auto', 'status' => 'done', 'driver' => 'fake',
            'created_at' => '2026-03-02 08:00:00', 'updated_at' => '2026-03-02 08:00:00',
        ], $attributes));
    };

    $a = pastTicket(['ai_status' => 'resolved']);
    $b = pastTicket(['ai_status' => 'escalated', 'escalated_at' => '2026-03-02 09:00:00', 'escalation_reason' => 'no_sources']);
    $c = pastTicket(['ai_status' => 'suspended', 'escalated_at' => '2026-03-02 09:00:00', 'escalation_reason' => 'human_requested']);
    pastTicket(['ai_status' => 'active']);
    pastTicket();

    $turn(['ticket_id' => $a, 'outcome' => 'answered', 'input_tokens' => 100, 'output_tokens' => 20, 'cost' => 0.01, 'currency' => 'EUR']);
    $turn(['ticket_id' => $b, 'outcome' => 'escalated', 'escalation_reason' => 'no_sources', 'cost' => 0.02, 'currency' => 'EUR']);
    $turn(['ticket_id' => $c, 'outcome' => 'escalated', 'escalation_reason' => 'human_requested', 'cost' => 0.5, 'currency' => 'USD']);
    $turn(['ticket_id' => $c, 'outcome' => 'answered', 'created_at' => '2026-02-01 08:00:00']);

    DB::table((new (Ticketing::conversationModel()))->getTable())->insert([
        ['public_id' => 'c1', 'channel' => 'widget', 'token_hash' => 'h1', 'status' => 'closed', 'resolved_by_ai' => true, 'escalated_at' => null, 'created_at' => '2026-03-03 08:00:00', 'updated_at' => '2026-03-03 08:00:00'],
        ['public_id' => 'c2', 'channel' => 'widget', 'token_hash' => 'h2', 'status' => 'escalated', 'resolved_by_ai' => false, 'escalated_at' => '2026-03-03 09:00:00', 'created_at' => '2026-03-03 08:00:00', 'updated_at' => '2026-03-03 08:00:00'],
    ]);

    $ai = Ticketing::metrics()->between(...period())->ai();

    expect($ai)->toMatchArray([
        'turns' => 3,
        'tickets' => 4,
        'resolved_by_ai' => 1,
        'escalated' => 2,
        'resolution_rate' => 0.25,
        'escalation_rate' => 0.5,
        'escalation_reasons' => ['human_requested' => 1, 'no_sources' => 1],
        'conversations' => ['started' => 2, 'resolved_by_ai' => 1, 'escalated' => 1],
        'input_tokens' => 100,
        'output_tokens' => 20,
        'cost' => ['EUR' => 0.03, 'USD' => 0.5],
    ])->and($ai['outcomes'])->toEqual(['answered' => 1, 'escalated' => 2]);

    // A ticket filter narrows the turns too, and leaves conversations out.
    $filtered = Ticketing::metrics()->between(...period())->where(fn (TicketQuery $query) => $query->where(fn ($builder) => $builder->whereKey($a)))->ai();
    expect($filtered['turns'])->toBe(1)->and($filtered['conversations'])->toBeNull();
});

it('reports the AI share of the work for a filtered set of tickets', function (): void {
    $a = pastTicket(['ai_status' => 'resolved', 'channel' => 'email']);
    $b = pastTicket(['ai_status' => 'escalated', 'escalated_at' => '2026-03-02 09:00:00', 'escalation_reason' => 'no_sources', 'channel' => 'web']);

    foreach ([$a, $b] as $i => $ticket) {
        DB::table((new (Ticketing::aiTurnModel()))->getTable())->insert([
            'ticket_id' => $ticket, 'trigger_key' => 'ticket_message:f'.$i, 'mode' => 'auto', 'status' => 'done', 'driver' => 'fake',
            'outcome' => $i === 0 ? 'answered' : 'escalated', 'cost' => 0.01, 'currency' => 'EUR',
            'created_at' => '2026-03-02 08:00:00', 'updated_at' => '2026-03-02 08:00:00',
        ]);
    }

    $ai = Ticketing::metrics()->between(...period())->filter(['channel' => 'email'])->ai();

    expect($ai['turns'])->toBe(1)
        ->and($ai['tickets'])->toBe(1)
        ->and($ai['resolved_by_ai'])->toBe(1)
        ->and($ai['escalation_reasons'])->toBe([])
        ->and($ai['conversations'])->toBeNull();
});

it('reports satisfaction per scale and the response rate', function (): void {
    $rating = function (int|string $ticket, ?int $value, string $scale = 'csat5'): void {
        DB::table((new (Ticketing::satisfactionRatingModel()))->getTable())->insert([
            'ticket_id' => $ticket, 'scale' => $scale, 'rating' => $value,
            'requested_at' => '2026-03-02 08:00:00', 'submitted_at' => $value === null ? null : '2026-03-03 08:00:00',
            'created_at' => '2026-03-02 08:00:00', 'updated_at' => '2026-03-02 08:00:00',
        ]);
    };

    $rating(pastTicket(), 5);
    $rating(pastTicket(), 4);
    $rating(pastTicket(), 5);
    $rating(pastTicket(), null);
    $rating(pastTicket(), 1, 'thumbs');

    expect(Ticketing::metrics()->between(...period())->csat())->toBe([
        'requested' => 5,
        'submitted' => 4,
        'response_rate' => 0.8,
        'scales' => [
            'csat5' => ['submitted' => 3, 'average' => 4.67, 'distribution' => [4 => 1, 5 => 2]],
            'thumbs' => ['submitted' => 1, 'average' => 1.0, 'distribution' => [1 => 1]],
        ],
    ]);
});

it('groups the questions the AI could not answer', function (): void {
    $gap = function (string $fingerprint, ?string $question, string $reason, string $at): void {
        static $n = 0;
        $n++;
        DB::table((new (Ticketing::aiTurnModel()))->getTable())->insert([
            'trigger_key' => 'conversation_message:'.$n, 'mode' => 'auto', 'status' => 'done', 'driver' => 'fake',
            'outcome' => 'escalated', 'escalation_reason' => $reason, 'question' => $question, 'question_fingerprint' => $fingerprint,
            'created_at' => $at, 'updated_at' => $at,
        ]);
    };

    $gap('refund', 'How do I get a refund?', 'no_sources', '2026-03-02 08:00:00');
    $gap('refund', 'how do i get a refund', 'low_confidence', '2026-03-05 08:00:00');
    $gap('refund', null, 'no_sources', '2026-03-04 08:00:00');
    $gap('invoice', 'Where is my invoice?', 'no_sources', '2026-03-03 08:00:00');
    $gap('human', 'Let me talk to a person', 'human_requested', '2026-03-03 08:00:00');

    expect(Ticketing::metrics()->between(...period())->knowledgeGaps())->toBe([
        ['question' => 'how do i get a refund', 'asked' => 3, 'last_asked_at' => Carbon::parse('2026-03-05 08:00:00')->toIso8601String()],
        ['question' => 'Where is my invoice?', 'asked' => 1, 'last_asked_at' => Carbon::parse('2026-03-03 08:00:00')->toIso8601String()],
    ]);
});

it('breaks the tickets down per team, with names', function (): void {
    $desk = Team::factory()->create(['name' => 'Desk']);
    $field = Team::factory()->create(['name' => 'Field']);

    pastTicket(['team_id' => $desk->getKey(), 'first_response_at' => '2026-03-02 10:00:00']);
    pastTicket(['team_id' => $desk->getKey(), 'first_response_at' => '2026-03-02 12:00:00', 'status' => 'resolved', 'resolved_at' => '2026-03-03 09:00:00']);
    pastTicket(['team_id' => $field->getKey(), 'created_at' => '2026-02-01 08:00:00']);
    pastTicket();

    $rows = collect(Ticketing::metrics()->between(...period())->breakdown('team'))->keyBy(fn (array $row): string => (string) $row['key']);

    expect($rows[(string) $desk->getKey()])->toBe([
        'key' => (string) $desk->getKey(), 'label' => 'Desk', 'created' => 2, 'resolved' => 1, 'open' => 1,
        'mean_first_response_seconds' => 7200.0, 'mean_resolution_seconds' => 86400.0,
    ])->and($rows[(string) $field->getKey()])->toMatchArray(['label' => 'Field', 'created' => 0, 'open' => 1])
        ->and($rows[''])->toMatchArray(['key' => null, 'label' => null, 'created' => 1, 'open' => 1]);
});

it('breaks the tickets down per agent, channel and priority', function (): void {
    $agent = makeUser(['name' => 'Ada']);
    pastTicket(['assignee_type' => $agent->getMorphClass(), 'assignee_id' => (string) $agent->getKey(), 'channel' => 'email', 'priority' => 40]);
    pastTicket(['channel' => 'email', 'priority' => 40]);

    $metrics = Ticketing::metrics()->between(...period());
    $agents = collect($metrics->breakdown('agent'))->pluck('label', 'key');

    expect($agents[$agent->getMorphClass().':'.$agent->getKey()])->toBe('Ada')
        ->and(collect($metrics->breakdown('channel'))->firstWhere('key', 'email')['created'])->toBe(2)
        ->and(collect($metrics->breakdown('priority'))->firstWhere('key', '40')['label'])->toBe(Priority::Urgent->label())
        ->and(fn () => $metrics->breakdown('mood'))->toThrow(InvalidArgumentException::class);
});

it('stays inside the current tenant', function (): void {
    app(TenantContext::class)->forTenant(1, fn () => pastTicket(['tenant_id' => 1]));
    app(TenantContext::class)->forTenant(2, fn () => pastTicket(['tenant_id' => 2]));
    app(TenantContext::class)->forTenant(2, fn () => pastTicket(['tenant_id' => 2]));

    expect(app(TenantContext::class)->forTenant(2, fn () => Ticketing::metrics()->between(...period())->volume()['created']))->toBe(2);
});

it('streams the tickets of the period as CSV, safe for spreadsheets', function (): void {
    pastTicket(['title' => '=HYPERLINK("http://evil.test")', 'created_at' => '2026-03-02 08:00:00']);
    pastTicket(['title' => 'Plain', 'created_at' => '2026-03-03 08:00:00']);
    pastTicket(['title' => 'Too old', 'created_at' => '2026-01-03 08:00:00']);

    $response = Ticketing::metrics()->between(...period())->exportTickets(['reference', 'title', 'priority', 'created_at']);

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();
    $lines = array_values(array_filter(explode("\n", $csv)));

    expect($response->headers->get('Content-Type'))->toBe('text/csv; charset=UTF-8')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="tickets.csv"')
        ->and($lines)->toHaveCount(3)
        ->and($lines[0])->toBe('reference,title,priority,created_at')
        ->and($lines[1])->toContain(',"\'=HYPERLINK(""http://evil.test"")",20,')
        ->and($csv)->not->toContain('Too old');
});

it('streams a breakdown as CSV', function (): void {
    pastTicket(['channel' => 'email']);

    $response = Ticketing::metrics()->between(...period())->exportBreakdown('channel');

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    expect($csv)->toStartWith("key,label,created,resolved,open,mean_first_response_seconds,mean_resolution_seconds\n")
        ->and($csv)->toContain("email,email,1,0,1,,\n");
});

it('computes every section of the summary on the same tickets', function (): void {
    pastTicket(['first_response_at' => '2026-03-02 10:00:00']);
    pastTicket(['first_response_at' => '2026-03-02 11:00:00', 'status' => 'resolved', 'resolved_at' => '2026-03-03 09:00:00']);

    $metrics = Ticketing::metrics()->between(...period());
    $summary = $metrics->summary();

    // The backlog (open tickets only) must not narrow the sections after it.
    expect($summary['backlog']['open'])->toBe(1)
        ->and($summary['first_response'])->toBe($metrics->firstResponse())
        ->and($summary['first_response']['count'])->toBe(2)
        ->and($summary)->toHaveKeys(['period', 'volume', 'resolution', 'reopen_rate', 'sla', 'ai', 'csat', 'knowledge_gaps']);
});

it('rejects a period that ends before it starts', function (): void {
    expect(fn () => Ticketing::metrics()->between(Carbon::parse('2026-03-02'), Carbon::parse('2026-03-01')))
        ->toThrow(InvalidArgumentException::class);
});
