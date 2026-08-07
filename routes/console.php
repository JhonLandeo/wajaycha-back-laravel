<?php

use App\Console\Commands\RunCoachingSweep;
use App\Console\Commands\SendSummaryTransactionByMonth;
use App\Console\Commands\SendSummaryTransactionsByDay;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Deliberate overlap window (design.md §8): SendSummaryTransactionsByDay's
// 20:08 entry stays untouched until slice 6 retires it. Eight minutes of two
// voices beats a day of none, and it is the only window in which the coach can
// be compared against what it replaces.
Schedule::command(RunCoachingSweep::class)->dailyAt('20:00');
Schedule::command(SendSummaryTransactionsByDay::class)->dailyAt('20:08');
Schedule::command(SendSummaryTransactionByMonth::class)->monthlyOn(1, '08:00');
Schedule::command(\App\Console\Commands\PruneChannelLinkTokens::class)->hourly();
Schedule::command(\App\Console\Commands\PruneProcessedChannelUpdates::class)->daily();
