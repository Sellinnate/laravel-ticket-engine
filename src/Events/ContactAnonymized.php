<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Selli\Ticketing\Models\Contact;

/**
 * A contact's personal data was erased. Integrations holding copies (search
 * indexes, vector stores) listen to this to purge them.
 */
class ContactAnonymized implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Contact $contact) {}
}
