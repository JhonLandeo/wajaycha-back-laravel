<?php

namespace App\Console\Commands;

use App\Jobs\SendEmailSummary;
use App\Mail\NotificationSummaryByDay;
use Carbon\Carbon;
use DateTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendSummaryTransactionsByDay extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-summary-transactions-by-day';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $summary = DB::select('CALL get_summary_transaction_by_day()');
            $summary = collect($summary)->first();
            Mail::to('jpls80032017@gmail.com')->queue(new NotificationSummaryByDay($summary));
        } catch (\Throwable $th) {
            error_log($th);
            throw $th;
        }

    }
}
