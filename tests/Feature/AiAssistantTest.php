<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Selli\Ticketing\Ai\AiAnswer;
use Selli\Ticketing\Ai\AiAssistant;
use Selli\Ticketing\Ai\AiClassification;
use Selli\Ticketing\Ai\AiManager;
use Selli\Ticketing\Ai\AiRequest;
use Selli\Ticketing\Ai\AiSource;
use Selli\Ticketing\Ai\AiSummary;
use Selli\Ticketing\Ai\AiUsage;
use Selli\Ticketing\Ai\Drivers\FakeAiDriver;
use Selli\Ticketing\Contracts\AiDriver;
use Selli\Ticketing\Data\ArticleData;
use Selli\Ticketing\Enums\AiMode;
use Selli\Ticketing\Enums\AiOutcome;
use Selli\Ticketing\Enums\AiStatus;
use Selli\Ticketing\Enums\EscalationReason;
use Selli\Ticketing\Enums\KnowledgeVisibility;
use Selli\Ticketing\Enums\MessageSource;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Enums\Sentiment;
use Selli\Ticketing\Events\AiDraftCreated;
use Selli\Ticketing\Events\AiReplied;
use Selli\Ticketing\Events\AiTurnFailed;
use Selli\Ticketing\Events\AiUsageRecorded;
use Selli\Ticketing\Events\HumanTookOver;
use Selli\Ticketing\Events\TicketEscalated;
use Selli\Ticketing\Exceptions\CrossTenantException;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Knowledge\KnowledgeAudience;
use Selli\Ticketing\Models\AiTurn;
use Selli\Ticketing\Models\AutomationRule;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Models\TeamMember;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Notifications\TicketEscalatedNotification;
use Selli\Ticketing\Tenancy\TenantContext;

function fakeAi(): FakeAiDriver
{
    $driver = app(AiManager::class)->driver('fake');
    assert($driver instanceof FakeAiDriver);

    return $driver;
}

function confidentAnswer(string $body = 'Open Settings > Security > Reset.', float $confidence = 0.92): AiAnswer
{
    return new AiAnswer(
        body: $body,
        sources: [new AiSource('kb_article', '1', 'Reset your password', 0.9)],
        confidence: $confidence,
        usage: new AiUsage('fake-model', 120, 30, 0.0012, 'EUR'),
    );
}

/**
 * A customer ticket with its first message posted (the AI turn runs inline).
 */
function customerAsks(string $question, string $type = 'support'): Ticket
{
    $customer = Ticketing::contacts()->remember('customer@example.com', 'Customer');
    $ticket = Ticketing::open(type: $type, title: 'Help needed', requester: $customer);
    Ticketing::postMessage($ticket, $customer, $question);

    return $ticket->refresh();
}

beforeEach(function (): void {
    config()->set('ticketing.ai.mode', 'auto');
    config()->set('ticketing.ai.escalation.handoff_message', 'A human will follow up.');
});

it('answers a customer with sources and confidence', function (): void {
    Event::fake([AiReplied::class, AiUsageRecorded::class]);
    fakeAi()->queueAnswer(confidentAnswer());

    $ticket = customerAsks('How do I reset my password?');
    $reply = $ticket->messages()->latest('id')->first();
    $turn = AiTurn::query()->sole();

    expect($reply?->source)->toBe(MessageSource::Ai)
        ->and($reply?->visibility)->toBe(MessageVisibility::Public)
        ->and($reply?->body)->toBe('Open Settings > Security > Reset.')
        ->and($reply?->meta['ai']['sources'][0]['title'])->toBe('Reset your password')
        ->and($reply?->meta['ai']['confidence'])->toBe(0.92)
        ->and($ticket->ai_status)->toBe(AiStatus::Active)
        ->and($ticket->ai_exchanges)->toBe(1)
        ->and($ticket->first_ai_response_at)->not->toBeNull()
        ->and($ticket->first_response_at)->toBeNull()
        ->and($turn->outcome)->toBe(AiOutcome::Answered)
        ->and($turn->mode)->toBe(AiMode::Auto)
        ->and($turn->confidence)->toBe(0.92)
        ->and($turn->sources_count)->toBe(1)
        ->and($turn->input_tokens)->toBeGreaterThanOrEqual(120)
        ->and($turn->cost)->toBe(0.0012)
        ->and($turn->model)->toBe('fake-model')
        ->and($turn->question)->toBe('How do I reset my password?')
        ->and($turn->question_fingerprint)->toBe(AiAssistant::fingerprint('how do I reset my password'));

    Event::assertDispatched(AiReplied::class);
    Event::assertDispatched(AiUsageRecorded::class);
});

