<?php

declare(strict_types=1);

namespace App\Services\Prices;

use App\DTOs\Prices\CanarySnapshot;
use App\Enums\PriceRunStatus;
use Carbon\CarbonImmutable;

/**
 * The canary's verdict (design D12), pure: a snapshot of what a source has
 * stored in, a reason code or null out.
 *
 * A source trips when its latest real run failed, when it has stored nothing,
 * when its newest observation is older than its fresh window, or when the newest
 * batch is thinner than `canary_min_rows` — the guard against a source that
 * "works" but quietly returns nothing. Reasons are reported in that order.
 *
 * A fallback source (GMML) is judged only when it actually ran, and only by that
 * run: it is meant to be idle most days, so old data and missing runs are normal.
 */
final class PriceCanaryEvaluator
{
    public function evaluate(
        CanarySnapshot $snapshot,
        int $freshDays,
        int $minRows,
        bool $isFallback,
        CarbonImmutable $today,
    ): ?string {
        if ($snapshot->latestRunStatus === PriceRunStatus::Failed) {
            return 'latest_run_failed';
        }

        if ($isFallback) {
            if ($snapshot->latestRunStatus === null) {
                return null;
            }

            return $snapshot->latestRunRows < $minRows ? 'below_min_rows' : null;
        }

        if ($snapshot->newestPeriodEnd === null) {
            return 'no_observations';
        }

        if (CalendarDays::number($today) - CalendarDays::number($snapshot->newestPeriodEnd) > $freshDays) {
            return 'stale_observations';
        }

        return $snapshot->newestBatchRows < $minRows ? 'below_min_rows' : null;
    }
}
