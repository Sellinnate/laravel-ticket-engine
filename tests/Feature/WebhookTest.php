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

it('emits WebhookFailed when the job is dead-lettered', function (): void {
    Event::fake([WebhookFailed::class]);

    (new DeliverWebhook('https://example.test/hook', ['a' => 1]))->failed(new RuntimeException('boom'));

    Event::assertDispatched(WebhookFailed::class, fn (WebhookFailed $e): bool => $e->url === 'https://example.test/hook' && $e->error === 'boom');
});

it('round-trips a signature through the verifier', function (): void {
    $body = '{"a":1}';
    $sig = WebhookSigner::sign($body, 'k');

    expect(WebhookSigner::verify($body, 'k', $sig))->toBeTrue()
        ->and(WebhookSigner::verify($body, 'k', $sig.'x'))->toBeFalse()
        ->and(WebhookSigner::verify('{"a":2}', 'k', $sig))->toBeFalse();
});
