<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('backfills the indexed Message-ID of the emails stored before it', function (): void {
    config()->set('ticketing.tables.prefix', 'b_');

    $migrations = glob(__DIR__.'/../../database/migrations/*.php') ?: [];
    sort($migrations);
    $threading = array_values(array_filter($migrations, fn (string $file): bool => str_contains($file, 'add_email_threading_columns')))[0];
    $ran = [];

    try {
        foreach ($migrations as $file) {
            if ($file === $threading) {
                break;
            }

            (require $file)->up();
            $ran[] = $file;
        }

        $message = fn (string $source, ?array $meta): array => [
            'ticket_id' => 1, 'body' => 'x', 'source' => $source, 'visibility' => 'public',
            'meta' => $meta === null ? null : json_encode($meta), 'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('b_ticket_messages')->insert([
            $message('email', ['message_id' => 'old@client.test', 'from' => 'jane@example.test']),
            $message('email', ['message_id' => str_repeat('a', 250).'@client.test']),
            $message('email', ['from' => 'no-id@example.test']),
            $message('api', ['message_id' => 'not-an-email@x.test']),
            $message('email', null),
        ]);

        (require $threading)->up();
        $ran[] = $threading;

        expect(DB::table('b_ticket_messages')->orderBy('id')->pluck('email_message_id')->all())
            ->toBe(['old@client.test', 'sha256:'.hash('sha256', str_repeat('a', 250).'@client.test'), null, null, null])
            ->and(array_column(Schema::getIndexes('b_ticket_messages'), 'name'))->toContain('ticket_messages_email_message_id_idx');
    } finally {
        foreach (array_reverse($ran) as $file) {
            (require $file)->down();
        }
    }

    expect(Schema::hasTable('b_tickets'))->toBeFalse();
})->skip(fn (): bool => DB::connection()->getDriverName() === 'pgsql', 'PostgreSQL index names are schema-wide: the suite\'s own tables already hold them.');
