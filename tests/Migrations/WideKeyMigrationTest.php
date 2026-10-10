<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wide key configurations must still migrate on MySQL, where an index over too
 * many utf8mb4 VARCHAR(255) parts exceeds InnoDB's 3072-byte key limit. Every
 * package migration runs into a table prefix so the suite's own schema is
 * untouched (MySQL index names are per table, so the copies cannot collide;
 * the prefix stays short because MySQL caps identifiers at 64 characters).
 */
function migrateEverythingWithPrefix(string $prefix): void
{
    config()->set('ticketing.tables.prefix', $prefix);

    $migrations = glob(__DIR__.'/../../database/migrations/*.php') ?: [];
    sort($migrations);
    $ran = [];

    try {
        foreach ($migrations as $file) {
            (require $file)->up();
            $ran[] = $file;
        }

        expect(Schema::hasTable($prefix.'tickets'))->toBeTrue()
            ->and(Schema::hasColumn($prefix.'tickets', 'channel'))->toBeTrue();
    } finally {
        foreach (array_reverse($ran) as $file) {
            (require $file)->down();
        }
    }

    expect($ran)->toHaveCount(count($migrations))
        ->and(Schema::hasTable($prefix.'tickets'))->toBeFalse();
}

it('migrates on MySQL with a string tenant column', function (): void {
    config()->set('ticketing.tenancy.column_type', 'string');

    migrateEverythingWithPrefix('w_');
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'mysql', 'InnoDB key-length limits only apply to MySQL/MariaDB.');

it('migrates on MySQL with ULID ids once the default string length is 191', function (): void {
    // The documented requirement for ULID keys on MySQL utf8mb4: the original
    // participants unique index (a ULID plus three VARCHARs) is 3164 bytes at
    // the default length of 255.
    config()->set('ticketing.tenancy.column_type', 'string');
    config()->set('ticketing.ids.type', 'ulid');
    Builder::defaultStringLength(191);

    try {
        migrateEverythingWithPrefix('u_');
    } finally {
        Builder::defaultStringLength(255);
    }
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'mysql', 'InnoDB key-length limits only apply to MySQL/MariaDB.');
