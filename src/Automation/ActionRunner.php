<?php

declare(strict_types=1);

namespace Selli\Ticketing\Automation;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Jobs\DeliverWebhook;
use Selli\Ticketing\Models\Macro;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\Ticketing;

/**
 * Runs a rule's data-driven actions against a ticket. Action types are
 * allow-listed; an unknown type throws (fail closed). Each action reuses the
 * audited, tenant-safe domain Actions via the Ticketing manager, so automation
 * is no different from a manual operation.
 */
class ActionRunner
{
    public function __construct(protected Ticketing $manager) {}

    /**
     * @param  array<int, array<string, mixed>>  $actions
     */
    public function run(Ticket $ticket, array $actions, ?Model $actor, string $eventKey): void
    {
        foreach ($actions as $action) {
            $this->runOne($ticket->fresh() ?? $ticket, $action, $actor, $eventKey);
        }
    }

    /**
     * @param  array<string, mixed>  $action
     */
    protected function runOne(Ticket $ticket, array $action, ?Model $actor, string $eventKey): void
    {
        $type = is_string($action['type'] ?? null) ? $action['type'] : '';

        match ($type) {
            'transition' => $this->manager->transition($ticket, (string) ($action['transition'] ?? ''), $actor, $this->stringOrNull($action['note'] ?? null)),
            'assign' => $this->assign($ticket, $action, $actor),
            'tag' => $this->manager->tag($ticket, array_values(array_map('strval', (array) ($action['tags'] ?? [])))),
            'reply' => $this->reply($ticket, $action, $actor),
            'priority' => $this->manager->changePriority($ticket, $this->priority($action['value'] ?? null), $actor),
            'apply_macro' => $this->applyMacro($ticket, $action, $actor),
            'webhook' => $this->webhook($ticket, $action, $eventKey),
            default => throw new InvalidConfigurationException("Unknown automation action type [{$type}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $action
     */
    protected function assign(Ticket $ticket, array $action, ?Model $actor): void
    {
        if (empty($action['team_id'])) {
            throw new InvalidConfigurationException('Automation assign action requires a team_id.');
        }

        $team = Ticketing::teamModel()::query()->withoutTenancy()->find($action['team_id']);

        if (! $team instanceof Team || ! $team->is_active) {
            throw new InvalidConfigurationException("Automation references an unknown or inactive team [{$action['team_id']}].");
        }

        $this->manager->assign($ticket, team: $team, strategy: $this->stringOrNull($action['strategy'] ?? null), actor: $actor);
    }

    /**
     * @param  array<string, mixed>  $action
     */
    protected function reply(Ticket $ticket, array $action, ?Model $actor): void
    {
        $body = $action['body'] ?? null;

        if (! is_string($body) || $body === '') {
            throw new InvalidConfigurationException('Automation reply action requires a non-empty string body.');
        }

        $visibility = MessageVisibility::tryFrom((string) ($action['visibility'] ?? 'public'))
            ?? throw new InvalidConfigurationException('Automation reply has an invalid visibility ['.(string) ($action['visibility'] ?? '').'].');

        $this->manager->postMessage($ticket, $actor, $body, $visibility);
    }

    /**
     * @param  array<string, mixed>  $action
     */
    protected function applyMacro(Ticket $ticket, array $action, ?Model $actor): void
    {
        $macro = Ticketing::macroModel()::query()->withoutTenancy()->find($action['macro_id'] ?? null);

        if (! $macro instanceof Macro) {
            throw new InvalidConfigurationException("Automation references an unknown macro [{$this->stringOrNull($action['macro_id'] ?? null)}].");
        }

        $this->manager->applyMacro($ticket, $macro, $actor);
    }

    /**
     * @param  array<string, mixed>  $action
     */
    protected function webhook(Ticket $ticket, array $action, string $eventKey): void
    {
        $url = $action['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new InvalidConfigurationException('Automation webhook action requires a string url.');
        }

        DeliverWebhook::dispatch(
            $url,
            [
                'event' => $eventKey,
                'ticket' => [
                    'id' => $ticket->getKey(),
                    'reference' => $ticket->reference,
                    'status' => $ticket->status,
                    'priority' => $ticket->priority->value,
                ],
            ],
            $this->stringOrNull($action['secret'] ?? null),
        );
    }

    protected function priority(mixed $value): Priority
    {
        // Accept the integer weight, a numeric string ("30"), or the case name
        // ("urgent"), since rule JSON commonly stores any of these.
        if (is_int($value) || (is_string($value) && is_numeric($value))) {
            $byValue = Priority::tryFrom((int) $value);

            if ($byValue !== null) {
                return $byValue;
            }
        }

        if (is_string($value)) {
            foreach (Priority::cases() as $case) {
                if (strtolower($case->name) === strtolower($value)) {
                    return $case;
                }
            }
        }

        throw new InvalidConfigurationException('Automation priority action has an invalid value.');
    }

    protected function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