it('answers from the knowledge base with the fake driver', function (): void {
    config()->set('ticketing.ai.escalation.min_confidence', 0.05);
    $kb = Ticketing::knowledge();
    $kb->publish($kb->save(new ArticleData(
        title: 'Reset your password',
        body: 'To reset your password open Settings, then Security, then Reset.',
        summary: 'Settings > Security > Reset.',
        visibility: KnowledgeVisibility::Customer,
    )));
    $kb->publish($kb->save(new ArticleData(
        title: 'Internal password runbook',
        body: 'Reset password via the admin console.',
        visibility: KnowledgeVisibility::Internal,
    )));

    $ticket = customerAsks('I need to reset my password');
    $reply = $ticket->messages()->where('source', MessageSource::Ai->value)->sole();

    // The internal runbook is never a source for a customer answer.
    expect($reply->body)->toBe('Settings > Security > Reset.')
        ->and(array_column($reply->meta['ai']['sources'], 'title'))->toBe(['Reset your password']);
});

it('escalates when nothing in the knowledge base answers', function (): void {
    Event::fake([TicketEscalated::class]);

    $ticket = customerAsks('Something nobody documented');

    expect($ticket->ai_status)->toBe(AiStatus::Escalated)
        ->and($ticket->escalation_reason)->toBe(EscalationReason::NoSources)
        ->and($ticket->escalated_at)->not->toBeNull()
        ->and(AiTurn::query()->sole()->outcome)->toBe(AiOutcome::Escalated)
        ->and($ticket->messages()->where('body', 'A human will follow up.')->exists())->toBeTrue()
        ->and($ticket->messages()->where('visibility', MessageVisibility::Internal->value)->where('meta->ai->kind', 'summary')->exists())->toBeTrue()
        ->and($ticket->activities()->where('event', 'ticket.escalated')->exists())->toBeTrue();

    Event::assertDispatched(TicketEscalated::class, fn (TicketEscalated $event): bool => $event->reason === EscalationReason::NoSources);
});

it('routes an escalated ticket to the escalation team and notifies its leads', function (): void {
    Notification::fake();
    $team = Team::factory()->create(['key' => 'support-desk']);
    $lead = makeUser(['name' => 'Lead', 'email' => 'lead@example.test']);
    $member = makeUser(['name' => 'Member', 'email' => 'member@example.test']);
    TeamMember::factory()->member($lead)->create(['team_id' => $team->getKey(), 'role' => TeamMember::ROLE_LEAD]);
    TeamMember::factory()->member($member)->create(['team_id' => $team->getKey()]);
    config()->set('ticketing.ai.escalation.team', 'support-desk');
    config()->set('ticketing.notifications.default_channels', ['database']);

    $ticket = customerAsks('Nobody documented this');

    expect($ticket->team_id)->toBe($team->getKey());

    Notification::assertSentTo($lead, TicketEscalatedNotification::class, fn (TicketEscalatedNotification $n): bool => $n->reason === EscalationReason::NoSources
        && $n->key() === 'ticket.escalated'
        && str_contains($n->body(), 'no sources')
        && $n->toArray($lead)['reason'] === 'no_sources');
    Notification::assertNotSentTo($member, TicketEscalatedNotification::class);
});

it('hands over at once when the customer asks for a person', function (): void {
    fakeAi()->queueClassification(new AiClassification(wantsHuman: true));

    $ticket = customerAsks('Can I talk to a human please?');

    expect($ticket->escalation_reason)->toBe(EscalationReason::HumanRequested)
        ->and(collect(fakeAi()->requests())->pluck('operation')->all())->not->toContain('answer');
});

