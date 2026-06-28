<?php

declare(strict_types=1);

use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Policies\TicketPolicy;
use Selli\Ticketing\Tenancy\TenantContext;
use Selli\Ticketing\Tests\Fixtures\TestRequester;

function ticketPolicy(): TicketPolicy
{
    return app(TicketPolicy::class);
}

it('allows any agent or requester to list and create', function (): void {
    $agent = makeUser();
    $requester = TestRequester::query()->create(['name' => 'R']);

    expect(ticketPolicy()->viewAny($agent))->toBeTrue()
        ->and(ticketPolicy()->create($requester))->toBeTrue()
        ->and(ticketPolicy()->create($agent))->toBeTrue();
});

it('lets a tenant agent view and act, and denies a cross-tenant agent', function (): void {
    $context = app(TenantContext::class);
    $ticket = $context->forTenant(5, fn () => Ticketing::open(type: 'support', title: 'x', requester: makeUser(['tenant_id' => 5])));

    $agent5 = makeUser(['tenant_id' => 5]);
    $agent9 = makeUser(['tenant_id' => 9]);

    expect(ticketPolicy()->view($agent5, $ticket))->toBeTrue()
        ->and(ticketPolicy()->comment($agent5, $ticket))->toBeTrue()
        ->and(ticketPolicy()->commentInternal($agent5, $ticket))->toBeTrue()
        ->and(ticketPolicy()->addAttachment($agent5, $ticket))->toBeTrue()
        ->and(ticketPolicy()->transition($agent5, $ticket))->toBeTrue()
        ->and(ticketPolicy()->assign($agent5, $ticket))->toBeTrue()
        ->and(ticketPolicy()->changePriority($agent5, $ticket))->toBeTrue()
        ->and(ticketPolicy()->merge($agent5, $ticket))->toBeTrue()
        ->and(ticketPolicy()->split($agent5, $ticket))->toBeTrue()
        ->and(ticketPolicy()->delete($agent5, $ticket))->toBeTrue()
        ->and(ticketPolicy()->submitCsat($agent5, $ticket))->toBeTrue()
        ->and(ticketPolicy()->viewInternal($agent5, $ticket))->toBeTrue()
        ->and(ticketPolicy()->view($agent9, $ticket))->toBeFalse()
        ->and(ticketPolicy()->transition($agent9, $ticket))->toBeFalse();
});

it('lets the requester/subject and participants view but not do agent actions', function (): void {
    $context = app(TenantContext::class);
    $requester = TestRequester::query()->create(['name' => 'Owner', 'tenant_id' => 5]);
    $ticket = $context->forTenant(5, fn () => Ticketing::open(type: 'support', title: 'x', requester: $requester));

    // Requester (a participant) can view + comment but not transition/internal.
    expect(ticketPolicy()->view($requester, $ticket))->toBeTrue()
        ->and(ticketPolicy()->comment($requester, $ticket))->toBeTrue()
        ->and(ticketPolicy()->addAttachment($requester, $ticket))->toBeTrue()
        ->and(ticketPolicy()->submitCsat($requester, $ticket))->toBeTrue()
        ->and(ticketPolicy()->viewInternal($requester, $ticket))->toBeFalse()
        ->and(ticketPolicy()->commentInternal($requester, $ticket))->toBeFalse()
        ->and(ticketPolicy()->transition($requester, $ticket))->toBeFalse()
        ->and(ticketPolicy()->assign($requester, $ticket))->toBeFalse()
        ->and(ticketPolicy()->merge($requester, $ticket))->toBeFalse()
        ->and(ticketPolicy()->split($requester, $ticket))->toBeFalse()
        ->and(ticketPolicy()->delete($requester, $ticket))->toBeFalse();

    // An explicit watcher participant may view; a stranger may not.
    $watcher = TestRequester::query()->create(['name' => 'Watcher', 'tenant_id' => 5]);
    $context->forTenant(5, fn () => $ticket->participants()->create([
        'participant_type' => $watcher->getMorphClass(),
        'participant_id' => $watcher->getKey(),
        'role' => ParticipantRole::Watcher->value,
        'notify' => true,
    ]));

    $stranger = TestRequester::query()->create(['name' => 'Stranger', 'tenant_id' => 5]);

    expect(ticketPolicy()->view($watcher, $ticket))->toBeTrue()
        ->and(ticketPolicy()->view($stranger, $ticket))->toBeFalse();
});
