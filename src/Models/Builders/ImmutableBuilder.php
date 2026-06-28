<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Exceptions\ImmutableAuditException;

/**
 * Query builder for append-only models. Model events alone do not protect
 * against mass operations (`Model::query()->update()` / `->delete()` skip the
 * updating/deleting events), so the immutability guarantee is also enforced at
 * the builder level here.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class ImmutableBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        throw ImmutableAuditException::cannotModify();
    }

    public function delete(): mixed
    {
        throw ImmutableAuditException::cannotModify();
    }

    public function forceDelete(): mixed
    {
        throw ImmutableAuditException::cannotModify();
    }

    /**
     * @param  array<int, array<string, mixed>>|array<string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        throw ImmutableAuditException::cannotModify();
    }
}
