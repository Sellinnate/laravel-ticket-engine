<?php

declare(strict_types=1);

namespace Selli\Ticketing\Contracts;

use Closure;
use Selli\Ticketing\Mail\InboundEmail;

/**
 * A mailbox the package polls for inbound email (`ticketing:mail:poll`), for
 * hosts without an inbound webhook. The package ships an IMAP implementation
 * (directorytree/imapengine); register others with `MailboxManager::extend()`.
 */
interface InboundMailbox
{
    /**
     * Hand up to $limit unprocessed messages, oldest first, to $handler and
     * mark each one processed (moved or flagged) once the handler returns. A
     * message whose handler throws stays unprocessed for the next poll.
     *
     * @param  Closure(InboundEmail): mixed  $handler
     * @return int the messages processed
     */
    public function poll(Closure $handler, int $limit = 50): int;
}
