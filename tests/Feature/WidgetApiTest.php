<?php

declare(strict_types=1);

use Selli\Ticketing\Ai\AiAnswer;
use Selli\Ticketing\Ai\AiManager;
use Selli\Ticketing\Ai\AiSource;
use Selli\Ticketing\Ai\Drivers\FakeAiDriver;
use Selli\Ticketing\Contracts\HumanVerifier;
use Selli\Ticketing\Conversations\WidgetKeys;
use Selli\Ticketing\Data\ArticleData;
use Selli\Ticketing\Enums\KnowledgeVisibility;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\Conversation;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\WidgetKey;

const WIDGET = '/ticketing/widget/v1';

/**
 * @return array{0: WidgetKey, 1: string}
 */
function widget(array $origins = ['https://acme.example']): array
{
    ['key' => $key, 'secret' => $secret] = Ticketing::widgetKeys()->create('Acme', $origins, settings: ['greeting' => 'Ciao!', 'locale' => 'it']);

    return [$key, $secret];
}

/**
 * @return array<string, string>
 */
function widgetHeaders(WidgetKey $key, ?string $token = null, string $origin = 'https://acme.example'): array
{
    return array_filter([
        'X-Ticketing-Key' => $key->public_key,
        'Origin' => $origin,
        'Authorization' => $token !== null ? 'Bearer '.$token : null,
    ]);
}

function widgetAi(): FakeAiDriver
{
    $driver = app(AiManager::class)->driver('fake');
    assert($driver instanceof FakeAiDriver);

    return $driver;
}

beforeEach(function (): void {
    config()->set('ticketing.ai.mode', 'auto');
    config()->set('ticketing.ai.escalation.handoff_message', 'A human will follow up.');
});

it('serves the widget configuration for a valid key and origin', function (): void {
    [$key] = widget();

    $this->getJson(WIDGET.'/config', widgetHeaders($key))
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', 'https://acme.example')
        ->assertJsonPath('data.name', 'Acme')
        ->assertJsonPath('data.greeting', 'Ciao!')
        ->assertJsonPath('data.locale', 'it')
        ->assertJsonPath('data.assistant', true)
        ->assertJsonMissingPath('data.id');
});

it('rejects unknown and revoked keys, and foreign origins', function (): void {
    [$key] = widget();

    $this->getJson(WIDGET.'/config', ['X-Ticketing-Key' => 'tk_pub_missing'])->assertStatus(401);
    $this->getJson(WIDGET.'/config')->assertStatus(401);

    $this->getJson(WIDGET.'/config', widgetHeaders($key, origin: 'https://evil.example'))
        ->assertForbidden()
        ->assertHeaderMissing('Access-Control-Allow-Origin');

    // A server-to-server call (no Origin) is allowed and gets no CORS header.
    $this->getJson(WIDGET.'/config', ['X-Ticketing-Key' => $key->public_key])
        ->assertOk()
        ->assertHeaderMissing('Access-Control-Allow-Origin');

    Ticketing::widgetKeys()->revoke($key);
    $this->getJson(WIDGET.'/config', widgetHeaders($key))->assertStatus(401);
});

