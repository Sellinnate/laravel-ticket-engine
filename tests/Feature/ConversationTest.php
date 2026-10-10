<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Selli\Ticketing\Ai\AiAnswer;
use Selli\Ticketing\Ai\AiAssistant;
use Selli\Ticketing\Ai\AiClassification;
use Selli\Ticketing\Ai\AiManager;
use Selli\Ticketing\Ai\AiRequest;
use Selli\Ticketing\Ai\AiSource;
use Selli\Ticketing\Ai\AiSummary;
use Selli\Ticketing\Ai\Drivers\FakeAiDriver;
use Selli\Ticketing\Contracts\AiDriver;
use Selli\Ticketing\Contracts\VisitorResolver;
use Selli\Ticketing\Conversations\VisitorIdentity;
use Selli\Ticketing\Conversations\WidgetKeys;
use Selli\Ticketing\Data\ArticleData;
use Selli\Ticketing\Enums\AiOutcome;
use Selli\Ticketing\Enums\AiStatus;
use Selli\Ticketing\Enums\ConversationRole;
use Selli\Ticketing\Enums\ConversationStatus;
use Selli\Ticketing\Enums\EscalationReason;
use Selli\Ticketing\Enums\KnowledgeVisibility;
use Selli\Ticketing\Enums\MessageSource;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Events\ConversationClosed;
use Selli\Ticketing\Events\ConversationEscalated;
use Selli\Ticketing\Events\ConversationStarted;
use Selli\Ticketing\Exceptions\ConversationException;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\Contact;
use Selli\Ticketing\Models\Conversation;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Models\WidgetKey;
use Selli\Ticketing\Notifications\ReplyPostedNotification;
use Selli\Ticketing\Tenancy\TenantContext;

function widgetKey(array $options = []): array
{
    return app(TenantContext::class)->forTenant($options['tenant'] ?? null, fn () => Ticketing::widgetKeys()->create(
        name: 'Acme site',
        allowedOrigins: $options['origins'] ?? ['https://acme.example'],
        audience: $options['audience'] ?? null,
        ticketType: $options['type'] ?? null,
        settings: $options['settings'] ?? ['greeting' => 'Hi!'],
    ));
}

function chatAi(): FakeAiDriver
{
    $driver = app(AiManager::class)->driver('fake');
    assert($driver instanceof FakeAiDriver);

    return $driver;
}

function sure(string $body = 'Here is how.'): AiAnswer
{
    return new AiAnswer($body, [new AiSource('kb_article', '1', 'How-to', 0.9, 'https://help.example/how-to')], 0.95);
}

beforeEach(function (): void {
    config()->set('ticketing.ai.mode', 'auto');
    config()->set('ticketing.ai.escalation.handoff_message', 'A human will follow up.');
});

it('issues widget keys with a secret shown once, and revokes and rotates them', function (): void {
    ['key' => $key, 'secret' => $secret] = widgetKey();

    expect($key->public_key)->toStartWith('tk_pub_')
        ->and($secret)->toStartWith('tk_sec_')
        ->and($key->toArray())->not->toHaveKey('secret')
        ->and(Ticketing::widgetKeys()->find($key->public_key)?->getKey())->toBe($key->getKey())
        ->and(Ticketing::widgetKeys()->find('nope'))->toBeNull()
        ->and($key->setting('greeting'))->toBe('Hi!');

    $rotated = Ticketing::widgetKeys()->rotateSecret($key);
    expect($rotated)->not->toBe($secret)->and($key->refresh()->secret)->toBe($rotated);

    Ticketing::widgetKeys()->revoke($key);
    expect(Ticketing::widgetKeys()->find($key->public_key))->toBeNull()
        ->and($key->refresh()->isUsable())->toBeFalse();
});

