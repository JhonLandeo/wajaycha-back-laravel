<?php

namespace App\Console\Commands;

use App\Mail\NotificationSummaryByDay;
use App\Services\WhatsAppNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendSummaryTransactionsByDay extends Command
{
    protected $signature = 'app:send-summary-transactions-by-day';
    protected $description = 'Envía resumen diario por Email y WhatsApp';

    public function handle(WhatsAppNotificationService $waService): void
    {
        try {
            $userId = 1;
            $summary = DB::select('SELECT * FROM get_summary_transaction_by_day(?)', [$userId]);
            $summary = collect($summary)->first();

            if (!$summary) return;

            Mail::to('jpls80032017@gmail.com')->queue(new NotificationSummaryByDay($summary));

            $mensajeWA = "📊 *Resumen Diario Wajaycha*\n\n"
                . "💰 Presupuesto mensual: S/ {$summary->income_total_by_month}\n"
                . "🎯 Meta de gasto diario: S/ {$summary->avg_expense_day}\n"
                . "🔥 Gastado este mes: S/ {$summary->total_expense}\n"
                . "👉 *Nuevo monto a gastar por día: S/ {$summary->new_expense_day}*";

            $waService->sendTextMessage('+51 992 291 220', $mensajeWA); // Agrega tu número

            $this->info('Resumen enviado.');
        } catch (\Throwable $th) {
            Log::error("Error en resumen diario: " . $th->getMessage());
        }
    }
}
