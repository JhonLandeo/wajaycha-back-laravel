<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\PaydayPlan\SendPaydayReminderAction;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The 20:00 entry point: at most one reminder per cycle, never retried.
 *
 * Every rule lives in {@see SendPaydayReminderAction}; `--dry-run` claims
 * nothing and sends nothing.
 */
class SendPaydayReminder extends Command
{
    protected $signature = 'app:send-payday-reminder {--dry-run : Count what would be sent without claiming or sending}';

    protected $description = 'Envia el unico recordatorio del plan de cobro a quien sigue sin responder';

    public function handle(SendPaydayReminderAction $remind): int
    {
        $report = $remind->execute(CarbonImmutable::now(), (bool) $this->option('dry-run'));

        if ($report->disabled) {
            $this->info('Recordatorio de cobro: apagado (PAYDAY_ENABLED=false), no se envia nada.');

            return self::SUCCESS;
        }

        $prefix = $report->dryRun ? 'Recordatorio de cobro (dry-run)' : 'Recordatorio de cobro';
        $summary = "{$prefix}: {$report->considered} considerados, {$report->sent} enviados, "
            ."{$report->failed} fallidos, {$report->skipped} omitidos, {$report->errors} errores.";

        $this->info($summary);
        Log::info($summary);

        return $report->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