it('normalises and checks origins, failing closed on an empty allow-list', function (): void {
    ['key' => $key] = widgetKey(['origins' => ['https://Acme.example:443', 'http://localhost:5173']]);

    expect($key->allowsOrigin('https://acme.example'))->toBeTrue()
        ->and($key->allowsOrigin('https://ACME.example/'))->toBeTrue()
        ->and($key->allowsOrigin('http://localhost:5173'))->toBeTrue()
        ->and($key->allowsOrigin('http://localhost:3000'))->toBeFalse()
        ->and($key->allowsOrigin('https://evil.example'))->toBeFalse()
        ->and($key->allowsOrigin('javascript:alert(1)'))->toBeFalse()
        ->and(WidgetKey::normaliseOrigin('ftp://x'))->toBeNull();

    ['key' => $closed] = widgetKey(['origins' => []]);
    ['key' => $open] = widgetKey(['origins' => ['*']]);

    expect($closed->allowsOrigin('https://acme.example'))->toBeFalse()
        ->and($open->allowsOrigin('https://anything.example'))->toBeTrue();
});

it('verifies signed identities in constant time and trusts nothing unsigned', function (): void {
    ['key' => $key, 'secret' => $secret] = widgetKey();
    $keys = app(WidgetKeys::class);

    $signedEmail = new VisitorIdentity(email: 'Jane@Example.com', signature: WidgetKeys::sign($secret, 'jane@example.com'));
    $signedUser = new VisitorIdentity(email: 'x@example.com', userId: '42', signature: strtoupper(WidgetKeys::sign($secret, '42')));
    $forged = new VisitorIdentity(email: 'jane@example.com', signature: WidgetKeys::sign('wrong', 'jane@example.com'));

    expect($keys->verifyIdentity($key, $signedEmail))->toBeTrue()
        ->and($keys->verifyIdentity($key, $signedUser))->toBeTrue()
        ->and($keys->verifyIdentity($key, $forged))->toBeFalse()
        ->and($keys->verifyIdentity($key, new VisitorIdentity(email: 'jane@example.com')))->toBeFalse()
        ->and(VisitorIdentity::fromArray(['email' => ' a@b.c ', 'user_id' => 7, 'name' => ''])->userId)->toBe('7');
});

it('starts a conversation in the key\'s tenant with a hashed token', function (): void {
    Event::fake([ConversationStarted::class]);
    ['key' => $key] = widgetKey(['tenant' => 5]);

    ['conversation' => $conversation, 'token' => $token] = Ticketing::conversations()->start($key, null, ['ip' => '203.0.113.9', 'origin' => 'https://acme.example', 'locale' => 'it']);

    expect($conversation->tenant_id)->toBe(5)
        ->and($conversation->status)->toBe(ConversationStatus::Open)
        ->and($conversation->public_id)->toHaveLength(26)
        ->and($conversation->token_hash)->toBe(hash('sha256', $token))
        ->and($conversation->ip_hash)->not->toContain('203.0.113.9')
        ->and($conversation->locale)->toBe('it')
        ->and($conversation->identity_verified)->toBeFalse()
        ->and($conversation->toArray())->not->toHaveKeys(['token_hash', 'ip_hash'])
        ->and(Ticketing::conversations()->authenticate($conversation->public_id, $token)?->getKey())->toBe($conversation->getKey())
        ->and(Ticketing::conversations()->authenticate($conversation->public_id, 'wrong'))->toBeNull()
        ->and(Ticketing::conversations()->authenticate('missing', $token))->toBeNull();

    Event::assertDispatched(ConversationStarted::class);
});

it('expires conversation tokens and binds them to their key', function (): void {
    ['key' => $key] = widgetKey();
    ['key' => $other] = widgetKey();
    ['conversation' => $conversation, 'token' => $token] = Ticketing::conversations()->start($key);

    expect(Ticketing::conversations()->authenticate($conversation->public_id, $token, $other))->toBeNull();

    Carbon::setTestNow(now()->addDays(8));
    expect(Ticketing::conversations()->authenticate($conversation->public_id, $token))->toBeNull();

    config()->set('ticketing.widget.token_ttl', 0);
    expect(Ticketing::conversations()->authenticate($conversation->public_id, $token))->not->toBeNull();
    Carbon::setTestNow();
});

