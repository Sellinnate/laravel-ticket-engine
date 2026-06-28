<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Selli\Ticketing\Enums\CsatScale;
use Selli\Ticketing\Events\CsatRequested;
use Selli\Ticketing\Events\CsatSubmitted;
use Selli\Ticketing\Exceptions\CrossTenantException;
use Selli\Ticketing\Exceptions\CsatException;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\SatisfactionRating;
use Selli\Ticketing\Support\CsatToken;
use Selli\Ticketing\Tenancy\TenantContext;

afterEach(fn () => Carbon::setTestNow());

it('auto-requests CSAT when a ticket is resolved', function (): void {
    Event::fake([CsatRequested::class]);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    Ticketing::for($ticket)->transition('resolve');

    expect(SatisfactionRating::query()->where('ticket_id', $ticket->getKey())->exists())->toBeTrue();
    Event::assertDispatched(CsatRequested::class, fn (CsatRequested $e): bool => $e->ticket->is($ticket) && $e->token !== '');
});

it('does not auto-request when auto_request is off', function (): void {
    config()->set('ticketing.csat.auto_request', false);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    Ticketing::for($ticket)->transition('resolve');

    expect(SatisfactionRating::query()->where('ticket_id', $ticket->getKey())->exists())->toBeFalse();
});

it('submits a valid rating and emits CsatSubmitted', function (): void {
    Event::fake([CsatSubmitted::class]);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $requester = makeUser();
    Ticketing::requestCsat($ticket);

    $rating = Ticketing::submitCsat($ticket, 5, 'Excellent', $requester);

    expect($rating->rating)->toBe(5)
        ->and($rating->comment)->toBe('Excellent')
        ->and($rating->isSubmitted())->toBeTrue()
        ->and($rating->isPositive())->toBeTrue()
        ->and((string) $rating->submitted_by_id)->toBe((string) $requester->getKey());
    Event::assertDispatched(CsatSubmitted::class);
});

it('rejects a rating outside the scale', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    Ticketing::requestCsat($ticket);

    Ticketing::submitCsat($ticket, 9); // five_star accepts 1..5
})->throws(CsatException::class);

it('keeps a single rating per ticket on resubmit', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    Ticketing::requestCsat($ticket);

    Ticketing::submitCsat($ticket, 4);
    Ticketing::submitCsat($ticket, 2, 'changed my mind');

    expect(SatisfactionRating::query()->where('ticket_id', $ticket->getKey())->count())->toBe(1)
        ->and(SatisfactionRating::query()->where('ticket_id', $ticket->getKey())->first()->rating)->toBe(2);
});

it('re-arms the rating when a resolved ticket is reopened and resolved again', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    Ticketing::for($ticket)->transition('resolve');
    Ticketing::submitCsat($ticket, 5);

    Ticketing::for($ticket->fresh())->transition('reopen');
    Ticketing::for($ticket->fresh())->transition('resolve'); // re-requests CSAT

    $rating = SatisfactionRating::query()->where('ticket_id', $ticket->getKey())->first();
    expect($rating->isSubmitted())->toBeFalse() // prior submission cleared
        ->and($rating->rating)->toBeNull();
});

it('submits via a signed token', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $token = Ticketing::csatToken($ticket);

    $rating = Ticketing::submitCsatByToken($token, 5);

    expect($rating->rating)->toBe(5)
        ->and(CsatToken::verify($token))->toBe((string) $ticket->getKey());
});

it('does not overwrite an already-submitted rating via the token', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $token = Ticketing::csatToken($ticket);

    Ticketing::submitCsatByToken($token, 5, 'first');
    Ticketing::submitCsatByToken($token, 1, 'tampered'); // bearer link can't rewrite

    $rating = SatisfactionRating::query()->where('ticket_id', $ticket->getKey())->first();
    expect($rating->rating)->toBe(5)
        ->and($rating->comment)->toBe('first');
});

it('does not auto-request when CSAT is disabled at runtime', function (): void {
    config()->set('ticketing.csat.enabled', false); // subscriber still bound from boot
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    Ticketing::for($ticket)->transition('resolve'); // must not throw or persist

    expect(SatisfactionRating::query()->where('ticket_id', $ticket->getKey())->exists())->toBeFalse();
    expect($ticket->fresh()->status)->toBe('resolved');
});

it('rejects an expired token', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-29 10:00:00', 'UTC'));
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $token = Ticketing::csatToken($ticket, ttl: 60);

    Carbon::setTestNow(Carbon::parse('2026-06-29 10:02:00', 'UTC')); // past the 60s ttl

    Ticketing::submitCsatByToken($token, 5);
})->throws(CsatException::class);

