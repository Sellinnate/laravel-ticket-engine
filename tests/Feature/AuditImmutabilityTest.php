<?php

declare(strict_types=1);

use Selli\Ticketing\Exceptions\ImmutableAuditException;
use Selli\Ticketing\Models\TicketActivity;

it('forbids updating an audit record', function (): void {
    $activity = TicketActivity::factory()->create();

    $activity->event = 'tampered';
    $activity->save();
})->throws(ImmutableAuditException::class);

it('forbids deleting an audit record', function (): void {
    $activity = TicketActivity::factory()->create();

    $activity->delete();
})->throws(ImmutableAuditException::class);

it('only tracks a created_at timestamp', function (): void {
    $activity = TicketActivity::factory()->create();

    expect($activity->created_at)->not->toBeNull()
        ->and(TicketActivity::UPDATED_AT)->toBeNull();
});
