<?php

declare(strict_types=1);

namespace Selli\Ticketing\Exceptions;

use RuntimeException;

/**
 * Base type for all domain errors raised by the ticketing engine.
 */
class TicketingException extends RuntimeException {}