it('escalates sensitive topics, negative sentiment and low confidence', function (AiClassification $classification, ?AiAnswer $answer, EscalationReason $expected, bool $negativeRule): void {
    config()->set('ticketing.ai.escalation.on_negative_sentiment', $negativeRule);
    fakeAi()->queueClassification($classification);

    if ($answer !== null) {
        fakeAi()->queueAnswer($answer);
    }

    expect(customerAsks('A question')->escalation_reason)->toBe($expected);
})->with([
    'sensitive topic' => [new AiClassification(topics: ['Billing']), null, EscalationReason::SensitiveTopic, false],
    'negative sentiment' => [new AiClassification(sentiment: Sentiment::Negative), null, EscalationReason::NegativeSentiment, true],
    'low confidence' => [AiClassification::none(), confidentAnswer(confidence: 0.4), EscalationReason::LowConfidence, false],
    'answer wants a human' => [AiClassification::none(), new AiAnswer('x', [new AiSource('faq', '1', 'F')], 0.99, wantsHuman: true), EscalationReason::HumanRequested, false],
    'no sources' => [AiClassification::none(), new AiAnswer('An unsourced guess', [], 0.99), EscalationReason::NoSources, false],
]);

it('escalates after too many AI exchanges', function (): void {
    config()->set('ticketing.ai.escalation.max_exchanges', 2);
    fakeAi()->queueAnswer(confidentAnswer('First'))->queueAnswer(confidentAnswer('Second'));

    $ticket = customerAsks('Question one');
    $customer = $ticket->participants()->sole()->participant;
    Ticketing::postMessage($ticket, $customer, 'Question two');
    Ticketing::postMessage($ticket, $customer, 'Question three');

    $ticket->refresh();

    expect($ticket->ai_exchanges)->toBe(2)
        ->and($ticket->escalation_reason)->toBe(EscalationReason::TooManyExchanges);
});

it('writes an internal draft in draft mode', function (): void {
    Event::fake([AiDraftCreated::class, AiReplied::class]);
    config()->set('ticketing.ai.mode', 'draft');
    fakeAi()->queueAnswer(confidentAnswer('Suggested reply'));

    $ticket = customerAsks('How do I reset my password?');
    $draft = $ticket->messages()->where('source', MessageSource::Ai->value)->sole();

    expect($draft->visibility)->toBe(MessageVisibility::Internal)
        ->and($draft->meta['ai']['kind'])->toBe('draft')
        ->and($ticket->ai_exchanges)->toBe(0)
        ->and(AiTurn::query()->sole()->outcome)->toBe(AiOutcome::Drafted);

    Event::assertDispatched(AiDraftCreated::class);
    Event::assertNotDispatched(AiReplied::class);
});

it('still drafts but escalates silently when a rule fires in draft mode', function (): void {
    config()->set('ticketing.ai.mode', 'draft');
    fakeAi()->queueClassification(new AiClassification(topics: ['privacy']))->queueAnswer(confidentAnswer('Draft about GDPR'));

    $ticket = customerAsks('Delete my data');

    expect($ticket->escalation_reason)->toBe(EscalationReason::SensitiveTopic)
        ->and($ticket->messages()->where('meta->ai->kind', 'draft')->exists())->toBeTrue()
        // In draft mode the customer is not told about a hand-over.
        ->and($ticket->messages()->where('body', 'A human will follow up.')->exists())->toBeFalse();
});

it('does nothing when the AI is off, for the type or the tenant', function (): void {
    config()->set('ticketing.ai.mode', 'off');
    expect(customerAsks('Anyone?')->ai_status)->toBeNull();

    config()->set('ticketing.ai.mode', 'auto');
    config()->set('ticketing.ai.modes.types', ['incident' => 'off']);
    expect(customerAsks('Outage!', 'incident')->ai_status)->toBeNull();

    config()->set('ticketing.ai.modes.tenants', [7 => 'off']);
    $ticket = app(TenantContext::class)->forTenant(7, fn () => customerAsks('Tenant seven'));
    expect($ticket->ai_status)->toBeNull()
        ->and(AiTurn::query()->withoutTenancy()->count())->toBe(0);
});