it('links a verified visitor to a verified contact, or to a host user via the resolver', function (): void {
    ['key' => $key, 'secret' => $secret] = widgetKey();

    ['conversation' => $byContact] = Ticketing::conversations()->start($key, new VisitorIdentity(
        email: 'jane@example.com', name: 'Jane', signature: WidgetKeys::sign($secret, 'jane@example.com'),
    ));

    $contact = Contact::query()->sole();
    expect($byContact->identity_verified)->toBeTrue()
        ->and($byContact->contact_id)->toBe($contact->getKey())
        ->and($contact->isVerified())->toBeTrue();

    $user = makeUser(['name' => 'Host user']);
    app()->bind(VisitorResolver::class, fn () => new class($user) implements VisitorResolver
    {
        public function __construct(private $user) {}

        public function resolve(VisitorIdentity $identity, WidgetKey $key): ?Model
        {
            return $identity->userId === '42' ? $this->user : null;
        }
    });

    ['conversation' => $byUser] = Ticketing::conversations()->start($key, new VisitorIdentity(userId: '42', signature: WidgetKeys::sign($secret, '42')));

    expect($byUser->requester_type)->toBe($user->getMorphClass())
        ->and($byUser->requester_id)->toBe($user->getKey());

    // An unsigned claim is remembered, never trusted.
    ['conversation' => $claimed] = Ticketing::conversations()->start($key, new VisitorIdentity(email: 'ceo@acme.example', name: 'CEO'));
    expect($claimed->identity_verified)->toBeFalse()
        ->and($claimed->contact_id)->toBeNull()
        ->and($claimed->meta['claimed']['email'])->toBe('ceo@acme.example');
});

it('lets the AI answer while the conversation is open', function (): void {
    ['key' => $key] = widgetKey();
    ['conversation' => $conversation] = Ticketing::conversations()->start($key);
    chatAi()->queueAnswer(sure('Use the reset link.'));

    $message = Ticketing::conversations()->post($conversation, '  How do I reset my password?  ');
    $conversation->refresh();
    $timeline = Ticketing::conversations()->timeline($conversation);

    expect($message->body)->toBe('How do I reset my password?')
        ->and($conversation->ai_exchanges)->toBe(1)
        ->and($conversation->message_count)->toBe(2)
        ->and(array_column($timeline, 'role'))->toBe(['visitor', 'assistant'])
        ->and($timeline[1]['body'])->toBe('Use the reset link.')
        ->and($timeline[1]['sources'])->toBe([['type' => 'kb_article', 'title' => 'How-to', 'url' => 'https://help.example/how-to']])
        ->and(Ticket::query()->count())->toBe(0);
});

it('turns the conversation into a ticket with its transcript when a human is needed', function (): void {
    Event::fake([ConversationEscalated::class]);
    ['key' => $key, 'secret' => $secret] = widgetKey(['type' => 'support']);
    ['conversation' => $conversation] = Ticketing::conversations()->start($key, new VisitorIdentity(
        email: 'jane@example.com', name: 'Jane', signature: WidgetKeys::sign($secret, 'jane@example.com'),
    ));

    chatAi()->queueAnswer(sure('First answer'));
    Ticketing::conversations()->post($conversation, 'My order is late');

    chatAi()->queueClassification(new AiClassification(wantsHuman: true));
    Ticketing::conversations()->post($conversation, 'I want to talk to a person');

    $conversation->refresh();
    $ticket = Ticket::query()->sole();
    $contact = Contact::query()->sole();

    expect($conversation->status)->toBe(ConversationStatus::Escalated)
        ->and($conversation->ticket_id)->toBe($ticket->getKey())
        ->and($conversation->escalation_reason)->toBe(EscalationReason::HumanRequested)
        ->and($ticket->channel)->toBe('widget')
        ->and($ticket->title)->toBe('My order is late')
        ->and($ticket->ai_status)->toBe(AiStatus::Escalated)
        ->and($ticket->escalation_reason)->toBe(EscalationReason::HumanRequested)
        ->and($ticket->participants()->where('role', 'requester')->sole()->participant_id)->toBe((string) $contact->getKey())
        ->and($ticket->custom_fields['conversation'])->toBe($conversation->public_id);

    $transcript = $ticket->messages()->where('meta->transcript', true)->orderBy('id')->get();
    expect($transcript->pluck('body')->all())->toBe(['My order is late', 'First answer', 'I want to talk to a person'])
        ->and($transcript[0]->source)->toBe(MessageSource::Widget)
        ->and($transcript[1]->source)->toBe(MessageSource::Ai);

    Event::assertDispatched(ConversationEscalated::class);
});

