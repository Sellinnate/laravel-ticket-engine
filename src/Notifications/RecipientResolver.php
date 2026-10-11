<?php

declare(strict_types=1);

namespace Selli\Ticketing\Notifications;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Models\Ticket;

/**
 * Resolves the host models to notify for a ticket. Agnostic about the user model
 * (it follows the polymorphic assignee/participant relations); the resolved
 * models are whatever the host attached, which carry the Notifiable trait.
 *
 * @internal
 */
class RecipientResolver
{
    public function assignee(Ticket $ticket): ?Model
    {
        return $ticket->assignee;
    }

    /**
     * The notifiable participant models for a ticket (notify=true), optionally
     * filtered to roles and excluding one actor (e.g. a reply's own author).
     *
     * @param  list<string>  $roles
     * @return list<Model>
     */
    public function participants(Ticket $ticket, array $roles = [], ?Model $except = null): array
    {
        $query = $ticket->participants()->withoutTenancy()->where('notify', true);

        if ($roles !== []) {
            $query->whereIn('role', $roles);
        }

        $out = [];
        $seen = [];

        // Eager-load every participant's host model in one query per morph type
        // instead of one lazy load per participant.
        foreach ($query->with('participant')->get() as $participant) {
            $model = $participant->participant;

            if (! $model instanceof Model || $this->same($model, $except)) {
                continue;
            }

            $signature = $model::class.':'.$model->getKey();

            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $out[] = $model;
        }

        return $out;
    }

    protected function same(Model $model, ?Model $other): bool
    {
        return $other !== null
            && $model::class === $other::class
            && (string) $model->getKey() === (string) $other->getKey();
    }
}
