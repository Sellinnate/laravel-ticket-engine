<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Enums\TicketChannel;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketType;
use Selli\Ticketing\Query\TicketQuery;
use Selli\Ticketing\Tenancy\TenantContext;
use Selli\Ticketing\Workflow\ConfigWorkflowDriver;
use Selli\Ticketing\Workflow\WorkflowManager;

/**
 * @param  list<Ticket>|Collection<int, Ticket>  $tickets
 * @return list<string>
 */
function titles(iterable $tickets): array
{
    $out = [];

    foreach ($tickets as $ticket) {
        $out[] = $ticket->title;
    }

    sort($out);

    return $out;
}

it('records the channel a ticket was opened through', function (): void {
    $web = Ticketing::open(type: 'support', title: 'From the app');
    $email = Ticketing::open(type: 'support', title: 'From mail', channel: TicketChannel::Email);
    $custom = Ticketing::for(makeOrder())->open(type: 'support', title: 'From WhatsApp', channel: 'whatsapp');

    expect($web->channel)->toBe('web')
        ->and($email->channel)->toBe('email')
        ->and($custom->channel)->toBe('whatsapp');
});

it('filters by the open/closed/paused semantic of each ticket type workflow', function (): void {
    $support = Ticketing::open(type: 'support', title: 'support open');
    $pending = Ticketing::open(type: 'support', title: 'support pending');
    Ticketing::transition($pending, 'wait');
    $incident = Ticketing::open(type: 'incident', title: 'incident new');
    $resolved = Ticketing::open(type: 'incident', title: 'incident resolved');
    Ticketing::transition($resolved, 'triage');
    Ticketing::transition($resolved, 'start');
    Ticketing::transition($resolved, 'resolve', note: 'fixed');

    expect(titles(Ticketing::tickets()->state('open')->get()))->toBe(['incident new', 'support open'])
        ->and(titles(Ticketing::tickets()->state('paused')->get()))->toBe(['support pending'])
        ->and(titles(Ticketing::tickets()->state('closed')->get()))->toBe(['incident resolved']);

    expect(fn () => Ticketing::tickets()->state('archived'))->toThrow(InvalidArgumentException::class);
    expect($support->exists && $incident->exists)->toBeTrue();
});

it('combines status, priority, type, category and channel filters', function (): void {
    Ticketing::open(type: 'support', title: 'a', priority: Priority::High, category: 'billing');
    Ticketing::open(type: 'support', title: 'b', priority: Priority::Low, category: 'billing', channel: 'email');
    Ticketing::open(type: 'incident', title: 'c', priority: Priority::High, category: 'outage');

    expect(titles(Ticketing::tickets()->priority(Priority::High)->get()))->toBe(['a', 'c'])
        ->and(titles(Ticketing::tickets()->type('incident')->get()))->toBe(['c'])
        ->and(titles(Ticketing::tickets()->category('billing')->channel('email')->get()))->toBe(['b'])
        ->and(titles(Ticketing::tickets()->status(['new'])->get()))->toBe(['c'])
        ->and(Ticketing::tickets()->type(['support', 'incident'])->count())->toBe(3);
});

it('filters by assignee, team and the unassigned queue', function (): void {
    $agent = makeUser(['name' => 'Agent']);
    $other = makeUser(['name' => 'Other']);
    $team = Team::factory()->create();

    $mine = Ticketing::open(type: 'support', title: 'mine');
    Ticketing::assign($mine, assignee: $agent);
    $theirs = Ticketing::open(type: 'support', title: 'theirs');
    Ticketing::assign($theirs, assignee: $other, team: $team);
    Ticketing::open(type: 'support', title: 'nobody');

    expect(titles(Ticketing::tickets()->assignedTo($agent)->get()))->toBe(['mine'])
        ->and(titles(Ticketing::tickets()->unassigned()->get()))->toBe(['nobody'])
        ->and(titles(Ticketing::tickets()->forTeam($team)->get()))->toBe(['theirs'])
        ->and(titles(Ticketing::tickets()->filter(['assignee' => 'me'], $agent)->get()))->toBe(['mine'])
        ->and(titles(Ticketing::tickets()->filter(['assignee' => 'none'])->get()))->toBe(['nobody'])
        ->and(titles(Ticketing::tickets()->filter(['assignee' => $other->getMorphClass().':'.$other->getKey()])->get()))->toBe(['theirs'])
        ->and(titles(Ticketing::tickets()->filter(['team' => (string) $team->getKey()])->get()))->toBe(['theirs']);

    // "me" without an acting model, or a team key that cannot be an id, match
    // nothing — a malformed filter never widens the listing.
    expect(Ticketing::tickets()->filter(['assignee' => 'me'])->count())->toBe(0)
        ->and(Ticketing::tickets()->filter(['team' => 'not-a-number'])->count())->toBe(0)
        ->and(Ticketing::tickets()->filter(['team' => '999999999999999999999999999999'])->count())->toBe(0)
        ->and(Ticketing::tickets()->filter(['team' => '-1'])->count())->toBe(0);
});

