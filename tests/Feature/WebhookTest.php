<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Selli\Ticketing\Events\WebhookFailed;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Jobs\DeliverWebhook;
use Selli\Ticketing\Support\WebhookSigner;

// The delivery tests target example.test; allow-list it so the SSRF heuristic
// (which may resolve reserved test domains) doesn't get in the way. Reset the
// guard flags each test so a test that toggles them can't leak via order.
beforeEach(function (): void {
    config()->set('ticketing.webhooks.block_private', true);
    config()->set('ticketing.webhooks.allowed_hosts', ['example.test']);
});

it('signs and posts the payload', function (): void {
    config()->set('ticketing.webhooks.secret', 'shhh');
    Http::fake(['*' => Http::response('', 200)]);

    (new DeliverWebhook('https://example.test/hook', ['event' => 'ticket.opened', 'ticket' => ['id' => 1]]))->handle();

    Http::assertSent(function (Request $request): bool {
        $expected = WebhookSigner::sign($request->body(), 'shhh');

        return $request->url() === 'https://example.test/hook'
            && $request->hasHeader(WebhookSigner::HEADER, $expected)
            && $request['event'] === 'ticket.opened';
    });
});

it('omits the signature when no secret is configured', function (): void {
    config()->set('ticketing.webhooks.secret', null);
    Http::fake(['*' => Http::response('', 200)]);

    (new DeliverWebhook('https://example.test/hook', ['a' => 1]))->handle();

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader(WebhookSigner::HEADER));
});

it('prefers a per-call secret over the configured default', function (): void {
    config()->set('ticketing.webhooks.secret', 'default');
    Http::fake(['*' => Http::response('', 200)]);

    (new DeliverWebhook('https://example.test/hook', ['a' => 1], secret: 'override'))->handle();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader(
        WebhookSigner::HEADER,
        WebhookSigner::sign($request->body(), 'override'),
    ));
});

it('throws on a non-2xx response so the job retries', function (): void {
    Http::fake(['*' => Http::response('nope', 500)]);

    (new DeliverWebhook('https://example.test/hook', ['a' => 1]))->handle();
})->throws(RequestException::class);

it('refuses a non-http(s) url', function (): void {
    (new DeliverWebhook('file:///etc/passwd', ['a' => 1]))->handle();
})->throws(InvalidConfigurationException::class);

it('blocks a webhook to a private/loopback address (SSRF)', function (): void {
    config()->set('ticketing.webhooks.allowed_hosts', []); // use the private-range heuristic
    (new DeliverWebhook('http://127.0.0.1/hook', ['a' => 1]))->handle();
})->throws(InvalidConfigurationException::class);

it('blocks a webhook to an IPv6 loopback address (SSRF)', function (): void {
    config()->set('ticketing.webhooks.allowed_hosts', []);
    (new DeliverWebhook('http://[::1]/hook', ['a' => 1]))->handle();
})->throws(InvalidConfigurationException::class);

it('allows a private address when explicitly allow-listed', function (): void {
    config()->set('ticketing.webhooks.allowed_hosts', ['127.0.0.1']);
    Http::fake(['*' => Http::response('', 200)]);

    (new DeliverWebhook('http://127.0.0.1/hook', ['a' => 1]))->handle();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1/hook');
});

it('emits WebhookFailed when the job is dead-lettered', function (): void {
    Event::fake([WebhookFailed::class]);

    (new DeliverWebhook('https://example.test/hook', ['a' => 1]))->failed(new RuntimeException('boom'));

    Event::assertDispatched(WebhookFailed::class, fn (WebhookFailed $e): bool => $e->url === 'https://example.test/hook' && $e->error === 'boom');
});

it('allows a private address when block_private is disabled', function (): void {
    config()->set('ticketing.webhooks.allowed_hosts', []);
    config()->set('ticketing.webhooks.block_private', false);
    Http::fake(['*' => Http::response('', 200)]);

    (new DeliverWebhook('http://127.0.0.1/hook', ['a' => 1]))->handle();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1/hook');
});

it('rejects a url with no host', function (): void {
    config()->set('ticketing.webhooks.allowed_hosts', []);
    (new DeliverWebhook('http:///just-a-path', ['a' => 1]))->handle();
})->throws(InvalidConfigurationException::class);

it('rejects a host that is not allow-listed', function (): void {
    config()->set('ticketing.webhooks.allowed_hosts', ['allowed.test']);
    (new DeliverWebhook('https://evil.test/hook', ['a' => 1]))->handle();
})->throws(InvalidConfigurationException::class);

it('round-trips a signature through the verifier', function (): void {
    $body = '{"a":1}';
    $sig = WebhookSigner::sign($body, 'k');

    expect(WebhookSigner::verify($body, 'k', $sig))->toBeTrue()
        ->and(WebhookSigner::verify($body, 'k', $sig.'x'))->toBeFalse()
        ->and(WebhookSigner::verify('{"a":2}', 'k', $sig))->toBeFalse();
});
