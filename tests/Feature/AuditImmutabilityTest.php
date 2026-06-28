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

it('forbids a mass update that would bypass model events', function (): void {
    TicketActivity::factory()->create();

    TicketActivity::query()->update(['event' => 'tampered']);
})->throws(ImmutableAuditException::class);

it('forbids a mass delete that would bypass model events', function (): void {
    TicketActivity::factory()->create();

    TicketActivity::query()->delete();
})->throws(ImmutableAuditException::class);

it('forbids an upsert on the audit trail', function (): void {
    TicketActivity::query()->upsert(
        [['ticket_id' => 1, 'event' => 'x']],
        ['id'],
        ['event'],
    );
})->throws(ImmutableAuditException::class);

it('only tracks a created_at timestamp', function (): void {
    $activity = TicketActivity::factory()->create();

    expect($activity->created_at)->not->toBeNull()
        ->and(TicketActivity::UPDATED_AT)->toBeNull();
});
