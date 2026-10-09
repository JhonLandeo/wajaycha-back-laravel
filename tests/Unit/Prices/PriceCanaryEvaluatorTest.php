<?php

declare(strict_types=1);

use App\DTOs\Prices\CanarySnapshot;
use App\Enums\PriceRunStatus;
use App\Services\Prices\PriceCanaryEvaluator;
use Carbon\CarbonImmutable;

/**
 * The canary's verdict as a pure decision (design D12): a trip reason code or
 * null, from a snapshot of what the source has stored and today's Lima date.
 */
function canaryVerdict(
    ?PriceRunStatus $latestRun = PriceRunStatus::Success,
    int $latestRunRows = 30,
    ?string $newestPeriodEnd = '2026-10-05',
    int $batchRows = 30,
    int $freshDays = 8,
    int $minRows = 28,
    bool $fallback = false,
    string $today = '2026-10-05',
): ?string {
    return (new PriceCanaryEvaluator)->evaluate(
        new CanarySnapshot($latestRun, $latestRunRows, $newestPeriodEnd === null ? null : CarbonImmutable::parse($newestPeriodEnd), $batchRows),
        $freshDays,
        $minRows,
        $fallback,
        CarbonImmutable::parse($today),
    );
}

it('is healthy with fresh data, a successful last run and enough rows', function () {
    expect(canaryVerdict())->toBeNull()
        ->and(canaryVerdict(latestRun: PriceRunStatus::Partial))->toBeNull();
});

it('trips when the latest run failed, whatever else is true', function () {
    expect(canaryVerdict(latestRun: PriceRunStatus::Failed))->toBe('latest_run_failed');
});

it('trips when the newest observation is older than the fresh window, and not at the edge', function () {
    // fresh window 8 days: 8 days old is still fresh, 9 is not.
    expect(canaryVerdict(newestPeriodEnd: '2026-09-27'))->toBeNull()
        ->and(canaryVerdict(newestPeriodEnd: '2026-09-26'))->toBe('stale_observations')
        ->and(canaryVerdict(newestPeriodEnd: '2026-09-25'))->toBe('stale_observations');
});

it('trips when an enabled source has stored nothing at all', function () {
    expect(canaryVerdict(newestPeriodEnd: null, batchRows: 0))->toBe('no_observations');
});

it('trips when the newest batch is thinner than the configured minimum, and not at it', function () {
    expect(canaryVerdict(batchRows: 28))->toBeNull()
        ->and(canaryVerdict(batchRows: 27))->toBe('below_min_rows')
        ->and(canaryVerdict(batchRows: 7, minRows: 8, freshDays: 4))->toBe('below_min_rows');
});

it('reports the failed run before the other reasons', function () {
    expect(canaryVerdict(latestRun: PriceRunStatus::Failed, newestPeriodEnd: '2026-01-01', batchRows: 0))->toBe('latest_run_failed');
});

it('checks a fallback source only when it ran, and only by its own run', function () {
    // Never ran (or only skipped): nothing to check.
    expect(canaryVerdict(latestRun: null, latestRunRows: 0, newestPeriodEnd: null, batchRows: 0, fallback: true))->toBeNull()
        // Ran and wrote enough, even though its data is old.
        ->and(canaryVerdict(latestRunRows: 5, minRows: 5, newestPeriodEnd: '2026-08-01', fallback: true))->toBeNull()
        // Ran and wrote too little.
        ->and(canaryVerdict(latestRunRows: 4, minRows: 5, fallback: true))->toBe('below_min_rows')
        // Ran and failed.
        ->and(canaryVerdict(latestRun: PriceRunStatus::Failed, fallback: true))->toBe('latest_run_failed');
});

it('measures age in calendar days of Lima, not instants', function () {
    // Same date written at 23:59 and 00:01 gives the same age.
    expect(canaryVerdict(newestPeriodEnd: '2026-09-27', today: '2026-10-05'))->toBeNull();
});
