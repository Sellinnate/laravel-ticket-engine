<?php

declare(strict_types=1);

namespace Selli\Ticketing\Contacts;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Enums\ConversationRole;
use Selli\Ticketing\Events\ContactAnonymized;
use Selli\Ticketing\Gdpr\ExportRequesterData;
use Selli\Ticketing\Models\Contact;
use Selli\Ticketing\Models\Conversation;
use Selli\Ticketing\Models\ConversationMessage;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Tenancy\TenantContext;

/**
 * Finds and creates external contacts, de-duplicated per tenant on the
 * normalised email, and handles their GDPR export and erasure.
 *
 * `Ticketing::contacts()->remember('jane@example.com', 'Jane')` returns the same
 * Contact for the same tenant and address however many times — and however
 * concurrently — it is called.
 */
class ContactDirectory
{
    public function __construct(
        protected TenantContext $tenant,
        protected Container $container,
    ) {}

    /**
     * Lower-cased, trimmed email, or null when it is not a valid address.
     */
    public static function normaliseEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = strtolower(trim($email));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /**
     * The contact for this address in the current tenant, created on first use.
     * A name (or phone/locale) is filled in when the stored contact has none —
     * an existing value is never overwritten by a later, possibly spoofed, one.
     *
     * @param  array{phone?: string|null, locale?: string|null, meta?: array<string, mixed>}  $attributes
     *
     * @throws \InvalidArgumentException when the email is not a valid address
     */
    public function remember(string $email, ?string $name = null, array $attributes = []): Contact
    {
        $normalised = self::normaliseEmail($email)
            ?? throw new \InvalidArgumentException('A contact needs a valid email address.');

        $model = Ticketing::contactModel();
        $tenant = $this->tenant->current();
        $name = $name !== null && trim($name) !== '' ? mb_substr(trim($name), 0, 255) : null;

        // createOrFirst is race-safe against the unique dedupe_key: two
        // concurrent first messages from the same sender resolve to one row.
        /** @var Contact $contact */
        $contact = $model::query()->withoutTenancy()->createOrFirst(
            ['dedupe_key' => $this->dedupeKey($tenant, $normalised)],
            array_filter([
                $this->tenant->column() => $tenant,
                'email' => $normalised,
                'name' => $name,
                'phone' => $attributes['phone'] ?? null,
                'locale' => $attributes['locale'] ?? null,
                'meta' => $attributes['meta'] ?? null,
            ], fn (mixed $value): bool => $value !== null),
        );

        $missing = array_filter([
            'name' => $contact->name === null ? $name : null,
            'phone' => $contact->phone === null ? ($attributes['phone'] ?? null) : null,
            'locale' => $contact->locale === null ? ($attributes['locale'] ?? null) : null,
        ], fn (mixed $value): bool => $value !== null);

        if ($missing !== [] && ! $contact->wasRecentlyCreated) {
            $contact->forceFill($missing)->save();
        }

        return $contact;
    }

    /**
     * The contact for this address in the current tenant, if any.
     */
    public function find(string $email): ?Contact
    {
        $normalised = self::normaliseEmail($email);

        if ($normalised === null) {
            return null;
        }

        $contact = Ticketing::contactModel()::query()->withoutTenancy()
            ->where('dedupe_key', $this->dedupeKey($this->tenant->current(), $normalised))
            ->first();

        return $contact instanceof Contact ? $contact : null;
    }

    /**
     * Mark a contact as verified (e.g. after a signed widget identity).
     */
    public function markVerified(Contact $contact): Contact
    {
        if ($contact->verified_at === null) {
            $contact->forceFill(['verified_at' => now()])->save();
        }

        return $contact;
    }

    /**
     * Erase a contact's personal data (GDPR right to erasure): the tickets it
     * requested keep their statistics, but the stored mail metadata, the AI
     * question excerpts and the contact's own name, email and phone are
     * scrubbed. Returns the number of tickets touched.
     */
    public function anonymize(Contact $contact): int
    {
        $tickets = $this->container->make(Ticketing::class)->anonymiseRequester($contact);

        return $tickets;
    }