it('does not notify anyone about the copied transcript', function (): void {
    Notification::fake();
    config()->set('ticketing.notifications.default_channels', ['mail']);
    config()->set('ticketing.ai.escalation.handoff_message', null);
    ['key' => $key] = widgetKey();
    ['conversation' => $conversation] = Ticketing::conversations()->start($key);

    chatAi()->queueAnswer(sure());
    Ticketing::conversations()->post($conversation, 'Question');

    Ticketing::conversations()->escalate($conversation, null, 'Jane', 'jane@example.com');

    Notification::assertNotSentTo(Contact::query()->sole(), ReplyPostedNotification::class);
});

it('parks an AI hand-over until an anonymous visitor leaves an email', function (): void {
    ['key' => $key] = widgetKey();
    ['conversation' => $conversation] = Ticketing::conversations()->start($key);

    // Nothing in the knowledge base: the AI hands over, but nobody to notify.
    Ticketing::conversations()->post($conversation, 'Nobody documented this');
    $conversation->refresh();

    expect($conversation->status)->toBe(ConversationStatus::Open)
        ->and($conversation->meta['handoff_pending'])->toBeTrue()
        ->and($conversation->escalation_reason)->toBe(EscalationReason::NoSources)
        ->and(collect(Ticketing::conversations()->timeline($conversation))->last()['role'])->toBe('system')
        ->and(Ticket::query()->count())->toBe(0);

    // While the hand-over waits, the AI stays quiet.
    Ticketing::conversations()->post($conversation, 'Hello?');
    expect($conversation->refresh()->ai_exchanges)->toBe(0);

    $ticket = Ticketing::conversations()->escalate($conversation, EscalationReason::HumanRequested, 'Jane', 'jane@example.com');

    expect($ticket)->toBeInstanceOf(Ticket::class)
        ->and($conversation->refresh()->status)->toBe(ConversationStatus::Escalated)
        ->and($conversation->meta)->toBeNull()
        ->and(Contact::query()->sole()->email)->toBe('jane@example.com')
        // Escalating again returns the same ticket.
        ->and(Ticketing::conversations()->escalate($conversation)?->getKey())->toBe($ticket?->getKey());
});

it('uses the claimed email for the ticket when the visitor asks for a person', function (): void {
    ['key' => $key] = widgetKey();
    ['conversation' => $conversation] = Ticketing::conversations()->start($key, new VisitorIdentity(email: 'claimed@example.com', name: 'Claimer'));

    $ticket = Ticketing::conversations()->escalate($conversation, EscalationReason::HumanRequested);

    expect($ticket)->not->toBeNull()
        ->and(Contact::query()->sole()->isVerified())->toBeFalse();
});

it('opens a ticket from the first message when the AI is off, without email if allowed', function (): void {
    config()->set('ticketing.ai.mode', 'off');
    config()->set('ticketing.widget.require_email_to_escalate', false);
    ['key' => $key] = widgetKey();
    ['conversation' => $conversation] = Ticketing::conversations()->start($key);

    Ticketing::conversations()->post($conversation, 'Hello team');

    $ticket = Ticket::query()->sole();
    expect($conversation->refresh()->status)->toBe(ConversationStatus::Escalated)
        ->and($ticket->ai_status)->toBeNull()
        ->and($conversation->escalation_reason)->toBeNull();
});

