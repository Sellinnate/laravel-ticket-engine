<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Database\SqlDialect;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\Ticket;

it('renders portable duration, day and string-cast expressions on the active driver', function (): void {
    Carbon::setTestNow('2026-04-02 08:00:00');
    $ticket = Ticketing::open(type: 'support', title: 'x');
    Ticket::query()->whereKey($ticket->getKey())->update(['resolved_at' => '2026-04-02 09:30:15']);
    Carbon::setTestNow();

    $query = fn () => DB::table($ticket->getTable())->where('id', $ticket->getKey());

    $seconds = $query()->value(SqlDialect::secondsBetween('created_at', 'resolved_at'));
    $day = $query()->value(SqlDialect::day('created_at'));

    expect((int) round((float) $seconds))->toBe(5415)
        ->and($day)->toBe('2026-04-02');

    // A numeric key compared with a string column through the cast.
    $matches = DB::table($ticket->getTable())
        ->where('reference', $ticket->reference)
        ->where(SqlDialect::castToString('id'), '=', (string) $ticket->getKey())
        ->count();

    expect($matches)->toBe(1);
});

it('yields NULL durations when either side is NULL', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x');

    $seconds = DB::table($ticket->getTable())
        ->where('id', $ticket->getKey())
        ->value(SqlDialect::secondsBetween('created_at', 'resolved_at'));

    expect($seconds)->toBeNull();
});