    /**
     * Scrub the contact row itself (called by the requester anonymisation).
     *
     * @internal
     */
    public function scrub(Contact $contact): void
    {
        $label = (string) config('ticketing.gdpr.anonymized_label', '[anonymized]');

        DB::transaction(function () use ($contact, $label): void {
            $contact->forceFill([
                'email' => null,
                'dedupe_key' => null,
                'name' => $label,
                'phone' => null,
                'meta' => null,
                'anonymized_at' => now(),
            ])->save();

            $this->scrubConversations(
                Ticketing::conversationModel()::query()->withoutTenancy()
                    ->where('contact_id', $contact->getKey())
                    ->pluck((new (Ticketing::conversationModel()))->getKeyName())
                    ->all(),
                $label,
            );
        });

        ContactAnonymized::dispatch($contact);
    }

    /**
     * Blank the visitor's words in these conversations — their messages, the
     * AI's question excerpts — and drop the network traces (origin, IP hash).
     * The conversations themselves stay for the statistics.
     *
     * @param  array<mixed>  $conversationIds
     *
     * @internal
     */
    public function scrubConversations(array $conversationIds, string $label): void
    {
        if ($conversationIds === []) {
            return;
        }

        Ticketing::conversationMessageModel()::query()->withoutTenancy()
            ->whereIn('conversation_id', $conversationIds)
            ->where('role', ConversationRole::Visitor->value)
            ->update(['body' => $label]);

        Ticketing::conversationModel()::query()->withoutTenancy()
            ->whereIn((new (Ticketing::conversationModel()))->getKeyName(), $conversationIds)
            ->update(['ip_hash' => null, 'origin' => null]);

        Ticketing::aiTurnModel()::query()->withoutTenancy()
            ->whereIn('conversation_id', $conversationIds)
            ->update(['question' => null]);
    }

    /**
     * A data-subject export for a contact: identity, tickets (public
     * conversation and ratings) and widget conversations.
     *
     * @return array{contact: array<string, mixed>, tickets: list<array<string, mixed>>, conversations: list<array<string, mixed>>}
     */
    public function export(Contact $contact): array
    {
        $conversations = Ticketing::conversationModel()::query()->withoutTenancy()
            ->where('contact_id', $contact->getKey())
            ->with(['messages' => fn ($query) => $query->withoutTenancy()->whereIn('role', [
                ConversationRole::Visitor->value,
                ConversationRole::Assistant->value,
                ConversationRole::Agent->value,
            ])->orderBy('created_at')])
            ->orderBy('created_at')
            ->get();

        return [
            'contact' => [
                'name' => $contact->name,
                'email' => $contact->email,
                'phone' => $contact->phone,
                'locale' => $contact->locale,
                'created_at' => $contact->created_at?->toIso8601String(),
            ],
            'tickets' => $this->container->make(ExportRequesterData::class)->handle($contact),
            'conversations' => array_values($conversations->map(fn (Conversation $conversation): array => [
                'id' => $conversation->public_id,
                'channel' => $conversation->channel,
                'status' => $conversation->status->value,
                'created_at' => $conversation->created_at?->toIso8601String(),
                'messages' => array_values($conversation->messages->map(fn (ConversationMessage $message): array => [
                    'role' => $message->role->value,
                    'body' => $message->body,
                    'created_at' => $message->created_at?->toIso8601String(),
                ])->all()),
            ])->all()),
        ];
    }

    /**
     * Whether a host model is a package contact.
     */
    public static function isContact(?Model $model): bool
    {
        return $model instanceof Contact;
    }

    protected function dedupeKey(int|string|null $tenant, string $email): string
    {
        return hash('sha256', ($tenant === null ? 'shared' : 'tenant:'.$tenant).'|'.$email);
    }
}
