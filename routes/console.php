<?php

use App\Console\Commands\SendSummaryTransactionsByDay;
use App\Console\Commands\SendSummaryTransactionByMonth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;


Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command(SendSummaryTransactionsByDay::class)->dailyAt('20:08');
Schedule::command(SendSummaryTransactionByMonth::class)->monthlyOn(1, '08:00');
