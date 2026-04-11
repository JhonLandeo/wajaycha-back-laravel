<?php

namespace App\Console\Commands;

use App\Mail\NotificationSummaryByMonth;
use App\Services\FinancialReportService;
use App\Services\WhatsAppNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendSummaryTransactionByMonth extends Command
{
    protected $signature = 'app:send-summary-transaction-by-month';
    protected $description = 'Envía el reporte de desviación presupuestaria al cerrar el mes';

    public function handle(FinancialReportService $reportService, WhatsAppNotificationService $waService): void
    {
        try {
            $userId = 1;

            // Obtenemos el mes y año pasados (para reportar el mes que acaba de cerrar)
            $lastMonth = now()->subMonth();
            $month = $lastMonth->month;
            $year = $lastMonth->year;
            $monthName = $lastMonth->translatedFormat('F');

            $budgetDeviation = $reportService->getBudgetDeviation($userId, $month, $year);

            // 1. Enviar Email con el PDF/Tabla Markdown
            Mail::to('jpls80032017@gmail.com')->queue(new NotificationSummaryByMonth($budgetDeviation, $monthName));

            // 2. Enviar Resumen Ejecutivo por WhatsApp
            $totalBudget = $budgetDeviation->sum('budgeted');
            $totalReal = $budgetDeviation->sum('real');
            $statusEmoji = $totalReal > $totalBudget ? '⚠️' : '✅';

            $mensajeWA = "📈 *Cierre Mensual Wajaycha: {$monthName}*\n\n"
                . "💰 Presupuestado Total: S/ " . number_format($totalBudget, 2) . "\n"
                . "💸 Gasto Real: S/ " . number_format($totalReal, 2) . "\n"
                . "📊 Estado: " . ($totalReal > $totalBudget ? "*Excedido*" : "*Dentro del ahorro*") . " {$statusEmoji}\n\n"
                . "_Revisa tu correo para ver el detalle por categoría._";

            $waService->sendTextMessage('+51 992 291 220', $mensajeWA);

            $this->info("Reporte mensual de {$monthName} enviado.");
        } catch (\Throwable $th) {
            Log::error("Error en reporte mensual: " . $th->getMessage());
        }
    }
}
