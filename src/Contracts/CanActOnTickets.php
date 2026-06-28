<?php

declare(strict_types=1);

namespace Selli\Ticketing\Contracts;

/**
 * An actor that can work tickets (an operator, a technician, a team member).
 *
 * The same host model may implement both this and {@see CanRequestTickets}, or
 * the two roles may live on different models entirely.
 */
interface CanActOnTickets
{
    /**
     * A human readable label for this agent, used in notifications/audit.
     */
    public function agentLabel(): string;

    /**
     * The primary email of the agent, if any.
     */
    public function agentEmail(): ?string;
}