it('answers CORS preflights without a key', function (): void {
    $this->call('OPTIONS', WIDGET.'/conversations', server: [
        'HTTP_ORIGIN' => 'https://acme.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ])
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'https://acme.example')
        ->assertHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Ticketing-Key, X-Ticketing-Conversation');
});

it('runs a whole conversation over HTTP', function (): void {
    [$key, $secret] = widget();
    widgetAi()->queueAnswer(new AiAnswer('Use the reset link.', [new AiSource('kb_article', '1', 'Reset')], 0.95));

    $start = $this->postJson(WIDGET.'/conversations', [
        'identity' => ['email' => 'jane@example.com', 'name' => 'Jane', 'signature' => WidgetKeys::sign($secret, 'jane@example.com')],
        'locale' => 'it',
    ], widgetHeaders($key))
        ->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.identity_verified', true)
        ->assertJsonPath('data.ticket', null);

    $id = (string) $start->json('data.id');
    $token = (string) $start->json('data.token');

    expect($id)->toBe(Conversation::query()->sole()->public_id)
        ->and($start->json('data.expires_at'))->not->toBeNull();

    $this->postJson(WIDGET."/conversations/{$id}/messages", ['body' => 'How do I reset my password?'], widgetHeaders($key, $token))
        ->assertCreated()
        ->assertJsonPath('data.messages.0.role', 'visitor')
        ->assertJsonPath('data.messages.1.role', 'assistant')
        ->assertJsonPath('data.messages.1.body', 'Use the reset link.')
        ->assertJsonPath('data.messages.1.sources.0.title', 'Reset');

    expect($this->getJson(WIDGET."/conversations/{$id}/messages", widgetHeaders($key, $token))->json('data.messages.0.id'))->toHaveLength(20);

    $this->getJson(WIDGET."/conversations/{$id}/messages", widgetHeaders($key, $token))
        ->assertOk()
        ->assertJsonCount(2, 'data.messages');

    $this->postJson(WIDGET."/conversations/{$id}/escalate", [], widgetHeaders($key, $token))
        ->assertOk()
        ->assertJsonPath('data.status', 'escalated')
        ->assertJsonPath('data.ticket', Ticket::query()->sole()->reference);

    $this->postJson(WIDGET."/conversations/{$id}/close", [], widgetHeaders($key, $token))
        ->assertOk()
        ->assertJsonPath('data.status', 'escalated');
});

it('requires the bearer token of that very conversation and key', function (): void {
    [$key] = widget();
    [$other] = widget();
    $start = $this->postJson(WIDGET.'/conversations', [], widgetHeaders($key))->assertCreated();
    $id = (string) $start->json('data.id');
    $token = (string) $start->json('data.token');

    $this->getJson(WIDGET."/conversations/{$id}/messages", widgetHeaders($key))->assertNotFound();
    $this->getJson(WIDGET."/conversations/{$id}/messages", widgetHeaders($key, 'wrong'))->assertNotFound();
    $this->getJson(WIDGET."/conversations/{$id}/messages", widgetHeaders($other, $token))->assertNotFound();
    $this->getJson(WIDGET.'/conversations/unknown/messages', widgetHeaders($key, $token))->assertNotFound();
});

it('asks anonymous visitors for an email before a human takes over', function (): void {
    [$key] = widget();
    $start = $this->postJson(WIDGET.'/conversations', [], widgetHeaders($key))->assertCreated();
    $id = (string) $start->json('data.id');
    $token = (string) $start->json('data.token');

    $this->postJson(WIDGET."/conversations/{$id}/escalate", [], widgetHeaders($key, $token))
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    $this->postJson(WIDGET."/conversations/{$id}/escalate", ['email' => 'not an email'], widgetHeaders($key, $token))
        ->assertStatus(422);

    $this->postJson(WIDGET."/conversations/{$id}/escalate", ['name' => 'Jane', 'email' => 'jane@example.com'], widgetHeaders($key, $token))
        ->assertOk()
        ->assertJsonPath('data.status', 'escalated');
});

it('stops bots with the honeypot and the human verifier', function (): void {
    [$key] = widget();

    $this->postJson(WIDGET.'/conversations', ['website' => 'http://spam.example'], widgetHeaders($key))
        ->assertStatus(422);

    app()->bind(HumanVerifier::class, fn () => new class implements HumanVerifier
    {
        public function verify(?string $token, ?string $ip): bool
        {
            return $token === 'ok';
        }
    });

    $this->postJson(WIDGET.'/conversations', [], widgetHeaders($key))
        ->assertStatus(422)
        ->assertJsonValidationErrors('captcha_token');

    $this->postJson(WIDGET.'/conversations', ['captcha_token' => 'ok'], widgetHeaders($key))->assertCreated();
});

it('validates messages and closed conversations', function (): void {
    config()->set('ticketing.widget.max_message_length', 10);
    [$key] = widget();
    $start = $this->postJson(WIDGET.'/conversations', [], widgetHeaders($key))->assertCreated();
    $id = (string) $start->json('data.id');
    $token = (string) $start->json('data.token');

    $this->postJson(WIDGET."/conversations/{$id}/messages", ['body' => str_repeat('x', 11)], widgetHeaders($key, $token))
        ->assertStatus(422);
    $this->postJson(WIDGET."/conversations/{$id}/messages", ['body' => 'hi', 'website' => 'x'], widgetHeaders($key, $token))
        ->assertStatus(422);

    $this->postJson(WIDGET."/conversations/{$id}/close", [], widgetHeaders($key, $token))
        ->assertOk()
        ->assertJsonPath('data.status', 'closed');

    $this->postJson(WIDGET."/conversations/{$id}/messages", ['body' => 'hello'], widgetHeaders($key, $token))
        ->assertStatus(422)
        ->assertJsonValidationErrors('body');

    $this->postJson(WIDGET."/conversations/{$id}/escalate", ['email' => 'a@b.example'], widgetHeaders($key, $token))
        ->assertStatus(422);
});

it('rate limits conversation starts per IP', function (): void {
    config()->set('ticketing.widget.rate_limits.conversations_per_ip', '2,60');
    [$key] = widget();

    $this->postJson(WIDGET.'/conversations', [], widgetHeaders($key))->assertCreated();
    $this->postJson(WIDGET.'/conversations', [], widgetHeaders($key))->assertCreated();
    $this->postJson(WIDGET.'/conversations', [], widgetHeaders($key))->assertStatus(429);
});

it('serves the public help center to the widget audience', function (): void {
    [$key] = widget();
    $kb = Ticketing::knowledge();
    $kb->publish($kb->save(new ArticleData(title: 'Reset password', body: 'Open settings to reset the password.')));
    $kb->publish($kb->save(new ArticleData(title: 'Internal password runbook', body: 'password admin', visibility: KnowledgeVisibility::Internal)));

    $this->getJson(WIDGET.'/kb/search?q=password', widgetHeaders($key))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'reset-password')
        ->assertJsonMissingPath('data.0.id');

    $this->getJson(WIDGET.'/kb/articles/reset-password', widgetHeaders($key))
        ->assertOk()
        ->assertJsonPath('data.title', 'Reset password')
        ->assertJsonPath('data.body_format', 'markdown');

    $this->getJson(WIDGET.'/kb/articles/internal-password-runbook', widgetHeaders($key))->assertNotFound();

    $this->postJson(WIDGET.'/kb/articles/reset-password/feedback', ['helpful' => true], widgetHeaders($key))
        ->assertCreated();
    $this->postJson(WIDGET.'/kb/articles/reset-password/feedback', ['helpful' => true], widgetHeaders($key))
        ->assertCreated();
    $this->postJson(WIDGET.'/kb/articles/missing/feedback', ['helpful' => true], widgetHeaders($key))
        ->assertNotFound();

    expect(Ticketing::kbArticleModel()::query()->where('slug', 'reset-password')->sole()->helpful_count)->toBe(1);

    $this->getJson(WIDGET.'/kb/search?q=a', widgetHeaders($key))->assertStatus(422);
});

