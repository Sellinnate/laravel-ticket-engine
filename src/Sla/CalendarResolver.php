<?php

declare(strict_types=1);

namespace Selli\Ticketing\Sla;

use Illuminate\Support\Carbon;
use Selli\Ticketing\Models\BusinessHours as BusinessHoursModel;
use Selli\Ticketing\Models\SlaPolicy;
use Selli\Ticketing\Support\Ticketing;

/**
 * Builds a {@see BusinessHours} value object from a stored calendar + its
 * holidays (calendar-specific and tenant-global). A policy with no calendar is
 * treated as 24/7.
 */
class CalendarResolver
{
    public function forPolicy(?SlaPolicy $policy): BusinessHours
    {
        if ($policy === null || $policy->business_hours_id === null) {
            return BusinessHours::alwaysOpen($this->defaultTimezone());
        }

        $model = Ticketing::businessHoursModel();
        $calendar = $model::query()->find($policy->business_hours_id);

        return $this->forModel($calendar instanceof BusinessHoursModel ? $calendar : null);
    }

    public function forModel(?BusinessHoursModel $calendar): BusinessHours
    {
        if ($calendar === null) {
            return BusinessHours::alwaysOpen($this->defaultTimezone());
        }

        $holidayModel = Ticketing::holidayModel();

        /** @var list<string> $holidays */
        $holidays = $holidayModel::query()
            ->where(function ($query) use ($calendar): void {
                $query->whereNull('business_hours_id')->orWhere('business_hours_id', $calendar->getKey());
            })
            ->pluck('date')
            ->map(fn (Carbon $date): string => $date->format('Y-m-d'))
            ->values()
            ->all();

        return new BusinessHours($calendar->timezone, $calendar->schedule, $holidays);
    }

    protected function defaultTimezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }
}
