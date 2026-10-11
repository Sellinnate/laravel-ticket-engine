<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mail;

use Closure;
use Illuminate\Contracts\Container\Container;
use Selli\Ticketing\Contracts\InboundMailbox;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;

/**
 * The mailboxes configured under `ticketing.mail.inbound.mailboxes`, each with
 * a driver (`imap` built in, others via extend()).
 */
class MailboxManager
{
    /**
     * @var array<string, Closure(Container, array<string, mixed>): InboundMailbox>
     */
    protected array $drivers = [];

    public function __construct(protected Container $container) {}

    /**
     * @param  Closure(Container, array<string, mixed>): InboundMailbox  $factory
     */
    public function extend(string $driver, Closure $factory): static
    {
        $this->drivers[$driver] = $factory;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $mailboxes = config('ticketing.mail.inbound.mailboxes', []);

        return is_array($mailboxes) ? array_values(array_filter(array_keys($mailboxes), 'is_string')) : [];
    }

    public function mailbox(string $name): InboundMailbox
    {
        $config = config('ticketing.mail.inbound.mailboxes.'.$name);

        if (! is_array($config)) {
            throw new InvalidConfigurationException("Unknown inbound mailbox [{$name}].");
        }

        /** @var array<string, mixed> $config */
        $driver = is_string($config['driver'] ?? null) ? $config['driver'] : 'imap';

        if (isset($this->drivers[$driver])) {
            return ($this->drivers[$driver])($this->container, $config);
        }

        if ($driver === 'imap') {
            return ImapMailbox::fromConfig($config);
        }

        throw new InvalidConfigurationException("Unknown inbound mailbox driver [{$driver}].");
    }
}
