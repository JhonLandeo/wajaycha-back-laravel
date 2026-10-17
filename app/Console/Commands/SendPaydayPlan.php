<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\PaydayPlan\DeliverPaydayPlanAction;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The 09:00 entry point of the payday plan: sends each eligible user the plan
 * message, once per month, retrying cycles that never went out inside the grace.
 *
 * The command orchestrates and prints; every rule lives in
 * {@see DeliverPaydayPlanAction}. `--dry-run` claims nothing and sends nothing.
 */
class SendPaydayPlan extends Command
{
    protected $signature = 'app:send-payday-plan {--dry-run : Count what would be sent without claiming or sending}';

    protected $description = 'Envia el plan de ahorro del dia de cobro a quien le toca este mes';

    public function handle(DeliverPaydayPlanAction $deliver): int
    {
        $report = $deliver->execute(CarbonImmutable::now(), (bool) $this->option('dry-run'));

        if ($report->disabled) {
            $this->info('Plan de cobro: apagado (PAYDAY_ENABLED=false), no se envia nada.');

            return self::SUCCESS;
        }

        $prefix = $report->dryRun ? 'Plan de cobro (dry-run)' : 'Plan de cobro';
        $summary = "{$prefix}: {$report->considered} considerados, {$report->sent} enviados, "
            ."{$report->failed} fallidos, {$report->retried} reintentos, {$report->skipped} omitidos, "
            ."{$report->expired} expirados, {$report->errors} errores.";

        $this->info($summary);
        Log::info($summary);

        return $report->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
