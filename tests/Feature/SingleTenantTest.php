<?php

declare(strict_types=1);

use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\TicketActivity;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Tenancy\TenantContext;

beforeEach(function (): void {
    config()->set('ticketing.tenancy.enabled', false);
    app()->forgetInstance(TenantContext::class);
});

it('opens tickets, posts messages and allocates references with tenancy disabled', function (): void {
    $user = makeUser();

    $ticket = Ticketing::open(type: 'support', title: 'Single tenant', requester: $user);
    Ticketing::for($ticket)->postMessage($user, 'Hello');

    $year = date('Y');

    expect($ticket->reference)->toBe("SUPPORT-{$year}-00001")
        ->and($ticket->tenant_id)->toBeNull()
        ->and(TicketMessage::query()->count())->toBe(1)
        ->and(TicketActivity::query()->where('event', 'ticket.opened')->count())->toBe(1);

    // A second ticket continues the sequence (no missing-column errors).
    $second = Ticketing::open(type: 'support', title: 'Another', requester: $user);
    expect($second->reference)->toBe("SUPPORT-{$year}-00002");
});
