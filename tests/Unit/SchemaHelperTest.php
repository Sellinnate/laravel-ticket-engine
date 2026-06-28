<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
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

it('omits the tenant column and prefix when tenancy is disabled', function (): void {
    config()->set('ticketing.tenancy.enabled', false);
    config()->set('ticketing.ids.type', 'auto');

    buildProbeTable('probe_no_tenant');

    expect(Schema::hasColumn('probe_no_tenant', 'tenant_id'))->toBeFalse();
});

it('resolves prefixed table names', function (): void {
    config()->set('ticketing.tables.prefix', 'tk_');

    expect((new SchemaProbe)->tableName('tickets'))->toBe('tk_tickets');
});
