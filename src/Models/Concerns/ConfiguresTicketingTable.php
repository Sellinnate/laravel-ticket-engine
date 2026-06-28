<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves the (prefixable) table name from config and toggles ULID keys when
 * the package is configured for them.
 *
 * @phpstan-require-extends Model
 */
trait ConfiguresTicketingTable
{
    use HasUlids;

    /**
     * The config key under `ticketing.tables` for this model's table.
     */
    abstract protected function tableConfigKey(): string;

    public function getTable(): string
    {
        if (isset($this->table)) {
            return $this->table;
        }

        $prefix = (string) config('ticketing.tables.prefix', '');
        $name = (string) config('ticketing.tables.'.$this->tableConfigKey(), $this->tableConfigKey());

        return $prefix.$name;
    }

    /**
     * Whether the package is configured to use ULID primary keys.
     */
    public static function usesUlids(): bool
    {
        return config('ticketing.ids.type') === 'ulid';
    }

    public function getIncrementing(): bool
    {
        return ! static::usesUlids();
    }

    public function getKeyType(): string
    {
        return static::usesUlids() ? 'string' : 'int';
    }

    /**
     * Only generate ULIDs for the primary key when ULIDs are enabled.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return static::usesUlids() ? [$this->getKeyName()] : [];
    }
}
