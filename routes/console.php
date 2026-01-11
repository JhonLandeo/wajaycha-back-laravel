<?php

use App\Console\Commands\SendSummaryTransactionsByDay;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;


Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('app:send-summary-transactions-by-day', function(){
    logger()->info('Sending summary transactions by day');
} )->dailyAt('20:08');

Artisan::command('app:send-summary-transaction-by-month', function(){
    logger()->info('Sending summary transaction by month');
} )->monthlyOn(1, '08:00');