it('drafts on the new ticket in draft mode', function (): void {
    config()->set('ticketing.ai.mode', 'draft');
    ['key' => $key, 'secret' => $secret] = widgetKey();
    ['conversation' => $conversation] = Ticketing::conversations()->start($key, new VisitorIdentity(
        email: 'jane@example.com', signature: WidgetKeys::sign($secret, 'jane@example.com'),
    ));
    chatAi()->queueAnswer(sure('Suggested'));

    Ticketing::conversations()->post($conversation, 'Need help');

    $ticket = Ticket::query()->sole();
    expect($ticket->messages()->where('meta->ai->kind', 'draft')->value('body'))->toBe('Suggested');
});

it('continues on the ticket after the hand-over, and shows agent replies to the visitor', function (): void {
    config()->set('ticketing.ai.escalation.handoff_message', null);
    ['key' => $key] = widgetKey();
    ['conversation' => $conversation] = Ticketing::conversations()->start($key);
    $ticket = Ticketing::conversations()->escalate($conversation, EscalationReason::HumanRequested, 'Jane', 'jane@example.com');

    $this->travel(2)->seconds();
    $agent = makeUser(['name' => 'Agent']);
    Ticketing::postMessage($ticket, $agent, 'Hi Jane, looking into it.');
    Ticketing::postMessage($ticket, $agent, 'Internal: check the warehouse', MessageVisibility::Internal);
    $this->travel(2)->seconds();

    $reply = Ticketing::conversations()->post($conversation->refresh(), 'Thank you!');

    $timeline = Ticketing::conversations()->timeline($conversation);

    expect($reply)->toBeInstanceOf(TicketMessage::class)
        ->and($reply->source)->toBe(MessageSource::Widget)
        ->and(array_column($timeline, 'body'))->toBe(['Hi Jane, looking into it.', 'Thank you!'])
        ->and(array_column($timeline, 'role'))->toBe(['agent', 'visitor'])
        ->and(Ticketing::conversations()->timeline($conversation, now()->addMinute()))->toBe([]);
});

it('closes a conversation, counting AI-only ones as resolved by the AI', function (): void {
    Event::fake([ConversationClosed::class]);
    ['key' => $key] = widgetKey();
    ['conversation' => $answered] = Ticketing::conversations()->start($key);
    chatAi()->queueAnswer(sure());
    Ticketing::conversations()->post($answered, 'Question');
    ['conversation' => $idle] = Ticketing::conversations()->start($key);

    $closed = Ticketing::conversations()->close($answered);
    Ticketing::conversations()->close($closed);

    expect($closed->status)->toBe(ConversationStatus::Closed)
        ->and($closed->resolved_by_ai)->toBeTrue()
        ->and(Ticketing::conversations()->close($idle)->resolved_by_ai)->toBeFalse();

    Event::assertDispatchedTimes(ConversationClosed::class, 2);

    expect(fn () => Ticketing::conversations()->post($closed, 'Hello'))->toThrow(ConversationException::class, 'closed');
});

it('enforces message limits', function (): void {
    config()->set('ticketing.widget.max_messages_per_conversation', 2);
    config()->set('ticketing.ai.mode', 'off');
    config()->set('ticketing.widget.require_email_to_escalate', true);
    ['key' => $key] = widgetKey();
    ['conversation' => $conversation] = Ticketing::conversations()->start($key);

    Ticketing::conversations()->post($conversation, 'one');
    Ticketing::conversations()->post($conversation, 'two');

    expect(fn () => Ticketing::conversations()->post($conversation, 'three'))->toThrow(ConversationException::class, 'limit')
        ->and(fn () => Ticketing::conversations()->post($conversation, '   '))->toThrow(ConversationException::class, 'empty');
});

