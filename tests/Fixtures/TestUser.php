<?php

declare(strict_types=1);

namespace Selli\Ticketing\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Contracts\CanActOnTickets;
use Selli\Ticketing\Contracts\CanRequestTickets;

/**
 * A test user that can be both a requester and an agent — exercising the dual
 * contract case on a single model.
 *
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property int|null $tenant_id
 */
class TestUser extends Model implements Authenticatable, CanActOnTickets, CanRequestTickets
{
    protected $table = 'users';

    protected $guarded = [];

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
