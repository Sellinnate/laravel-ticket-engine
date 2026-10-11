<?php

declare(strict_types=1);

namespace Selli\Ticketing\Enums;

/**
 * The channel a ticket arrived through. The column is a plain string (at most
 * 32 characters), so a host may record its own channels (e.g. "whatsapp",
 * "phone"); these are the ones the package itself writes.
 */
enum TicketChannel: string
{
    /** Opened from the host application's own UI (the facade default). */
    case Web = 'web';

    /** Opened through the package REST API. */
    case Api = 'api';

    /** Opened by an inbound email. */
    case Email = 'email';

    /** Escalated from a conversation on an embedded website widget. */
    case Widget = 'widget';

    /** Escalated from a conversation in an in-app chat. */
    case Chat = 'chat';

    /** Opened by an agent tool (e.g. the MCP server). */
    case Agent = 'agent';
}
