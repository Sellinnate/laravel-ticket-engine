<?php

declare(strict_types=1);

namespace Selli\Ticketing\Commands;

use Illuminate\Console\Command;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Mail\InboundEmail;
use Selli\Ticketing\Mail\MailboxManager;
use Selli\Ticketing\Support\Ticketing;

/**
 * Polls the configured inbound mailboxes (ticketing.mail.inbound.mailboxes)
 * and ingests their new messages like the inbound webhook would. Idempotent:
 * a message is ingested once (Message-ID claim) and marked processed after.
 *
 * @internal
 */
class PollMailCommand extends Command
{
    protected $signature = 'ticketing:mail:poll
        {mailbox?* : Mailbox names (default: every configured mailbox)}
        {--limit=50 : Messages per mailbox and run}';

    protected $description = 'Ingest new email from the configured inbound mailboxes (IMAP).';

    public function handle(MailboxManager $mailboxes, Ticketing $ticketing): int
    {
        // Disabled ingestion would drop every message and still mark it read.
        if (config('ticketing.mail.inbound.enabled', false) !== true) {
            $this->error('Inbound email is disabled (ticketing.mail.inbound.enabled).');

            return self::FAILURE;
        }

        /** @var list<string> $requested */
        $requested = (array) $this->argument('mailbox');
        $names = $requested !== [] ? $requested : $mailboxes->names();

        if ($names === []) {
            $this->info('No inbound mailboxes configured.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $status = self::SUCCESS;

        foreach ($names as $name) {
            try {
                $count = $mailboxes->mailbox($name)->poll(fn (InboundEmail $email) => $ticketing->receiveEmail($email), $limit);
                $this->line(sprintf('  %s — %d message(s)', $name, $count));
            } catch (InvalidConfigurationException $exception) {
                $this->error($exception->getMessage());
                $status = self::FAILURE;
            } catch (\Throwable $exception) {
                // One unreachable server must not stop the other mailboxes.
                report($exception);
                $this->error(sprintf('  %s — %s', $name, $exception->getMessage()));
                $status = self::FAILURE;
            }
        }

        return $status;
    }
}
