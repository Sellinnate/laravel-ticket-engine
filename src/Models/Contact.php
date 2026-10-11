<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Contracts\CanRequestTickets;
use Selli\Ticketing\Database\Factories\ContactFactory;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;

/**
 * An external requester with no account in the host app: an email sender, or a
 * widget visitor who left a name and an email. De-duplicated per tenant on the
 * normalised email (see ContactDirectory::remember()), notifiable by mail, and
 * usable anywhere a requester or participant model is.
 *
 * @property int|string $id
 * @property int|string|null $tenant_id
 * @property string|null $email
 * @property string|null $dedupe_key
 * @property string|null $name
 * @property string|null $phone
 * @property string|null $locale
 * @property string|null $user_type
 * @property int|string|null $user_id
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $verified_at
 * @property Carbon|null $anonymized_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Contact extends Model implements CanRequestTickets
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    use Notifiable;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'contacts';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'verified_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ContactFactory
    {
        return ContactFactory::new();
    }

    /**
     * The host model this contact is linked to, if any (e.g. the user it became).
     *
     * @return MorphTo<Model, $this>
     */
    public function user(): MorphTo
    {
        return $this->morphTo();
    }

    public function requesterLabel(): string
    {
        return $this->name ?? $this->email ?? 'Contact #'.$this->getKey();
    }

    public function requesterEmail(): ?string
    {
        return $this->email;
    }

    /**
     * Mail notifications go to the contact's address (none once anonymised).
     */
    public function routeNotificationForMail(): ?string
    {
        return $this->email;
    }

    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
