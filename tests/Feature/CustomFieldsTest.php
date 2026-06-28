<?php

declare(strict_types=1);

use Selli\Ticketing\Models\Ticket;

it('reads, writes and lists custom fields', function (): void {
    $ticket = Ticket::factory()->create(['custom_fields' => ['severity' => 'high']]);

    expect($ticket->customField('severity'))->toBe('high')
        ->and($ticket->customField('missing', 'fallback'))->toBe('fallback');

    $ticket->setCustomField('region', 'EU')->save();

    expect($ticket->fresh()->customFields())->toBe(['severity' => 'high', 'region' => 'EU']);
});

it('returns an empty array when no custom fields are set', function (): void {
    $ticket = Ticket::factory()->create(['custom_fields' => null]);

    expect($ticket->customFields())->toBe([])
        ->and($ticket->customField('any'))->toBeNull();
});
