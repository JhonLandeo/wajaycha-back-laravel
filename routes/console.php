<?php

use App\Console\Commands\RunCoachingSweep;
use App\Console\Commands\SendSummaryTransactionByMonth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// design.md §8: the daily summary (SendSummaryTransactionsByDay, 20:08) is retired —
// its "meta de gasto diario" came from a hardcoded v_amount_total := 2000, the exact
// pace arithmetic the coach now computes honestly and per category. The 20:00 sweep
// below is the sole daily voice.
Schedule::command(RunCoachingSweep::class)->dailyAt('20:00');
Schedule::command(SendSummaryTransactionByMonth::class)->monthlyOn(1, '08:00');
Schedule::command(\App\Console\Commands\PruneChannelLinkTokens::class)->hourly();
Schedule::command(\App\Console\Commands\PruneProcessedChannelUpdates::class)->daily();
