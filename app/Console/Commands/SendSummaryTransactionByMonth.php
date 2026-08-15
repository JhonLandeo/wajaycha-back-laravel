<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\NotificationSummaryByMonth;
use App\Services\FinancialReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendSummaryTransactionByMonth extends Command
{
    protected $signature = 'app:send-summary-transaction-by-month';

    protected $description = 'Envía el reporte de desviación presupuestaria al cerrar el mes';

    /**
     * Task 6.4/design.md §8: the WhatsApp push is removed entirely — the coach now
     * owns the conversational channel (ADR-0007). Its "Resumen Ejecutivo" read
     * `$budgetDeviation->sum('real')`, a property that never existed on the rows
     * `FinancialReportService::getBudgetDeviation()` returns, so it always rendered
     * `S/ 0.00`. The monthly email survives unmodified in intent — only repaired.
     */
    public function handle(FinancialReportService $reportService): void
    {
        try {
            $userId = 1;

            // Obtenemos el mes y año pasados (para reportar el mes que acaba de cerrar)
            $lastMonth = now()->subMonth();
            $month = $lastMonth->month;
            $year = $lastMonth->year;
            $monthName = $lastMonth->translatedFormat('F');

            $budgetDeviation = $reportService->getBudgetDeviation($userId, $month, $year);

            Mail::to('jpls80032017@gmail.com')->queue(new NotificationSummaryByMonth($budgetDeviation, $monthName));

            $this->info("Reporte mensual de {$monthName} enviado.");
        } catch (\Throwable $th) {
            Log::error('Error en reporte mensual: '.$th->getMessage());
        }
    }
}
