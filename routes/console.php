<?php

use App\Console\Commands\RunCoachingSweep;
use App\Console\Commands\SendBudgetDigest;
use App\Console\Commands\SendSummaryTransactionByMonth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Sentry cron monitors are attached to the three commands that speak to a user,
// and to none of the housekeeping ones. The reason is specific to this system:
// the coach is designed to stay silent when it has nothing honest to say, so a
// day with no message is indistinguishable from a scheduler that died. The
// check-in is the only signal that separates the two — `sentryMonitor()` reports
// in_progress before the command and ok/error after it, and Sentry raises the
// alert when the check-in never arrives at all.
//
// Slugs are written out rather than derived. The generated slug comes from the
// command name, so renaming a signature would silently open a second monitor
// while the original kept reporting missed runs forever.
//
// `checkInMargin` and `maxRuntime` are in minutes. The margin is how late a run
// may start before it counts as missed; `schedule:work` polls every minute, so
// the dailies need very little slack and the monthly gets more because a busy
// first-of-month is normal, not a fault.

// design.md §8: the daily summary (SendSummaryTransactionsByDay, 20:08) is retired —
// its "meta de gasto diario" came from a hardcoded v_amount_total := 2000, the exact
// pace arithmetic the coach now computes honestly and per category. The 20:00 sweep
// below is the sole daily voice.
Schedule::command(RunCoachingSweep::class)
    ->dailyAt('20:00')
    ->sentryMonitor(monitorSlug: 'coaching-sweep', checkInMargin: 5, maxRuntime: 15);

// El parte matutino de presupuestos. Es la otra mitad del par y llega antes de
// las decisiones del dia, no despues: el barrido de las 20:00 narra lo que ya
// paso, y una advertencia que llega cuando el gasto ya ocurrio no puede
// corregirlo. Repite el estado todos los dias a proposito — eso es justo lo que
// el ledger le prohibe al coach, y por eso vive en su propio comando.
Schedule::command(SendBudgetDigest::class)
    ->dailyAt((string) config('coaching.digest_hour'))
    ->sentryMonitor(monitorSlug: 'budget-digest', checkInMargin: 5, maxRuntime: 15);

// Once a month: a failure here is invisible for thirty days without a monitor.
Schedule::command(SendSummaryTransactionByMonth::class)
    ->monthlyOn(1, '08:00')
    ->sentryMonitor(monitorSlug: 'monthly-transaction-summary', checkInMargin: 30, maxRuntime: 30);
Schedule::command(\App\Console\Commands\PruneChannelLinkTokens::class)->hourly();
Schedule::command(\App\Console\Commands\PruneProcessedChannelUpdates::class)->daily();

// Grocery prices: ingest, freshness canary and Sentry monitors for the four
// price sources. The entries and their reasoning live in PriceSchedule so the
// times and monitor slugs are testable.
\App\Console\PriceSchedule::register();