it('classifies the first message: language, sentiment, category and a higher priority', function (): void {
    fakeAi()->queueClassification(new AiClassification(category: 'billing-question', priority: Priority::Urgent, language: 'it', sentiment: Sentiment::Neutral))
        ->queueAnswer(confidentAnswer());

    $ticket = customerAsks('Ho un problema');

    expect($ticket->language)->toBe('it')
        ->and($ticket->sentiment)->toBe(Sentiment::Neutral)
        ->and($ticket->category)->toBe('billing-question')
        ->and($ticket->priority)->toBe(Priority::Urgent);
});

it('never lowers the priority and never sends internal notes to the AI', function (): void {
    $customer = Ticketing::contacts()->remember('customer@example.com');
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: $customer, priority: Priority::High);
    Ticketing::postMessage($ticket, makeUser(), 'Internal: customer is a VIP', MessageVisibility::Internal);

    fakeAi()->queueClassification(new AiClassification(priority: Priority::Low))->queueAnswer(confidentAnswer());
    Ticketing::postMessage($ticket, $customer, 'Question');

    $answerRequest = collect(fakeAi()->requests())->firstWhere('operation', 'answer')['request'];
    assert($answerRequest instanceof AiRequest);

    expect($ticket->refresh()->priority)->toBe(Priority::High)
        ->and(collect($answerRequest->history)->pluck('body')->all())->not->toContain('Internal: customer is a VIP')
        ->and($answerRequest->question)->toBe('Question')
        ->and($answerRequest->ticketType)->toBe('support');
});

it('stops for good when an agent replies (human takeover)', function (): void {
    Event::fake([HumanTookOver::class]);
    fakeAi()->queueAnswer(confidentAnswer());

    $ticket = customerAsks('First question');
    $agent = makeUser(['name' => 'Agent']);
    Ticketing::postMessage($ticket, $agent, 'I am taking this one.');

    $customer = $ticket->participants()->where('role', 'requester')->sole()->participant;
    Ticketing::postMessage($ticket, $customer, 'Thanks!');

    expect($ticket->refresh()->ai_status)->toBe(AiStatus::Suspended)
        ->and(AiTurn::query()->count())->toBe(1);

    Event::assertDispatched(HumanTookOver::class, fn (HumanTookOver $event): bool => $event->previous === AiStatus::Active);
});

it('lets an agent take over explicitly and assigns an unowned ticket', function (): void {
    fakeAi()->queueAnswer(confidentAnswer());
    $ticket = customerAsks('Question');
    $agent = makeUser(['name' => 'Agent']);

    $taken = Ticketing::takeOver($ticket, $agent);

    expect($taken->ai_status)->toBe(AiStatus::Suspended)
        ->and((string) $taken->assignee_id)->toBe((string) $agent->getKey())
        ->and($taken->activities()->where('event', 'ticket.taken_over')->exists())->toBeTrue();

    // A cross-tenant agent is refused.
    $stranger = makeUser(['name' => 'Stranger', 'tenant_id' => 99]);
    $scoped = app(TenantContext::class)->forTenant(1, fn () => customerAsks('Tenant one'));
    expect(fn () => Ticketing::takeOver($scoped, $stranger))->toThrow(CrossTenantException::class);
});

it('never writes after a takeover that happens while the driver is thinking', function (): void {
    $agent = makeUser(['name' => 'Agent']);

    app(AiManager::class)->extend('slow', fn () => new class($agent) implements AiDriver
    {
        public function __construct(private $agent) {}

        public function classify(AiRequest $request): AiClassification
        {
            return AiClassification::none();
        }

        public function answer(AiRequest $request): AiAnswer
        {
            // An agent grabs the ticket mid-turn.
            Ticketing::takeOver($request->ticket, $this->agent, assign: false);

            return new AiAnswer('Late answer', [new AiSource('faq', '1', 'F')], 0.99);
        }

        public function summarize(AiRequest $request): AiSummary
        {
            return new AiSummary('');
        }
    });
    config()->set('ticketing.ai.driver', 'slow');

    // The ticket must be AI-handled for the takeover to suspend it.
    $customer = Ticketing::contacts()->remember('customer@example.com');
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: $customer);
    $ticket->forceFill(['ai_status' => AiStatus::Active])->save();
    Ticketing::postMessage($ticket, $customer, 'Question');

    expect($ticket->messages()->where('body', 'Late answer')->exists())->toBeFalse()
        ->and(AiTurn::query()->sole()->outcome)->toBe(AiOutcome::Skipped)
        ->and($ticket->refresh()->ai_status)->toBe(AiStatus::Suspended);
});

