<?php

declare(strict_types=1);

namespace Selli\Ticketing\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Concerns\HasTickets;
use Selli\Ticketing\Contracts\Ticketable;

/**
 * A host model that can be the subject of tickets, exercising the agnostic
 * subject case.
 *
 * @property int $id
 * @property string $number
 * @property int|null $tenant_id
 */
class TestOrder extends Model implements Ticketable
{
    use HasTickets;

    protected $table = 'orders';

    protected $guarded = [];

    // Intentionally relies on the HasTickets default ticketableLabel().
}
