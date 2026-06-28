<?php

declare(strict_types=1);

namespace Selli\Ticketing\Sla;

use Illuminate\Support\Carbon;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Models\BusinessHours as BusinessHoursModel;
use Selli\Ticketing\Models\SlaPolicy;
use Selli\Ticketing\Support\Ticketing;

/**
 * Builds a {@see BusinessHours} value object from a stored calendar + its
 * holidays. A policy with no calendar is treated as 24/7; a policy that
 * references a calendar which cannot be found fails loudly rather than silently
 * degrading to 24/7 (which would move deadlines earlier).
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

        if (! $calendar instanceof BusinessHoursModel) {
            // A referenced-but-missing calendar is a data-integrity problem; do
            // not silently turn a calendared SLA into 24/7.
            throw new InvalidConfigurationException("SLA business-hours calendar [{$id}] was not found.");
        }

        return $this->forModel($calendar);
    }

    public function forModel(?BusinessHoursModel $calendar): BusinessHours
    {
        if ($calendar === null) {
            return BusinessHours::alwaysOpen($this->defaultTimezone());
        }

        $holidayModel = Ticketing::holidayModel();
        $tenantColumn = $calendar->getTenantColumn();
        $tenantValue = $calendar->getAttribute($tenantColumn);
        $allowShared = config('ticketing.tenancy.allow_shared', true) !== false;

        // Resolve holidays without the ambient tenant scope (the calendar was
        // loaded unscoped too): scope explicitly to the calendar's own tenant,
        // and include shared (null-tenant) holidays only when allow_shared is on.
        /** @var list<string> $holidays */
        $holidays = $holidayModel::query()
            ->withoutTenancy()
            ->where(function ($query) use ($calendar): void {
                $query->whereNull('business_hours_id')->orWhere('business_hours_id', $calendar->getKey());
            })
            ->where(function ($query) use ($tenantColumn, $tenantValue, $allowShared): void {
                $query->where($tenantColumn, $tenantValue);

                if ($allowShared) {
                    $query->orWhereNull($tenantColumn);
                }
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
