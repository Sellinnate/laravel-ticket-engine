<?php

declare(strict_types=1);

namespace Selli\Ticketing\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Exceptions\TicketingException;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\Ticket;

/**
 * Seeds one working example ticket — a reply and an internal note — so a
 * developer can see the engine produce a real reference and conversation
 * within minutes of installing (spec §18, time-to-first-ticket).
 */
class DemoCommand extends Command
{
    protected $signature = 'ticketing:demo {--type= : The ticket type key to use (defaults to the first configured type)}';

    protected $description = 'Seed a working example ticket so you can see the engine in action.';

    public function handle(): int
    {
        try {
            $type = $this->resolveType();

            // One transaction, so a failure mid-seed leaves no half-built demo.
            $ticket = DB::transaction(function () use ($type): Ticket {
                $ticket = Ticketing::open(type: $type, title: 'Welcome to your ticketing engine');
                Ticketing::for($ticket)->postMessage(null, 'This ticket was created by `php artisan ticketing:demo`. Reply to it, transition it, assign it — the engine handles the rest.');
                Ticketing::for($ticket)->postMessage(null, 'Internal note: only agents (CanActOnTickets) see this.', MessageVisibility::Internal);

                return $ticket;
            });
        } catch (TicketingException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Created demo ticket {$ticket->reference} (type: {$type}, status: {$ticket->status}).");
        $this->line('  - 1 public reply and 1 internal note posted.');
        $this->line('  - Try: Ticketing::for($ticket)->transition(\'resolve\') or open the REST API.');

        return self::SUCCESS;
    }

    protected function resolveType(): string
    {
        $option = $this->option('type');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        /** @var array<string, mixed> $types */
        $types = (array) config('ticketing.types', []);
        $type = array_key_first($types);

        if (! is_string($type) || $type === '') {
            // Fail on the real problem (no types configured) instead of a
            // misleading "unknown type" later on a guessed default.
            throw new InvalidConfigurationException('No ticket types are configured — set ticketing.types or pass --type.');
        }

        return $type;
    }
}
