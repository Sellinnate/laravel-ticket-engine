<?php

declare(strict_types=1);

namespace Selli\Ticketing\Broadcasting;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Contracts\CanActOnTickets;
use Selli\Ticketing\Contracts\ChannelAuthorizer;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Tenancy\TenantContext;

/**
 * Default, tenant-scoped channel authorization. Fails closed: any user that is
 * not provably entitled (wrong tenant, not an agent, not on the ticket) is
 * denied. A host can bind its own {@see ChannelAuthorizer} to delegate to its
 * policies instead.
 */
class DefaultChannelAuthorizer implements ChannelAuthorizer
{
    public function forTenantTickets(Authenticatable $user, int|string $tenantId): bool
    {
        // The tenant-wide feed is agent-facing: only agents of that tenant.
        return $user instanceof CanActOnTickets && $this->tenantMatches($user, $tenantId);
    }

    public function forAgent(Authenticatable $user, int|string $tenantId, string $agentType, int|string $agentId): bool
    {
        // The agent feed names the assignee's morph TYPE as well as its id, so a
        // User#1 can't reach an Admin#1 feed. Match the connecting user's own
        // (tokenised) morph type and id.
        return $user instanceof CanActOnTickets
            && $user instanceof Model
            && $this->tenantMatches($user, $tenantId)
            && Channels::token($user->getMorphClass()) === $agentType
            && (string) $user->getAuthIdentifier() === (string) $agentId;
    }

    public function forTicket(Authenticatable $user, int|string $ticketId): bool
    {
        // Load WITHOUT the tenant scope, then enforce the tenant explicitly. The
        // scope keys off the resolver's tenant (the user's tenant_id); a legit
        // requester with a null tenant — or single-tenant mode — would otherwise
        // never see a tenant-scoped row and be wrongly denied. The checks below
        // bind the user to the ticket, so loading unscoped leaks nothing.
        $ticket = Ticketing::ticketModel()::withoutTenancy()->find($ticketId);

        if (! $ticket instanceof Ticket) {
            return false;
        }

        // Any agent of the ticket's tenant may watch it; otherwise the user must
        // be on the ticket (its subject/requester or an explicit participant).
        if ($user instanceof CanActOnTickets && $this->belongsToTicketTenant($user, $ticket)) {
            return true;
        }

        return $this->isSubject($user, $ticket) || $this->isParticipant($user, $ticket);
    }

    public function forTicketAgents(Authenticatable $user, int|string $ticketId): bool
    {
        // Agent-only feed: the connecting user must be an agent of the ticket's
        // tenant. Loaded unscoped (see forTicket), gated by belongsToTicketTenant
        // (honours allow_shared). A dual-role model that is the ticket's REQUESTER
        // or subject (the customer side) is excluded even if it also implements
        // CanActOnTickets — but an assignee/collaborator agent (also a participant)
        // is NOT, since they legitimately work the ticket.
        if (! $user instanceof CanActOnTickets) {
            return false;
        }

        $ticket = Ticketing::ticketModel()::withoutTenancy()->find($ticketId);

        if (! $ticket instanceof Ticket || ! $this->belongsToTicketTenant($user, $ticket)) {
            return false;
        }

        // The assignee always gets the agent feed — even a dual-role user who
        // opened the ticket and then self-assigned. On a shared ticket this is
        // their only agent-event channel, so the requester carve-out must not
        // lock the person working the ticket out of it.
        if ($this->isAssignee($user, $ticket)) {
            return true;
        }

        // Otherwise the customer side (subject/requester) is excluded.
        return ! $this->isSubject($user, $ticket) && ! $this->isRequester($user, $ticket);
    }

    protected function tenantMatches(Authenticatable $user, int|string $tenantId): bool
    {
        // Single-tenant mode (tenancy disabled) is a supported setup with no
        // tenant to match on — role/identity alone decides, so don't hard-deny.
        if (! app(TenantContext::class)->enabled()) {
            return true;
        }

        $userTenant = $this->userTenant($user);

        // A null-tenant (shared) user is never entitled to a specific tenant feed.
        return $userTenant !== null && (string) $userTenant === (string) $tenantId;
    }

    protected function belongsToTicketTenant(Authenticatable $user, Ticket $ticket): bool
    {
        $context = app(TenantContext::class);

        // When tenancy is off there is no tenant to match on — agents pass.
        if (! $context->enabled()) {
            return true;
        }

        $tenantId = $ticket->getAttribute($ticket->getTenantColumn());

        // A shared (null-tenant) ticket is reachable only when sharing is enabled
        // — with allow_shared off it's hidden from scoped reads, so an agent who
        // merely knows the id must not subscribe to its channel either.
        if ($tenantId === null) {
            return $context->allowsShared();
        }

        return $this->tenantMatches($user, $tenantId);
    }

    protected function isSubject(Authenticatable $user, Ticket $ticket): bool
    {
        return $user instanceof Model
            && $ticket->subject_type === $user->getMorphClass()
            && (string) $ticket->subject_id === (string) $user->getKey();
    }

    protected function isAssignee(Authenticatable $user, Ticket $ticket): bool
    {
        return $user instanceof Model
            && $ticket->assignee_type === $user->getMorphClass()
            && $ticket->assignee_id !== null
            && (string) $ticket->assignee_id === (string) $user->getKey();
    }

    protected function isRequester(Authenticatable $user, Ticket $ticket): bool
    {
        if (! $user instanceof Model) {
            return false;
        }

        return $ticket->participants()->withoutTenancy()
            ->where('role', ParticipantRole::Requester->value)
            ->where('participant_type', $user->getMorphClass())
            ->where('participant_id', $user->getKey())
            ->exists();
    }

    protected function isParticipant(Authenticatable $user, Ticket $ticket): bool
    {
        if (! $user instanceof Model) {
            return false;
        }

        // withoutTenancy for the same reason as forTicket: the participant rows
        // carry the ticket's tenant, which a null current-tenant scope would hide.
        // Scoping by ticket_id (the relation) + the user's morph keys is enough.
        return $ticket->participants()->withoutTenancy()
            ->where('participant_type', $user->getMorphClass())
            ->where('participant_id', $user->getKey())
            ->exists();
    }

    protected function userTenant(Authenticatable $user): int|string|null
    {
        if (! $user instanceof Model) {
            return null;
        }

        $column = app(TenantContext::class)->column();

        /** @var int|string|null $value */
        $value = $user->getAttribute($column);

        return $value;
    }
}
