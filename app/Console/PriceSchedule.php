<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Support\Facades\Schedule;

/**
 * When the price commands run (design D10, D12). Times are Lima (the application
 * timezone). Every entry reports to a Sentry cron monitor whose slug is written
 * out here rather than derived: the generated slug comes from the command line,
 * so renaming a signature would silently open a second monitor while the first
 * kept reporting missed runs.
 *
 * Each canary runs after the ingest it watches, with room for the queue to drain
 * (Plaza Vea's 37 jobs are spaced over about four minutes). EMMSA is fetched at
 * 10:00 for the previous day; GMML at 11:30 fills only a day EMMSA did not cover.
 *
 * `checkInMargin` and `maxRuntime` are in minutes. The ingest commands only
 * dispatch jobs, so they finish in seconds.
 */
final class PriceSchedule
{
    /**
     * @return list<array{command: string, cron: string, monitor: string, margin: int, runtime: int}>
     */
    public static function entries(): array
    {
        return [
            ['command' => 'prices:ingest plazavea', 'cron' => '0 5 * * 1', 'monitor' => 'prices-plazavea', 'margin' => 10, 'runtime' => 10],
            ['command' => 'prices:canary plazavea', 'cron' => '0 6 * * 1', 'monitor' => 'prices-canary-plazavea', 'margin' => 10, 'runtime' => 5],
            ['command' => 'prices:ingest inei', 'cron' => '0 7 * * 1', 'monitor' => 'prices-inei', 'margin' => 10, 'runtime' => 10],
            ['command' => 'prices:canary inei', 'cron' => '0 8 * * 1', 'monitor' => 'prices-canary-inei', 'margin' => 10, 'runtime' => 5],
            ['command' => 'prices:ingest emmsa', 'cron' => '0 10 * * *', 'monitor' => 'prices-emmsa', 'margin' => 10, 'runtime' => 10],
            ['command' => 'prices:canary emmsa', 'cron' => '0 11 * * *', 'monitor' => 'prices-canary-emmsa', 'margin' => 10, 'runtime' => 5],
            ['command' => 'prices:ingest gmml', 'cron' => '30 11 * * *', 'monitor' => 'prices-gmml', 'margin' => 10, 'runtime' => 10],
            ['command' => 'prices:canary gmml', 'cron' => '30 12 * * *', 'monitor' => 'prices-canary-gmml', 'margin' => 10, 'runtime' => 5],
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
