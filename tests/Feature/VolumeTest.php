<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Models\TeamMember;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Notifications\RecipientResolver;
use Selli\Ticketing\Sla\SlaManager;
use Selli\Ticketing\Support\TicketTypeRegistry;

/**
 * Realistic-volume checks: the main queries must cost a bounded number of
 * statements whatever the size of the tenant (no N+1), on every database the
 * suite runs against.
 */
const VOLUME = 10_000;

/**
 * Bulk-insert $count tickets (bypassing events, for speed) and return their ids.
 *
 * @param  array<string, mixed>  $overrides
 * @return list<int>
 */
function seedTickets(int $count, array $overrides = []): array
{
    $type = app(TicketTypeRegistry::class)->resolve('support');
    $table = (new Ticket)->getTable();
    $start = Carbon::parse('2026-01-01 00:00:00');
    $priorities = array_map(fn (Priority $p): int => $p->value, Priority::cases());
    $prefix = 'VOL'.bin2hex(random_bytes(3)).'-';

    foreach (array_chunk(range(1, $count), 500) as $chunk) {
        DB::table($table)->insert(array_map(fn (int $i): array => array_merge([
            'reference' => $prefix.$i,
            'ticket_type_id' => $type->getKey(),
            'title' => "Volume ticket {$i}",
            'priority' => $priorities[$i % count($priorities)],
            'status' => $i % 5 === 0 ? 'resolved' : 'open',
            'channel' => $i % 3 === 0 ? 'email' : 'web',
            'reopened_count' => 0,
            'created_at' => $start->copy()->addMinutes($i),
            'updated_at' => $start->copy()->addMinutes($i),
        ], $overrides), $chunk));
    }

    return array_map('intval', DB::table($table)->where('reference', 'like', $prefix.'%')->pluck('id')->all());
}

function countQueries(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $callback();
    } finally {
        DB::disableQueryLog();
    }

    return count(DB::getQueryLog());
}

it('lists and filters 10k tickets in a constant number of queries', function (): void {
    seedTickets(VOLUME);

    $queries = countQueries(function (): void {
        $page = Ticketing::tickets()->state('open')->priority([Priority::High, Priority::Urgent])->sort('priority')->paginate(25);

        expect($page->total())->toBe(4000)
            ->and($page->items())->toHaveCount(25);
    });

    // The workflow lookup, one COUNT and one page select, whatever the volume.
    expect($queries)->toBe(3);

    $searchQueries = countQueries(function (): void {
        expect(Ticketing::tickets()->search('Volume ticket 9999')->channel('email')->count())->toBe(1);
    });

    expect($searchQueries)->toBe(1);
});

it('serves the REST listing over 10k tickets without per-row queries', function (): void {
    seedTickets(VOLUME);
    $agent = makeUser(['name' => 'Agent']);
    $this->actingAs($agent);

    $queries = countQueries(function (): void {
        $this->getJson(API.'/tickets?state=open&sort=-created_at&per_page=100')
            ->assertOk()
            ->assertJsonCount(100, 'data');
    });

    expect($queries)->toBeLessThanOrEqual(4);
});

it('picks the least busy agent with one load query, whatever the team size', function (): void {
    $type = app(TicketTypeRegistry::class)->resolve('support');

    $measure = function (int $members) use ($type): int {
        $team = Team::factory()->create();
        $agents = [];

        foreach (range(1, $members) as $i) {
            $agent = makeUser(['name' => "Agent {$i}", 'email' => "agent{$i}@example.test"]);
            TeamMember::factory()->member($agent)->create(['team_id' => $team->getKey()]);
            $agents[] = $agent;
        }

        // Give every agent but the last a backlog, spread over 5k tickets.
        $ids = seedTickets(VOLUME / 2);
        $slices = array_chunk($ids, (int) ceil(count($ids) / ($members - 1)));
        foreach ($slices as $i => $slice) {
            $agent = $agents[$i];
            foreach (array_chunk($slice, 500) as $chunk) {
                DB::table((new Ticket)->getTable())->whereIn('id', $chunk)->update([
                    'assignee_type' => $agent->getMorphClass(),
                    'assignee_id' => (string) $agent->getKey(),
                ]);
            }
        }

        $ticket = Ticketing::open(type: $type->key, title: 'Needs an agent');

        $queries = countQueries(function () use ($ticket, $team): void {
            Ticketing::assign($ticket, team: $team, strategy: 'least-busy');
        });

        expect($ticket->fresh()->assignee_id)->toBe((string) end($agents)->getKey());

        return $queries;
    };

    // The same number of statements for a 3-agent and a 25-agent team.
    expect($measure(3))->toBe($measure(25));
});

it('sweeps thousands of running SLA clocks without per-clock queries', function (): void {
    $ids = seedTickets(VOLUME);
    $now = now();
    $table = Ticketing::slaClockModel();
    $clockTable = (new $table)->getTable();

    foreach (array_chunk($ids, 500) as $chunk) {
        DB::table($clockTable)->insert(array_map(fn (int $id): array => [
            'ticket_id' => $id,
            'target' => 'resolution',
            'budget_minutes' => 2880,
            'started_at' => $now,
            'due_at' => $now->copy()->addDays(2),
            'threshold_notified' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ], $chunk));
    }

    $queries = countQueries(fn () => app(SlaManager::class)->sweep(75, 500));

    // 20 chunks × (clock page + eager-loaded tickets) + the final empty page;
    // far below one query per clock.
    expect($queries)->toBeLessThanOrEqual(2 * (VOLUME / 500) + 2);
});

it('resolves 50 notification recipients in a constant number of queries', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'Busy thread');
    $participants = Ticketing::ticketParticipantModel();

    foreach (range(1, 50) as $i) {
        $user = makeUser(['name' => "Watcher {$i}", 'email' => "w{$i}@example.test"]);
        $participants::query()->create(array_merge($ticket->tenantAttributes(), [
            'ticket_id' => $ticket->getKey(),
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->getKey(),
            'role' => ParticipantRole::Watcher->value,
            'notify' => true,
        ]));
    }

    $resolved = [];
    $queries = countQueries(function () use ($ticket, &$resolved): void {
        $resolved = app(RecipientResolver::class)->participants($ticket, [ParticipantRole::Watcher->value]);
    });

    expect($resolved)->toHaveCount(50)
        ->and($queries)->toBe(2);
});

it('computes the KPIs of 10k tickets in the database, in a bounded number of queries', function (): void {
    seedTickets(VOLUME, ['first_response_at' => Carbon::parse('2026-01-05 00:00:00')]);
    Carbon::setTestNow('2026-02-01 00:00:00');

    try {
        $metrics = Ticketing::metrics()->between(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31 23:59:59'));
        $summary = null;

        $queries = countQueries(function () use ($metrics, &$summary): void {
            $summary = $metrics->summary();
        });

        // Volume and its daily series, backlog buckets, durations with their
        // medians, SLA, AI, CSAT, gaps: independent of the number of tickets.
        expect($summary['volume']['created'])->toBe(VOLUME)
            ->and($summary['first_response']['count'])->toBe(VOLUME)
            ->and($summary['backlog']['open'])->toBe(8000)
            ->and($queries)->toBeLessThanOrEqual(45);

        $breakdown = countQueries(fn () => expect($metrics->breakdown('channel'))->toHaveCount(2));
        expect($breakdown)->toBeLessThanOrEqual(8);
    } finally {
        Carbon::setTestNow();
    }
});