it('filters by tag, requester, subject and involvement', function (): void {
    $requester = makeUser(['name' => 'Jane']);
    $watcher = makeUser(['name' => 'Watcher']);
    $order = makeOrder();

    $tagged = Ticketing::open(type: 'support', title: 'tagged', requester: $requester);
    Ticketing::tag($tagged, ['VIP']);
    Ticketing::for($order)->open(type: 'support', title: 'about order');
    Ticketing::open(type: 'support', title: 'someone else', requester: $watcher);

    expect(titles(Ticketing::tickets()->taggedWith('vip')->get()))->toBe(['tagged'])
        ->and(titles(Ticketing::tickets()->filter(['tag' => 'vip,other'])->get()))->toBe(['tagged'])
        ->and(titles(Ticketing::tickets()->requestedBy($requester)->get()))->toBe(['tagged'])
        ->and(titles(Ticketing::tickets()->about($order)->get()))->toBe(['about order'])
        ->and(titles(Ticketing::tickets()->involving($watcher)->get()))->toBe(['someone else']);
});

it('filters by creation date range', function (): void {
    Carbon::setTestNow('2026-03-01 10:00:00');
    Ticketing::open(type: 'support', title: 'march');
    Carbon::setTestNow('2026-05-01 10:00:00');
    Ticketing::open(type: 'support', title: 'may');
    Carbon::setTestNow();

    expect(titles(Ticketing::tickets()->createdBetween(Carbon::parse('2026-04-01'), null)->get()))->toBe(['may'])
        ->and(titles(Ticketing::tickets()->filter(['created_from' => '2026-02-01', 'created_to' => '2026-03-31'])->get()))->toBe(['march'])
        ->and(Ticketing::tickets()->filter(['created_from' => 'not a date'])->count())->toBe(2)
        ->and(Ticketing::tickets()->filter(['created_to' => '99999-01-01'])->count())->toBe(2)
        ->and(Ticketing::tickets()->filter(['created_to' => '2026-02-31'])->count())->toBe(2)
        ->and(titles(Ticketing::tickets()->filter(['created_from' => '2026-03-01', 'created_to' => '2026-03-01'])->get()))->toBe(['march'])
        ->and(titles(Ticketing::tickets()->filter(['created_from' => '2026-04-30T23:00:00Z'])->get()))->toBe(['may'])
        // 12:00+02:00 is 10:00 UTC: the march ticket (10:00 UTC) is included.
        ->and(titles(Ticketing::tickets()->filter(['created_from' => '2026-03-01T12:00:00+02:00', 'created_to' => '2026-03-31'])->get()))->toBe(['march'])
        ->and(Ticketing::tickets()->filter(['created_from' => '2026-03-01T12:00:01+02:00', 'created_to' => '2026-03-31'])->count())->toBe(0);
});

it('searches the exact reference or a case-insensitive title substring', function (): void {
    $printer = Ticketing::open(type: 'support', title: 'Printer is BROKEN');
    Ticketing::open(type: 'support', title: 'Discount 100% off');
    Ticketing::open(type: 'support', title: 'Discount 1000 off');

    expect(titles(Ticketing::tickets()->search('broken')->get()))->toBe(['Printer is BROKEN'])
        ->and(titles(Ticketing::tickets()->search($printer->reference)->get()))->toBe(['Printer is BROKEN'])
        // LIKE wildcards in the term are literals, not patterns.
        ->and(titles(Ticketing::tickets()->search('100%')->get()))->toBe(['Discount 100% off'])
        ->and(Ticketing::tickets()->search('a_b')->count())->toBe(0);
});

it('sorts by a whitelisted column with a stable key tie-breaker', function (): void {
    Ticketing::open(type: 'support', title: 'low', priority: Priority::Low);
    Ticketing::open(type: 'support', title: 'urgent', priority: Priority::Urgent);
    Ticketing::open(type: 'support', title: 'normal', priority: Priority::Normal);

    $titles = Ticketing::tickets()->sort('priority', 'desc')->get()->pluck('title')->all();
    $ascending = Ticketing::tickets()->filter(['sort' => 'priority'])->get()->pluck('title')->all();
    $descending = Ticketing::tickets()->filter(['sort' => '-priority'])->get()->pluck('title')->all();

    expect($titles)->toBe(['urgent', 'normal', 'low'])
        ->and($ascending)->toBe(['low', 'normal', 'urgent'])
        ->and($descending)->toBe(['urgent', 'normal', 'low'])
        // An unknown sort key in loose filters is ignored (default order).
        ->and(Ticketing::tickets()->filter(['sort' => 'title; drop table'])->count())->toBe(3);

    expect(fn () => Ticketing::tickets()->sort('title'))->toThrow(InvalidArgumentException::class);
});