it('counts one vote per conversation for identified readers', function (): void {
    [$key] = widget();
    $kb = Ticketing::knowledge();
    $kb->publish($kb->save(new ArticleData(title: 'Shipping', body: 'Two days.')));

    $start = $this->postJson(WIDGET.'/conversations', [], widgetHeaders($key))->assertCreated();
    $headers = widgetHeaders($key, (string) $start->json('data.token')) + ['X-Ticketing-Conversation' => (string) $start->json('data.id')];

    $this->postJson(WIDGET.'/kb/articles/shipping/feedback', ['helpful' => false, 'comment' => 'Too slow'], $headers)->assertCreated();
    $this->postJson(WIDGET.'/kb/articles/shipping/feedback', ['helpful' => true], $headers)->assertCreated();

    $article = Ticketing::kbArticleModel()::query()->sole();
    expect($article->helpful_count)->toBe(1)->and($article->not_helpful_count)->toBe(0);
});

it('publishes the reference widget client and the widget routes', function (): void {
    $this->artisan('vendor:publish', ['--tag' => 'ticketing-widget', '--force' => true])->assertSuccessful();

    $path = public_path('vendor/ticketing/ticketing-widget.js');

    expect(file_exists($path))->toBeTrue()
        ->and((string) file_get_contents($path))->toContain('class TicketingWidget');

    @unlink($path);
});

it('counts the per-key limit on the resolved key, whatever the header spelling', function (): void {
    config()->set('ticketing.widget.rate_limits.per_key', '2,1');
    [$key] = widget();
    $headers = fn (string $value): array => array_merge(widgetHeaders($key), ['X-Ticketing-Key' => $value]);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])->getJson(WIDGET.'/config', $headers($key->public_key))->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2'])->getJson(WIDGET.'/config', $headers(' '.$key->public_key))->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.3'])->getJson(WIDGET.'/config', $headers($key->public_key.' '))->assertStatus(429);

    // Only the exact key resolves, even under a case-insensitive collation.
    $this->getJson(WIDGET.'/config', $headers('tk_pub_'.strtoupper(substr($key->public_key, 7))))->assertStatus(401);
});

it('lets the browser read a rate-limit response (CORS on 429)', function (): void {
    config()->set('ticketing.widget.rate_limits.per_ip', '2,1');
    [$key] = widget();

    $this->getJson(WIDGET.'/config', widgetHeaders($key))->assertOk();
    $this->getJson(WIDGET.'/config', widgetHeaders($key))->assertOk();
    $this->getJson(WIDGET.'/config', widgetHeaders($key))
        ->assertStatus(429)
        ->assertHeader('Access-Control-Allow-Origin', 'https://acme.example');
});
