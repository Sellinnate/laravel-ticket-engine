<?php

declare(strict_types=1);

namespace Selli\Ticketing\Tests;

/**
 * Boots the package with broadcasting enabled (on the no-op "null" broadcaster),
 * so the provider actually registers the BroadcastSubscriber and the private
 * channels. Bound to the broadcasting feature test via uses() in that file.
 */
class BroadcastingTestCase extends TestCase
{
    public function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        config()->set('ticketing.broadcasting.enabled', true);

        // Resolve broadcasts against a driver that does nothing, so dispatching a
        // ShouldBroadcast event never reaches a real Reverb/Pusher connection, and
        // run the queued broadcast inline so it can't fail on a missing worker.
        config()->set('broadcasting.default', 'null');
        config()->set('broadcasting.connections.null', ['driver' => 'null']);
        config()->set('queue.default', 'sync');
    }
}
