<?php

declare(strict_types=1);

namespace Selli\Ticketing\Tests;

/**
 * Boots the widget API behind the host's real "api" middleware group (with
 * SubstituteBindings), the default configuration, to check middleware order.
 */
class WidgetApiGroupTestCase extends TestCase
{
    public function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        config()->set('ticketing.widget.middleware', ['api']);
        config()->set('ticketing.widget.rate_limits.per_ip', '2,1');
    }
}