it('lets an agent take a conversation over at any time', function (): void {
    ['key' => $key, 'secret' => $secret] = widgetKey();
    ['conversation' => $conversation] = Ticketing::conversations()->start($key, new VisitorIdentity(
        email: 'jane@example.com', signature: WidgetKeys::sign($secret, 'jane@example.com'),
    ));
    chatAi()->queueAnswer(sure());
    Ticketing::conversations()->post($conversation, 'Question');

    $agent = makeUser(['name' => 'Agent']);
    $ticket = Ticketing::conversations()->takeOver($conversation, $agent);

    expect($ticket?->ai_status)->toBe(AiStatus::Suspended)
        ->and((string) $ticket?->assignee_id)->toBe((string) $agent->getKey())
        ->and($conversation->refresh()->escalation_reason)->toBe(EscalationReason::Manual);

    // No email, no ticket: the take-over waits like an AI hand-over.
    ['conversation' => $anonymous] = Ticketing::conversations()->start($key);
    expect(Ticketing::conversations()->takeOver($anonymous, $agent))->toBeNull();
});

it('falls back to escalation when the AI driver fails mid-conversation', function (): void {
    ['key' => $key, 'secret' => $secret] = widgetKey();
    ['conversation' => $conversation] = Ticketing::conversations()->start($key, new VisitorIdentity(
        email: 'jane@example.com', signature: WidgetKeys::sign($secret, 'jane@example.com'),
    ));
    chatAi()->failNext();

    Ticketing::conversations()->post($conversation, 'Question');

    expect($conversation->refresh()->status)->toBe(ConversationStatus::Escalated)
        ->and($conversation->escalation_reason)->toBe(EscalationReason::AiError);
});

it('answers widget visitors only from public articles of the widget audience', function (): void {
    config()->set('ticketing.ai.escalation.min_confidence', 0.01);
    $client = makeOrder();
    $kb = Ticketing::knowledge();
    $kb->publish($kb->save(new ArticleData(title: 'Shipping internal', body: 'shipping delay internal process', visibility: KnowledgeVisibility::Internal)));
    $kb->publish($kb->save(new ArticleData(title: 'Shipping for client', body: 'shipping delay client notes', audience: $client)));
    $kb->publish($kb->save(new ArticleData(title: 'Shipping public', body: 'shipping delay public answer', summary: 'Public shipping answer')));

    ['key' => $key] = widgetKey(['audience' => $client]);
    ['conversation' => $conversation] = Ticketing::conversations()->start($key);
    Ticketing::conversations()->post($conversation, 'shipping delay');

    $assistant = collect(Ticketing::conversations()->timeline($conversation))->firstWhere('role', 'assistant');

    expect(array_column($assistant['sources'], 'title'))->not->toContain('Shipping internal')
        ->and(array_column($assistant['sources'], 'title'))->toContain('Shipping for client');
});

it('keeps conversations inside their tenant', function (): void {
    ['key' => $key] = widgetKey(['tenant' => 3]);
    ['conversation' => $conversation] = Ticketing::conversations()->start($key);

    expect(app(TenantContext::class)->forTenant(4, fn () => Conversation::query()->count()))->toBe(0)
        ->and(app(TenantContext::class)->forTenant(3, fn () => Conversation::query()->count()))->toBe(1)
        ->and($conversation->messages()->withoutTenancy()->count())->toBe(0)
        ->and(ConversationRole::Visitor->value)->toBe('visitor');
});

it('erases an escalated conversation and its AI turns with the ticket on retention', function (): void {
    config()->set('ticketing.gdpr.retention', [['type' => '*', 'after_days' => 30, 'action' => 'delete']]);
    ['key' => $key, 'secret' => $secret] = widgetKey();
    ['conversation' => $conversation] = Ticketing::conversations()->start($key, new VisitorIdentity(
        email: 'jane@example.com', signature: WidgetKeys::sign($secret, 'jane@example.com'),
    ));
    chatAi()->queueAnswer(sure());
    Ticketing::conversations()->post($conversation, 'Question');
    $ticket = Ticketing::conversations()->escalate($conversation, EscalationReason::HumanRequested);
    Ticketing::transition($ticket, 'close');

    $this->travel(31)->days();
    $this->artisan('ticketing:prune')->assertSuccessful();

    expect(Ticket::query()->withTrashed()->count())->toBe(0)
        ->and(Conversation::query()->count())->toBe(0)
        ->and(Ticketing::conversationMessageModel()::query()->count())->toBe(0)
        ->and(Ticketing::aiTurnModel()::query()->count())->toBe(0);
});

