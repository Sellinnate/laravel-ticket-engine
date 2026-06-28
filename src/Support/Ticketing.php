<?php

declare(strict_types=1);

namespace Selli\Ticketing\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Actions\OpenTicket;
use Selli\Ticketing\Actions\PostMessage;
use Selli\Ticketing\Actions\TransitionTicket;
use Selli\Ticketing\Data\OpenTicketData;
use Selli\Ticketing\Data\PostMessageData;
use Selli\Ticketing\Data\TransitionData;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketActivity;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Models\TicketParticipant;
use Selli\Ticketing\Models\TicketType;

/**
 * The primary entry point to the domain. Resolved as a singleton and exposed
 * via the Ticketing facade. Heavy lifting lives in the Action classes so the
 * same operations stay invocable from jobs, commands and listeners.
 *
 * Model bindings are overridable the same way Cashier/Commerce allow it:
 * `Ticketing::useTicketModel(MyTicket::class)`.
 */
class Ticketing
{
    /** @var array<string, class-string> */
    protected static array $models = [];

    public function __construct(protected Container $container) {}

    /**
     * Open a new ticket. Prefer `for($subject)->open(...)` for subject tickets.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function open(
        string $type,
        string $title,
        mixed $requester = null,
        ?Priority $priority = null,
        mixed $subject = null,
        ?string $category = null,
        array $attributes = [],
    ): Ticket {
        return $this->container->make(OpenTicket::class)->handle(new OpenTicketData(
            type: $type,
            title: $title,
            requester: $requester,
            priority: $priority,
            subject: $subject,
            category: $category,
            attributes: $attributes,
        ));
    }

    /**
     * Begin a fluent operation against a subject (to open) or an existing
     * ticket (to act on it).
     */
    public function for(mixed $target): PendingTicket
    {
        return new PendingTicket($this, $target);
    }

    /**
     * Post a message to a ticket.
     *
     * @param  array<string, mixed>  $meta
     */
    public function postMessage(
        Ticket $ticket,
        mixed $author,
        string $body,
        MessageVisibility $visibility = MessageVisibility::Public,
        array $meta = [],
    ): TicketMessage {
        return $this->container->make(PostMessage::class)->handle(new PostMessageData(
            ticket: $ticket,
            author: $author,
            body: $body,
            visibility: $visibility,
            meta: $meta,
        ));
    }

    /**
     * Apply a workflow transition to a ticket.
     *
     * @param  array<string, mixed>  $params
     */
    public function transition(
        Ticket $ticket,
        string $transition,
        ?Model $actor = null,
        ?string $note = null,
        array $params = [],
    ): Ticket {
        return $this->container->make(TransitionTicket::class)->handle(new TransitionData(
            ticket: $ticket,
            transition: $transition,
            actor: $actor,
            note: $note,
            params: $params,
        ));
    }

    // --- Model binding -----------------------------------------------------

    public static function useTicketModel(string $model): void
    {
        static::$models['ticket'] = $model;
    }

    public static function useTicketTypeModel(string $model): void
    {
        static::$models['ticket_type'] = $model;
    }

    public static function useTicketMessageModel(string $model): void
    {
        static::$models['ticket_message'] = $model;
    }

    public static function useTicketParticipantModel(string $model): void
    {
        static::$models['ticket_participant'] = $model;
    }

    public static function useTicketActivityModel(string $model): void
    {
        static::$models['ticket_activity'] = $model;
    }

    /**
     * Enable ULID primary keys across the package tables.
     */
    public static function useUlids(bool $enabled = true): void
    {
        config(['ticketing.ids.type' => $enabled ? 'ulid' : 'auto']);
    }

    /**
     * Reset in-memory model overrides (used by the test suite).
     */
    public static function flushModelBindings(): void
    {
        static::$models = [];
    }

    /**
     * @return class-string<Ticket>
     */
    public static function ticketModel(): string
    {
        /** @var class-string<Ticket> */
        return static::$models['ticket'] ?? config('ticketing.models.ticket', Ticket::class);
    }

    /**
     * @return class-string<TicketType>
     */
    public static function ticketTypeModel(): string
    {
        /** @var class-string<TicketType> */
        return static::$models['ticket_type'] ?? config('ticketing.models.ticket_type', TicketType::class);
    }

    /**
     * @return class-string<TicketMessage>
     */
    public static function ticketMessageModel(): string
    {
        /** @var class-string<TicketMessage> */
        return static::$models['ticket_message'] ?? config('ticketing.models.ticket_message', TicketMessage::class);
    }

    /**
     * @return class-string<TicketParticipant>
     */
    public static function ticketParticipantModel(): string
    {
        /** @var class-string<TicketParticipant> */
        return static::$models['ticket_participant'] ?? config('ticketing.models.ticket_participant', TicketParticipant::class);
    }

    /**
     * @return class-string<TicketActivity>
     */
    public static function ticketActivityModel(): string
    {
        /** @var class-string<TicketActivity> */
        return static::$models['ticket_activity'] ?? config('ticketing.models.ticket_activity', TicketActivity::class);
    }

    /**
     * Instantiate a fresh instance of a bound model.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel
     */
    public static function make(string $model): object
    {
        return new $model;
    }
}
