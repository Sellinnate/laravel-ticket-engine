<?php

declare(strict_types=1);

namespace Selli\Ticketing\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Selli\Ticketing\Contracts\CanRequestTickets;

/**
 * A requester-only user: it can open and follow tickets but is NOT an agent
 * (no CanActOnTickets), so it can't post internal notes or be assigned.
 *
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property int|null $tenant_id
 */
class TestRequester extends Model implements Authenticatable, CanRequestTickets
{
    use Notifiable;

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