it('never verifies an email that the signature does not cover', function (): void {
    ['key' => $key, 'secret' => $secret] = widgetKey();

    // A valid signature over user 42, sent next to somebody else's email.
    ['conversation' => $conversation] = Ticketing::conversations()->start($key, new VisitorIdentity(
        email: 'ceo@acme.example', userId: '42', signature: WidgetKeys::sign($secret, '42'),
    ));

    expect($conversation->identity_verified)->toBeTrue()
        ->and($conversation->contact_id)->toBeNull()
        ->and(Contact::query()->count())->toBe(0)
        ->and($conversation->meta['claimed']['email'])->toBe('ceo@acme.example');
});

it('scrubs the claimed identity when the contact is erased', function (): void {
    ['key' => $key] = widgetKey();
    ['conversation' => $conversation] = Ticketing::conversations()->start($key, new VisitorIdentity(email: 'jane@example.com', name: 'Jane'));
    Ticketing::conversations()->escalate($conversation, EscalationReason::HumanRequested);

    Ticketing::contacts()->anonymize(Contact::query()->sole());

    expect($conversation->refresh()->meta)->toBeNull();
});

it('stops answering once a concurrent turn parked a hand-over', function (): void {
    ['key' => $key] = widgetKey();
    ['conversation' => $conversation] = Ticketing::conversations()->start($key);
    config()->set('ticketing.ai.mode', 'off');
    $message = Ticketing::conversationMessageModel()::query()->create([
        'conversation_id' => $conversation->getKey(), 'role' => 'visitor', 'body' => 'Question',
    ]);
    config()->set('ticketing.ai.mode', 'auto');

    // Another turn parks the hand-over while this one waits on the driver.
    $driver = new class implements AiDriver
    {
        public ?Conversation $conversation = null;

        public function classify(AiRequest $request): AiClassification
        {
            return AiClassification::none();
        }

        public function answer(AiRequest $request): AiAnswer
        {
            $this->conversation?->forceFill(['meta' => ['handoff_pending' => true]])->save();

            return sure('Should not be sent');
        }

        public function summarize(AiRequest $request): AiSummary
        {
            return new AiSummary('');
        }
    };
    $driver->conversation = $conversation;
    app(AiManager::class)->extend('racing', fn () => $driver);
    config()->set('ticketing.ai.driver', 'racing');

    $turn = app(AiAssistant::class)->respondToConversation($conversation, $message);

    expect($turn?->outcome)->toBe(AiOutcome::Skipped)
        ->and(Ticketing::conversationMessageModel()::query()->where('body', 'Should not be sent')->exists())->toBeFalse();
});

it('clears the comments of widget votes when the visitor is erased', function (): void {
    ['key' => $key] = widgetKey();
    ['conversation' => $conversation] = Ticketing::conversations()->start($key, new VisitorIdentity(email: 'jane@example.com'));
    $kb = Ticketing::knowledge();
    $article = $kb->publish($kb->save(new ArticleData(title: 'A', body: 'B')));
    $kb->feedback($article, false, 'conversation:'.$conversation->public_id, 'Jane from flat 4B');
    Ticketing::conversations()->escalate($conversation, EscalationReason::HumanRequested);

    Ticketing::contacts()->anonymize(Contact::query()->sole());

    expect($article->feedback()->sole()->comment)->toBeNull()
        ->and($article->refresh()->not_helpful_count)->toBe(1);
});
