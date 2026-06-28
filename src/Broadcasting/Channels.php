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

    /** An individual agent's personal feed (their assigned tickets). */
    public static function agent(int|string $tenantId, int|string $agentId): string
    {
        return self::prefix().'tenant.'.$tenantId.'.agent.'.$agentId;
    }

    /** A single ticket's watchers (participants + agents). */
    public static function ticket(int|string $ticketId): string
    {
        return self::prefix().'ticket.'.$ticketId;
    }

    /**
     * The Broadcast::channel() route patterns, with {placeholders} Laravel binds
     * to the authorization callback arguments.
     *
     * @return array{tenantTickets: string, agent: string, ticket: string}
     */
    public static function patterns(): array
    {
        return [
            'tenantTickets' => self::prefix().'tenant.{tenantId}.tickets',
            'agent' => self::prefix().'tenant.{tenantId}.agent.{agentId}',
            'ticket' => self::prefix().'ticket.{ticketId}',
        ];
    }
}
