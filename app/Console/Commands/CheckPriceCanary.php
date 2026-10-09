<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\Prices\PriceCanaryTripped;
use App\Repositories\Contracts\PriceRepositoryContract;
use App\Services\Prices\PriceCanaryEvaluator;
use App\Services\Prices\PriceSourceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Freshness canary for one source, scheduled right after its ingest. A trip is
 * reported to Sentry and the command exits non-zero, which fails the cron
 * check-in — the alert. A disabled source is not checked: switching a source off
 * must not page anyone.
 */
class CheckPriceCanary extends Command
{
    protected $signature = 'prices:canary {source : plazavea, inei, emmsa or gmml}';

    protected $description = 'Fails when a price source is stale, failing or returning too few rows';

    public function handle(PriceSourceRegistry $registry, PriceRepositoryContract $repository, PriceCanaryEvaluator $evaluator): int
    {
        $source = (string) $this->argument('source');

        if (! $registry->knows($source)) {
            $this->error("Unknown price source '{$source}'.");

            return self::FAILURE;
        }

        if (! $registry->isEnabled($source)) {
            $this->info("{$source} is disabled: not checked.");

            return self::SUCCESS;
        }

        $reason = $evaluator->evaluate(
            $repository->canarySnapshot($source),
            (int) config("prices.sources.{$source}.fresh_days"),
            (int) config("prices.sources.{$source}.canary_min_rows"),
            (bool) config("prices.sources.{$source}.fallback", false),
            CarbonImmutable::now('America/Lima')->startOfDay(),
        );

        if ($reason === null) {
            $this->info("{$source}: healthy.");

            return self::SUCCESS;
        }

        report(new PriceCanaryTripped($source, $reason));
        $this->error("{$source}: canary tripped ({$reason}).");

        return self::FAILURE;
    }
}
