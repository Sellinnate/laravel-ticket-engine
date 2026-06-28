<?php

declare(strict_types=1);

namespace Selli\Ticketing\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Contracts\CanActOnTickets;
use Selli\Ticketing\Contracts\CanRequestTickets;
use Selli\Ticketing\Contracts\ReportsAvailability;

/**
 * A test user that can be both a requester and an agent — exercising the dual
 * contract case on a single model.
 *
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property int|null $tenant_id
 * @property bool $available
 */
class TestUser extends Model implements Authenticatable, CanActOnTickets, CanRequestTickets, ReportsAvailability
{
    protected $table = 'users';

    protected $guarded = [];

    protected $casts = ['available' => 'boolean'];

    public function isAvailableForTickets(): bool
    {
        // Default to available when the column is not set.
        return $this->available ?? true;
    }

    public function requesterLabel(): string
    {
        return $this->name;
    }

    public function requesterEmail(): ?string
    {
        return $this->email;
    }

    public function agentLabel(): string
    {
        return $this->name;
    }

    public function agentEmail(): ?string
    {
        return $this->email;
    }

    // --- Authenticatable -------------------------------------------------

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }
}
