<?php

declare(strict_types=1);

namespace Selli\Ticketing\Exceptions;

/**
 * Raised at boot when the package configuration is internally inconsistent
 * (e.g. a workflow references a state that is not declared). Fail-fast.
 */
class InvalidConfigurationException extends TicketingException {}
