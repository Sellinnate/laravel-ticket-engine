<?php

declare(strict_types=1);

namespace Selli\Ticketing\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Contracts\CanActOnTickets;
use Selli\Ticketing\Contracts\CanRequestTickets;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Tenancy\TenantGuard;

/**
 * Default authorization for tickets (spec §17.1). Agnostic about the host's
 * permission system: "agent" and "requester" come from the package contracts.
 * Agents act on tickets in their tenant; a requester/participant may view and
 * reply to their own ticket; internal notes and state-changing actions are
 * agent-only. A host can register its own policy (authorization.register_policies
 * = false) or delegate from here to spatie/laravel-permission.
 */
class TicketPolicy
{
    public function __construct(protected TenantGuard $tenants) {}

    /** Listing is tenant-scoped by the global scope; anyone who can use the system may list. */
    public function viewAny(Authenticatable $user): bool
    {
        return $user instanceof CanActOnTickets || $user instanceof CanRequestTickets;
    }

    /** Opening a ticket: any requester or agent. */
    public function create(Authenticatable $user): bool
    {
        return $user instanceof CanActOnTickets || $user instanceof CanRequestTickets;
    }

    public function view(Authenticatable $user, Ticket $ticket): bool
    {
        return $this->isTenantAgent($user, $ticket) || $this->isOnTicket($user, $ticket);
    }

    /** Internal notes are never visible to a requester/participant — agents only. */
    public function viewInternal(Authenticatable $user, Ticket $ticket): bool
    {
        return $this->isTenantAgent($user, $ticket);
    }

    /** Public replies: agents and the ticket's own requester/participants. */
    public function comment(Authenticatable $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket);
    }

    /** Posting an INTERNAL note is agent-only. */
    public function commentInternal(Authenticatable $user, Ticket $ticket): bool
    {
        return $this->isTenantAgent($user, $ticket);
    }

    public function addAttachment(Authenticatable $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket);
    }

    public function transition(Authenticatable $user, Ticket $ticket): bool
    {
        return $this->isTenantAgent($user, $ticket);
    }

    public function assign(Authenticatable $user, Ticket $ticket): bool
    {
        return $this->isTenantAgent($user, $ticket);
    }

    public function changePriority(Authenticatable $user, Ticket $ticket): bool
    {
        return $this->isTenantAgent($user, $ticket);
    }

    public function merge(Authenticatable $user, Ticket $ticket): bool
    {
        return $this->isTenantAgent($user, $ticket);
    }

    public function split(Authenticatable $user, Ticket $ticket): bool
    {
        return $this->isTenantAgent($user, $ticket);
    }

    public function submitCsat(Authenticatable $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket);
    }

    public function delete(Authenticatable $user, Ticket $ticket): bool
    {
        return $this->isTenantAgent($user, $ticket);
    }

    /** An agent (CanActOnTickets) that belongs to the ticket's tenant. */
    protected function isTenantAgent(Authenticatable $user, Ticket $ticket): bool
    {
        return $user instanceof CanActOnTickets
            && $user instanceof Model
            && $this->tenants->belongsToTicketTenant($user, $ticket);
    }

    /** The ticket's subject (the for() target) or an explicit participant. */
    protected function isOnTicket(Authenticatable $user, Ticket $ticket): bool
    {
        if (! $user instanceof Model) {
            return false;
        }

        if ($ticket->subject_type === $user->getMorphClass() && (string) $ticket->subject_id === (string) $user->getKey()) {
            return true;
        }

        return $ticket->participants()->withoutTenancy()
            ->where('participant_type', $user->getMorphClass())
            ->where('participant_id', $user->getKey())
            ->exists();
    }
}
