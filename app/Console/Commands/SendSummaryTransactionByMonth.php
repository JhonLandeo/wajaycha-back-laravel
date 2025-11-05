<?php

namespace App\Console\Commands;

use App\Http\Controllers\FinancialReportController;
use App\Mail\NotificationSummaryByMonth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendSummaryTransactionByMonth extends Command
{
    private FinancialReportController $financialReportController;

    public function __construct(FinancialReportController $financialReportController) {
        parent::__construct();
        $this->financialReportController = $financialReportController;
    }
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-summary-transaction-by-month';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        try {
            $month = date('m');
            $year = date('Y');
            $budgetDeviation = $this->financialReportController->budgetDeviation($month - 1);
            Mail::to('jpls80032017@gmail.com')->queue(new NotificationSummaryByMonth($budgetDeviation));
        } catch (\Throwable $th) {
            error_log($th);

            throw $th;
        }
    }
}
