<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Selli\Ticketing\Contracts\CanRequestTickets;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Support\WebhookGuard;
use Selli\Ticketing\Tests\Fixtures\RestrictiveTicketPolicy;

const HAPI = '/ticketing/api/v1';

it('authorizes commentInternal separately, so a host policy can deny internal notes', function (): void {
    // A host policy that allows public comments but denies internal notes.
    Gate::policy(Ticketing::ticketModel(), RestrictiveTicketPolicy::class);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $this->actingAs(makeUser()); // an agent (CanActOnTickets)

    // Public reply allowed; internal note denied by the policy (was previously
    // only gated by the Form Request, so a custom policy was bypassed).
    $this->postJson(HAPI.'/tickets/'.$ticket->getKey().'/messages', ['body' => 'hi'])->assertCreated();
    $this->postJson(HAPI.'/tickets/'.$ticket->getKey().'/messages', ['body' => 'note', 'visibility' => 'internal'])
        ->assertForbidden();
});

it('shows a non-Eloquent requester no tickets (the listing fails closed)', function (): void {
    Ticketing::open(type: 'support', title: 'someone elses', requester: makeUser());

    // A requester that is NOT an Eloquent model — it has no morph identity to
    // scope by, so it must see nothing rather than the whole tenant.
    $requester = new class implements Authenticatable, CanRequestTickets
    {
        public function requesterLabel(): string
        {
            return 'Ghost';
        }

        public function requesterEmail(): ?string
        {
            return null;
        }

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): mixed
        {
            return 'ghost';
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return '';
        }
    };

    $this->actingAs($requester);

    $this->getJson(HAPI.'/tickets')->assertOk()->assertJsonCount(0, 'data');
});

it('blocks a webhook to a NAT64-wrapped private address (SSRF)', function (): void {
    config()->set('ticketing.webhooks.block_private', true);
    config()->set('ticketing.webhooks.allowed_hosts', []);

    // 64:ff9b::a9fe:a9fe is the NAT64 mapping of 169.254.169.254 (cloud metadata).
    expect(fn () => WebhookGuard::assertAllowed('http://[64:ff9b::a9fe:a9fe]/hook'))
        ->toThrow(InvalidConfigurationException::class);

    // The plain IPv4-mapped form is still blocked too.
    expect(fn () => WebhookGuard::assertAllowed('http://[::ffff:169.254.169.254]/hook'))
        ->toThrow(InvalidConfigurationException::class);
});
