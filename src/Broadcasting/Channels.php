<?php

declare(strict_types=1);

namespace Selli\Ticketing\Broadcasting;

/**
 * Single source of truth for the package's private channel names, so the
 * broadcast events, the authorization registration and any host code all agree
 * on the exact strings (including the configurable prefix).
 */
class Channels
{
    public static function prefix(): string
    {
        $prefix = trim((string) config('ticketing.broadcasting.channel_prefix', 'ticketing'), '.');

        return $prefix === '' ? '' : $prefix.'.';
    }

    /** The tenant-wide agent feed: every ticket change in the tenant. */
    public static function tenantTickets(int|string $tenantId): string
    {
        return self::prefix().'tenant.'.$tenantId.'.tickets';
    }

    /**
     * An individual agent's personal feed (their assigned tickets). The agent's
     * morph TYPE is part of the name, because assignee() is polymorphic: without
     * it a User#1 and an Admin#1 would share one channel and leak each other's
     * events.
     */
    public static function agent(int|string $tenantId, string $agentType, int|string $agentId): string
    {
        return self::prefix().'tenant.'.$tenantId.'.agent.'.self::token($agentType).'.'.$agentId;
    }

    /**
     * Collapse a value (typically a morph class) to the channel-name charset.
     * A morph FQCN has backslashes, which are invalid in a channel name; hosts
     * using a morph map get a clean alias instead. Stable on both the emit and
     * the authorization side, so the two always agree.
     */
    public static function token(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_-]+/', '-', $value) ?? $value;
    }

    /** A single ticket's watchers (participants + agents) — public events. */
    public static function ticket(int|string $ticketId): string
    {
        return self::prefix().'ticket.'.$ticketId;
    }

    /**
     * A single ticket's AGENT-only feed (internal notes, assignment, priority).
     * Distinct from ticket() so a requester watching the ticket never receives
     * agent-only events, and so agent-only events still have a channel on a
     * shared/single-tenant ticket that has no tenant feed.
     */
    public static function ticketAgents(int|string $ticketId): string
    {
        return self::prefix().'ticket.'.$ticketId.'.agents';
    }

    /**
     * The Broadcast::channel() route patterns, with {placeholders} Laravel binds
     * to the authorization callback arguments. Order matters: the more specific
     * ticket.{id}.agents must be registered before ticket.{id}.
     *
     * @return array{tenantTickets: string, agent: string, ticketAgents: string, ticket: string}
     */
    public static function patterns(): array
    {
        return [
            'tenantTickets' => self::prefix().'tenant.{tenantId}.tickets',
            'agent' => self::prefix().'tenant.{tenantId}.agent.{agentType}.{agentId}',
            'ticketAgents' => self::prefix().'ticket.{ticketId}.agents',
            'ticket' => self::prefix().'ticket.{ticketId}',
        ];
    }
}