it('escalates on a provider error', function (): void {
    Event::fake([AiTurnFailed::class, TicketEscalated::class]);
    fakeAi()->failNext('timeout');

    $ticket = customerAsks('Question');
    $turn = AiTurn::query()->sole();

    expect($turn->outcome)->toBe(AiOutcome::Failed)
        ->and($turn->error)->toBe('timeout')
        ->and($ticket->escalation_reason)->toBe(EscalationReason::AiError);

    Event::assertDispatched(AiTurnFailed::class);
    Event::assertDispatched(TicketEscalated::class);
});

it('runs one turn per message, however often it is triggered', function (): void {
    fakeAi()->queueAnswer(confidentAnswer())->queueAnswer(confidentAnswer('Twice?'));

    $ticket = customerAsks('Question');
    $message = $ticket->messages()->oldest('id')->first();

    expect(app(AiAssistant::class)->respondToTicket($ticket, $message))->toBeNull()
        ->and(AiTurn::query()->count())->toBe(1)
        ->and($ticket->messages()->where('body', 'Twice?')->exists())->toBeFalse();
});

it('counts a ticket resolved while the AI handled it as resolved by the AI', function (): void {
    fakeAi()->queueAnswer(confidentAnswer());
    $ticket = customerAsks('Question');

    Ticketing::transition($ticket, 'resolve');

    expect($ticket->refresh()->ai_status)->toBe(AiStatus::Resolved);
});

it('does not escalate twice nor override a takeover', function (): void {
    Event::fake([TicketEscalated::class]);
    $ticket = Ticketing::open(type: 'support', title: 'Manual');

    Ticketing::escalate($ticket);
    Ticketing::escalate($ticket);
    Ticketing::takeOver($ticket, makeUser(), assign: false);
    Ticketing::escalate($ticket);

    expect($ticket->refresh()->ai_status)->toBe(AiStatus::Suspended)
        ->and($ticket->escalation_reason)->toBe(EscalationReason::Manual);

    Event::assertDispatchedTimes(TicketEscalated::class, 1);
});

it('fires automation rules on escalation', function (): void {
    AutomationRule::factory()->create([
        'event' => 'ticket.escalated',
        'conditions' => [['field' => 'escalation_reason', 'operator' => '=', 'value' => 'manual']],
        'actions' => [['type' => 'tag', 'tags' => ['escalated']]],
    ]);

    $ticket = Ticketing::open(type: 'support', title: 'Rule');
    Ticketing::escalate($ticket);

    expect($ticket->tags()->pluck('slug')->all())->toBe(['escalated']);
});

it('resolves custom drivers and refuses unknown ones', function (): void {
    expect(Ticketing::ai()->driver('null')->answer(new AiRequest('q', KnowledgeAudience::public()))->isEmpty())->toBeTrue()
        ->and(fn () => Ticketing::ai()->driver('nope'))->toThrow(InvalidConfigurationException::class);

    Ticketing::ai()->forgetDrivers();
    config()->set('ticketing.ai.driver', '');
    expect(Ticketing::ai()->defaultDriver())->toBe('null');
});

it('hands over when the customer asks for a person later in the conversation', function (): void {
    fakeAi()->queueAnswer(confidentAnswer());
    $ticket = customerAsks('First question');

    fakeAi()->queueClassification(new AiClassification(wantsHuman: true));
    Ticketing::postMessage($ticket, $ticket->participants()->where('role', 'requester')->sole()->participant, 'Now I want a person');

    expect($ticket->refresh()->escalation_reason)->toBe(EscalationReason::HumanRequested)
        ->and($ticket->ai_exchanges)->toBe(1);
});