it('paginates with offset or cursor and clamps the page size', function (): void {
    foreach (range(1, 5) as $i) {
        Ticketing::open(type: 'support', title: "t{$i}");
    }

    $page = Ticketing::tickets()->paginate(2);
    $cursor = Ticketing::tickets()->cursorPaginate(2);

    expect($page->total())->toBe(5)
        ->and($page->items())->toHaveCount(2)
        ->and($cursor->items())->toHaveCount(2)
        ->and($cursor->hasMorePages())->toBeTrue()
        ->and(Ticketing::tickets()->paginate(1000)->perPage())->toBe(100)
        ->and(Ticketing::tickets()->paginate(0)->perPage())->toBe(1);
});

it('stays inside the current tenant', function (): void {
    $context = app(TenantContext::class);

    $context->forTenant(1, fn () => Ticketing::open(type: 'support', title: 'tenant one'));
    $context->forTenant(2, fn () => Ticketing::open(type: 'support', title: 'tenant two'));

    expect($context->forTenant(1, fn () => titles(TicketQuery::make()->get())))->toBe(['tenant one'])
        ->and($context->forTenant(2, fn () => titles(Ticketing::tickets()->search('tenant')->get())))->toBe(['tenant two']);
});

it('lets a host add its own constraint', function (): void {
    Ticketing::open(type: 'support', title: 'keep', attributes: ['custom_fields' => ['vip' => true]]);
    Ticketing::open(type: 'support', title: 'drop');

    $tickets = Ticketing::tickets()->where(fn ($query) => $query->where('title', 'keep'))->get();

    expect(titles($tickets))->toBe(['keep']);
});

it('filters the REST listing with the shared query semantics', function (): void {
    $agent = makeUser(['name' => 'Agent']);
    $mine = Ticketing::open(type: 'support', title: 'Printer jam', priority: Priority::High);
    Ticketing::assign($mine, assignee: $agent);
    Ticketing::open(type: 'support', title: 'Printer toner', priority: Priority::Low);
    Ticketing::open(type: 'incident', title: 'Network down');

    $this->actingAs($agent);

    $this->getJson(API.'/tickets?q=printer&sort=priority')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.title', 'Printer toner')
        ->assertJsonPath('data.0.channel', 'web');

    $this->getJson(API.'/tickets?assignee=me&state=open')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Printer jam');

    $this->getJson(API.'/tickets?type=incident')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('refuses cursor pagination on a nullable sort column', function (): void {
    Ticketing::open(type: 'support', title: 'no due date');

    expect(fn () => Ticketing::tickets()->sort('due_at')->cursorPaginate(10))
        ->toThrow(InvalidArgumentException::class, 'nullable [due_at]');

    expect(Ticketing::tickets()->sort('due_at')->paginate(10)->total())->toBe(1);
});

it('resolves state semantics for workflows only a custom driver knows', function (): void {
    $config = new ConfigWorkflowDriver;

    $custom = new class($config) extends ConfigWorkflowDriver
    {
        public function __construct(private ConfigWorkflowDriver $fallback) {}

        public function statesForSemantic(string $workflow, string $semantic): array
        {
            if ($workflow === 'database-backed') {
                return $semantic === 'paused' ? ['on_hold'] : [];
            }

            return $this->fallback->statesForSemantic($workflow, $semantic);
        }
    };

    app(WorkflowManager::class)->extend('custom', fn () => $custom);
    config()->set('ticketing.workflow.driver', 'custom');

    $type = TicketType::factory()->create(['key' => 'claims', 'workflow' => 'database-backed']);
    Ticket::factory()->create(['ticket_type_id' => $type->getKey(), 'status' => 'on_hold', 'title' => 'waiting on insurer']);
    Ticketing::open(type: 'support', title: 'regular open');

    // A type pointing at a workflow nobody defines is skipped, not fatal.
    $orphan = TicketType::factory()->create(['key' => 'orphan', 'workflow' => 'gone']);
    Ticket::factory()->create(['ticket_type_id' => $orphan->getKey(), 'status' => 'on_hold', 'title' => 'orphan']);

    expect(titles(Ticketing::tickets()->state('paused')->get()))->toBe(['waiting on insurer'])
        ->and(titles(Ticketing::tickets()->state('open')->get()))->toBe(['regular open']);
});