it('rejects a tampered token', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $token = Ticketing::csatToken($ticket);

    expect(CsatToken::verify($token.'x'))->toBeNull();
});

it('refuses a cross-tenant submitter', function (): void {
    $context = app(TenantContext::class);
    $ticket = $context->forTenant(5, fn () => Ticketing::open(type: 'support', title: 'x', requester: makeUser(['tenant_id' => 5])));
    $intruder = $context->forTenant(9, fn () => makeUser(['tenant_id' => 9]));

    $context->forTenant(5, fn () => Ticketing::submitCsat($ticket, 5, null, $intruder));
})->throws(CrossTenantException::class);

it('fails closed when CSAT is disabled', function (): void {
    config()->set('ticketing.csat.enabled', false);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    expect(fn () => Ticketing::requestCsat($ticket))->toThrow(CsatException::class);
    expect(fn () => Ticketing::submitCsat($ticket, 5))->toThrow(CsatException::class);
});

it('rejects a malformed token', function (): void {
    expect(CsatToken::verify('no-dot-here'))->toBeNull()
        ->and(CsatToken::verify('!!notbase64!!.signature'))->toBeNull()
        ->and(CsatToken::verify(''))->toBeNull();
});

it('exposes the ticket and submitter relations', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $requester = makeUser();
    Ticketing::requestCsat($ticket);
    $rating = Ticketing::submitCsat($ticket, 5, 'thanks', $requester);

    expect($rating->ticket->is($ticket))->toBeTrue()
        ->and($rating->submittedBy->is($requester))->toBeTrue()
        ->and(SatisfactionRating::query()->submitted()->count())->toBe(1);
});

it('exposes scale semantics for aggregation', function (): void {
    expect(CsatScale::FiveStar->accepts(5))->toBeTrue()
        ->and(CsatScale::FiveStar->accepts(0))->toBeFalse()
        ->and(CsatScale::Thumbs->isPositive(1))->toBeTrue()
        ->and(CsatScale::Thumbs->isPositive(0))->toBeFalse()
        ->and(CsatScale::Nps->accepts(10))->toBeTrue()
        ->and(CsatScale::Nps->isPositive(9))->toBeTrue()   // promoter
        ->and(CsatScale::Nps->isPositive(8))->toBeFalse()  // passive, not positive
        ->and(CsatScale::Nps->isPositive(6))->toBeFalse()  // detractor
        ->and(CsatScale::Nps->isPositive(11))->toBeFalse() // out of scale
        ->and(CsatScale::FiveStar->isPositive(6))->toBeFalse(); // out of scale
});

it('fails closed on a non-positive token ttl', function (): void {
    config()->set('ticketing.csat.token.ttl', 0);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    Ticketing::csatToken($ticket);
})->throws(InvalidConfigurationException::class);

it('does not persist a rating when the token config is invalid', function (): void {
    config()->set('ticketing.csat.token.ttl', 0); // invalid → token resolution throws
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    expect(fn () => Ticketing::requestCsat($ticket))->toThrow(InvalidConfigurationException::class);

    // The row must not exist: the config error aborts before any persistence.
    expect(SatisfactionRating::query()->where('ticket_id', $ticket->getKey())->exists())->toBeFalse();
});

it('fails closed when no token secret is available', function (): void {
    config()->set('ticketing.csat.token.secret', null);
    config()->set('app.key', '');
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    Ticketing::csatToken($ticket, ttl: 60);
})->throws(InvalidConfigurationException::class);

it('treats a token for a deleted ticket as invalid', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $token = Ticketing::csatToken($ticket);
    $ticket->forceDelete();

    Ticketing::submitCsatByToken($token, 5);
})->throws(CsatException::class);

it('scopes submitted ratings within a period', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-29 10:00:00', 'UTC'));
    $a = Ticketing::open(type: 'support', title: 'a', requester: makeUser());
    Ticketing::submitCsat($a, 5);

    $from = Carbon::parse('2026-06-29 00:00:00', 'UTC');
    $to = Carbon::parse('2026-06-29 23:59:59', 'UTC');

    expect(SatisfactionRating::query()->submittedBetween($from, $to)->count())->toBe(1)
        ->and(SatisfactionRating::query()->submittedBetween($from->copy()->addDay(), $to->copy()->addDay())->count())->toBe(0);
});
