<?php

declare(strict_types=1);

namespace Selli\Ticketing\Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Selli\Ticketing\Database\Migrations\HasTicketingSchema;

/**
 * Exposes the protected migration helpers so their ID/tenant/morph branches can
 * be exercised directly in tests.
 */
class SchemaProbe
{
    use HasTicketingSchema;

    public function build(Blueprint $table): void
    {
        $this->primaryKey($table);
        $this->foreignId($table, 'related_id');
        $this->tenantColumn($table);
        $this->nullableMorph($table, 'subject');
        $this->requiredMorph($table, 'owner');
        $this->uniqueScoped($table, ['related_id'], 'probe_unq');
        $this->indexScoped($table, ['owner_type'], 'probe_idx');
    }

    public function tableName(string $key): string
    {
        return $this->table($key);
    }
}
