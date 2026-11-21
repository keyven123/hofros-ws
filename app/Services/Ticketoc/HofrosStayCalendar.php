<?php

namespace App\Services\Ticketoc;

use App\Models\Booking;
use App\Models\Unit;
use App\Models\UnitDateBlock;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Merchant calendar for Ticketoc Stay. Hofros is the source of truth:
 * closed weekdays, date blocks, and firm bookings. Block and booking ends are exclusive.
 */
final class HofrosStayCalendar
{
    /**
     * @param  list<string>  $unitIds
     * @return array{from: string, to: string, units: list<array{id: string, unavailable: list<array{start_date: string, end_date: string}>}>}
     */
    public function forUnits(array $unitIds, string $from, string $to): array
    {
        $fromDate = Carbon::parse($from)->startOfDay();
        $toDate = Carbon::parse($to)->startOfDay();
        $unitIds = array_values(array_unique($unitIds));

        $units = Unit::query()->whereIn('uuid', $unitIds)->get();
        $bookings = Booking::query()
            ->whereIn('unit_uuid', $unitIds)
            ->whereIn('status', [
                Booking::STATUS_ACCEPTED,
                Booking::STATUS_ASSIGNED,
                Booking::STATUS_CHECKED_IN,
                Booking::STATUS_CHECKED_OUT,
            ])
            ->where('check_in', '<=', $toDate->toDateString())
            ->where('check_out', '>', $fromDate->toDateString())
            ->get(['uuid', 'unit_uuid', 'check_in', 'check_out']);

        $blocks = UnitDateBlock::query()
            ->whereIn('unit_uuid', $unitIds)
            ->where('start_date', '<=', $toDate->toDateString())
            ->where('end_date', '>', $fromDate->toDateString())
            ->get(['uuid', 'unit_uuid', 'start_date', 'end_date']);

        $bookingsByUnit = $bookings->groupBy('unit_uuid');
        $blocksByUnit = $blocks->groupBy('unit_uuid');

        $payload = [];
        foreach ($units as $unit) {
            $nights = [];
            $this->markClosedWeekdays($nights, $unit, $fromDate, $toDate);
            $this->markExclusiveRanges($nights, $blocksByUnit->get($unit->uuid, collect()), 'start_date', 'end_date', $fromDate, $toDate);
            $this->markExclusiveRanges($nights, $bookingsByUnit->get($unit->uuid, collect()), 'check_in', 'check_out', $fromDate, $toDate);

            $payload[] = [
                'id' => $unit->uuid,
                'unavailable' => $this->inclusiveRanges(array_keys($nights)),
            ];
        }

        return [
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'units' => $payload,
        ];
    }

    /**
     * @param  array<string, true>  $nights
     */
    private function markClosedWeekdays(array &$nights, Unit $unit, Carbon $from, Carbon $to): void
    {
        $defaults = Unit::defaultWeekSchedule();
        $week = array_merge($defaults, is_array($unit->week_schedule) ? array_intersect_key($unit->week_schedule, $defaults) : []);
        $keys = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];

        foreach (CarbonPeriod::create($from, $to) as $day) {
            $key = $keys[$day->dayOfWeekIso] ?? null;
            if ($key !== null && ($week[$key] ?? true) === false) {
                $nights[$day->toDateString()] = true;
            }
        }
    }

    /**
     * @param  array<string, true>  $nights
     * @param  Collection<int, mixed>  $rows
     */
    private function markExclusiveRanges(array &$nights, Collection $rows, string $startKey, string $endKey, Carbon $from, Carbon $to): void
    {
        foreach ($rows as $row) {
            $start = $row->{$startKey} instanceof Carbon ? $row->{$startKey}->copy()->startOfDay() : Carbon::parse((string) $row->{$startKey})->startOfDay();
            $end = $row->{$endKey} instanceof Carbon ? $row->{$endKey}->copy()->startOfDay() : Carbon::parse((string) $row->{$endKey})->startOfDay();
            if ($end->lte($start)) {
                continue;
            }

            $cursor = $start->greaterThan($from) ? $start->copy() : $from->copy();
            $last = $end->copy()->subDay();
            if ($last->greaterThan($to)) {
                $last = $to->copy();
            }
            if ($last->lt($cursor)) {
                continue;
            }

            foreach (CarbonPeriod::create($cursor, $last) as $day) {
                $nights[$day->toDateString()] = true;
            }
        }
    }

    /**
     * @param  list<string>  $dates
     * @return list<array{start_date: string, end_date: string}>
     */
    private function inclusiveRanges(array $dates): array
    {
        sort($dates);
        $ranges = [];
        $start = null;
        $previous = null;

        foreach ($dates as $date) {
            if ($start === null) {
                $start = $date;
                $previous = $date;
                continue;
            }

            if (Carbon::parse($previous)->addDay()->toDateString() === $date) {
                $previous = $date;
                continue;
            }

            $ranges[] = ['start_date' => $start, 'end_date' => $previous];
            $start = $date;
            $previous = $date;
        }

        if ($start !== null && $previous !== null) {
            $ranges[] = ['start_date' => $start, 'end_date' => $previous];
        }

        return $ranges;
    }
}
