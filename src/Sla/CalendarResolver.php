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
        return $this->forBusinessHoursId($policy?->business_hours_id);
    }

    /**
     * Build the working calendar for a stored business-hours id (null = 24/7).
     */
    public function forBusinessHoursId(int|string|null $id): BusinessHours
    {
        if ($id === null) {
            return BusinessHours::alwaysOpen($this->defaultTimezone());
        }

        $model = Ticketing::businessHoursModel();
        $calendar = $model::query()->withoutTenancy()->find($id);

        return $this->forModel($calendar instanceof BusinessHoursModel ? $calendar : null);
    }

    public function forModel(?BusinessHoursModel $calendar): BusinessHours
    {
        if ($calendar === null) {
            return BusinessHours::alwaysOpen($this->defaultTimezone());
        }

        $holidayModel = Ticketing::holidayModel();
        $tenantColumn = $calendar->getTenantColumn();
        $tenantValue = $calendar->getAttribute($tenantColumn);

        // Resolve holidays without the ambient tenant scope (the calendar was
        // loaded unscoped too): scope explicitly to the calendar's own tenant
        // plus shared (null-tenant) holidays, so a CLI/queue run with no resolved
        // tenant still includes tenant-specific holidays.
        /** @var list<string> $holidays */
        $holidays = $holidayModel::query()
            ->withoutTenancy()
            ->where(function ($query) use ($calendar): void {
                $query->whereNull('business_hours_id')->orWhere('business_hours_id', $calendar->getKey());
            })
            ->where(function ($query) use ($tenantColumn, $tenantValue): void {
                $query->where($tenantColumn, $tenantValue)->orWhereNull($tenantColumn);
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
