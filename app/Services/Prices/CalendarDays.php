<?php

declare(strict_types=1);

namespace App\Services\Prices;

use Carbon\CarbonImmutable;

/**
 * Calendar-day arithmetic for the price deciders. An age in days is a
 * difference between two DATES, never between two instants, so a 21:00 Lima
 * instant that is already tomorrow in UTC cannot move an age by one.
 */
final class CalendarDays
{
    /**
     * Whole days since the epoch for the date part of `$date`, read as written
     * (its own year, month and day — no timezone conversion).
     */
    public static function number(CarbonImmutable $date): int
    {
        return intdiv(CarbonImmutable::create($date->year, $date->month, $date->day, 0, 0, 0, 'UTC')->getTimestamp(), 86_400);
    }
}
