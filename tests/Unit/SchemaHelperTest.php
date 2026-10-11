<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Selli\Ticketing\Tests\Fixtures\SchemaProbe;

function buildProbeTable(string $name): void
{
    Schema::create($name, function (Blueprint $table): void {
        (new SchemaProbe)->build($table);
    });
}

it('builds tables with auto-increment ids and a bigint tenant column', function (): void {
    config()->set('ticketing.ids.type', 'auto');
    config()->set('ticketing.tenancy.column_type', 'unsignedBigInteger');

    buildProbeTable('probe_auto');

    expect(Schema::hasColumn('probe_auto', 'id'))->toBeTrue()
        ->and(Schema::hasColumn('probe_auto', 'tenant_id'))->toBeTrue()
        ->and(Schema::hasColumn('probe_auto', 'owner_type'))->toBeTrue();
});

it('builds tables with ULID ids and varied tenant column types', function (string $type): void {
    config()->set('ticketing.ids.type', 'ulid');
    config()->set('ticketing.tenancy.column_type', $type);

    $name = 'probe_ulid_'.$type;
    buildProbeTable($name);

    expect(Schema::hasColumn($name, 'id'))->toBeTrue()
        ->and(Schema::hasColumn($name, 'related_id'))->toBeTrue()
        ->and(Schema::hasColumn($name, 'tenant_id'))->toBeTrue();
})->with(['uuid', 'ulid', 'string']);

it('always creates the (nullable) tenant column even when tenancy is disabled', function (): void {
    config()->set('ticketing.tenancy.enabled', false);
    config()->set('ticketing.ids.type', 'auto');

    buildProbeTable('probe_no_tenant');

    // The column is always present (just unused/null) so runtime reads & writes
    // of tenant_id do not hit a missing column under the single-tenant setup.
    expect(Schema::hasColumn('probe_no_tenant', 'tenant_id'))->toBeTrue();
});

it('resolves prefixed table names', function (): void {
    config()->set('ticketing.tables.prefix', 'tk_');

    expect((new SchemaProbe)->tableName('tickets'))->toBe('tk_tickets');
});

it('builds a unique index that admits many NULLs', function (): void {
    Schema::create('probe_nullable_unique', function (Blueprint $table): void {
        $table->id();
        $table->string('code')->nullable();
    });
    (new SchemaProbe)->uniqueWhenPresent('probe_nullable_unique', 'code', 'probe_code_unq');

    DB::table('probe_nullable_unique')->insert([['code' => null], ['code' => null], ['code' => 'a']]);

    expect(DB::table('probe_nullable_unique')->count())->toBe(3)
        ->and(fn () => DB::table('probe_nullable_unique')->insert(['code' => 'a']))->toThrow(UniqueConstraintViolationException::class);
});

it('filters that unique index on SQL Server, whose plain unique index allows a single NULL', function (): void {
    // Pretend mode: the statement is only rendered, no SQL Server is needed.
    config()->set('database.connections.probe_sqlsrv', ['driver' => 'sqlsrv', 'database' => 'probe', 'prefix' => '']);
    $default = config('database.default');
    config()->set('database.default', 'probe_sqlsrv');

    try {
        $queries = DB::connection('probe_sqlsrv')->pretend(function (): void {
            (new SchemaProbe)->uniqueWhenPresent('probe_table', 'code', 'probe_code_unq');
        });
    } finally {
        config()->set('database.default', $default);
    }

    expect(array_column($queries, 'query'))->toBe(['create unique index [probe_code_unq] on [probe_table] ([code]) where [code] is not null']);
});
