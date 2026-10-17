<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Support\Facades\Schedule;

/**
 * When the payday plan commands run. Times are Lima (the application timezone).
 *
 * The plan goes out at 09:00 and the single reminder at 20:00, the hour of the
 * coach's evening sweep. Slugs are written out rather than derived, for the same
 * reason as in {@see PriceSchedule}: a derived slug follows the command name, so a
 * rename would open a second Sentry monitor while the first kept reporting missed
 * runs.
 *
 * A day with no message is normal here (nobody is due), so the check-in is the
 * only signal that tells "nothing to send" apart from "the scheduler died".
 * `margin` and `runtime` are in minutes.
 */
final class PaydaySchedule
{
    /**
     * @return list<array{command: string, cron: string, monitor: string, margin: int, runtime: int}>
     */
    public static function entries(): array
    {
        return [
            ['command' => 'app:send-payday-plan', 'cron' => '0 9 * * *', 'monitor' => 'payday-plan', 'margin' => 5, 'runtime' => 15],
            ['command' => 'app:send-payday-reminder', 'cron' => '0 20 * * *', 'monitor' => 'payday-reminder', 'margin' => 5, 'runtime' => 15],
        ];
    }

    public static function register(): void
    {
        foreach (self::entries() as $entry) {
            Schedule::command($entry['command'])
                ->cron($entry['cron'])
                ->sentryMonitor(monitorSlug: $entry['monitor'], checkInMargin: $entry['margin'], maxRuntime: $entry['runtime']);
        }
    }
}
