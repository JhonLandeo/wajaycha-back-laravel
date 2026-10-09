<?php

declare(strict_types=1);

use App\Enums\PriceRunStatus;
use App\Exceptions\Prices\PriceCanaryTripped;
use App\Models\PriceIngestionRun;
use App\Models\PriceObservation;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;

/**
 * `prices:canary {source}` (design D12, spec "Ingestion run log ... canary"):
 * the check-in that fails when an enabled source goes stale, its latest run
 * failed, or its newest batch is thinner than the configured minimum.
 */
beforeEach(function () {
    // Monday 06:00 in Lima, one hour after the Plaza Vea run.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 06:00:00', 'America/Lima'));
    config(['prices.sources.plazavea.enabled' => true, 'prices.sources.plazavea.canary_min_rows' => 3]);
});

function seedPlazaVeaBatch(int $count, string $day = '2026-10-05'): void
{
    foreach (range(1, $count) as $_) {
        PriceObservation::factory()->create([
            'product_id' => Product::factory(),
            'source' => 'plazavea',
            'period_start' => $day,
            'period_end' => $day,
        ]);
    }
}

function plazaVeaRun(PriceRunStatus $status): PriceIngestionRun
{
    return PriceIngestionRun::factory()->create(['source' => 'plazavea', 'status' => $status, 'rows_written' => 1]);
}

it('is healthy and exits 0 with fresh data, a successful last run and enough rows', function () {
    Exceptions::fake();
    seedPlazaVeaBatch(3);
    plazaVeaRun(PriceRunStatus::Success);

    $this->artisan('prices:canary', ['source' => 'plazavea'])->assertSuccessful();

    Exceptions::assertNothingReported();
});

it('trips and reports when the newest observation is older than the fresh window', function () {
    Exceptions::fake();
    seedPlazaVeaBatch(3, '2026-09-26'); // 9 days old, window is 8
    plazaVeaRun(PriceRunStatus::Success);

    $this->artisan('prices:canary', ['source' => 'plazavea'])->assertFailed();

    Exceptions::assertReported(fn (PriceCanaryTripped $e): bool => $e->source === 'plazavea' && $e->reason === 'stale_observations');
});

it('does not trip at the edge of the fresh window: an 8 day old batch is still fresh', function () {
    Exceptions::fake();
    seedPlazaVeaBatch(3, '2026-09-27');
    plazaVeaRun(PriceRunStatus::Success);

    $this->artisan('prices:canary', ['source' => 'plazavea'])->assertSuccessful();
});

it('trips when the latest run failed', function () {
    Exceptions::fake();
    seedPlazaVeaBatch(3);
    plazaVeaRun(PriceRunStatus::Success);
    plazaVeaRun(PriceRunStatus::Failed);

    $this->artisan('prices:canary', ['source' => 'plazavea'])->assertFailed();

    Exceptions::assertReported(fn (PriceCanaryTripped $e): bool => $e->reason === 'latest_run_failed');
});

it('looks past skipped and running rows to the latest real outcome', function () {
    Exceptions::fake();
    seedPlazaVeaBatch(3);
    plazaVeaRun(PriceRunStatus::Success);
    plazaVeaRun(PriceRunStatus::Skipped);
    plazaVeaRun(PriceRunStatus::Running);

    $this->artisan('prices:canary', ['source' => 'plazavea'])->assertSuccessful();
});

it('trips when the newest batch has fewer rows than the configured minimum', function () {
    Exceptions::fake();
    seedPlazaVeaBatch(2);
    plazaVeaRun(PriceRunStatus::Success);

    $this->artisan('prices:canary', ['source' => 'plazavea'])->assertFailed();

    Exceptions::assertReported(fn (PriceCanaryTripped $e): bool => $e->reason === 'below_min_rows');
});

it('does not count quarantined rows toward the minimum', function () {
    Exceptions::fake();
    config(['prices.sources.inei.canary_min_rows' => 2]);
    foreach ([false, false, true] as $quarantined) {
        PriceObservation::factory()->create([
            'product_id' => Product::factory(),
            'source' => 'inei',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'is_quarantined' => $quarantined,
        ]);
    }
    PriceIngestionRun::factory()->create(['source' => 'inei', 'status' => PriceRunStatus::Partial, 'rows_written' => 3]);

    $this->artisan('prices:canary', ['source' => 'inei'])->assertSuccessful();

    config(['prices.sources.inei.canary_min_rows' => 3]);
    $this->artisan('prices:canary', ['source' => 'inei'])->assertFailed();
});

it('trips for an enabled source that has never stored anything', function () {
    Exceptions::fake();

    $this->artisan('prices:canary', ['source' => 'plazavea'])->assertFailed();

    Exceptions::assertReported(fn (PriceCanaryTripped $e): bool => $e->reason === 'no_observations');
});

it('exits 0 and checks nothing for a disabled source', function () {
    Exceptions::fake();
    config(['prices.sources.plazavea.enabled' => false]);

    $this->artisan('prices:canary', ['source' => 'plazavea'])->assertSuccessful();

    Exceptions::assertNothingReported();
});

it('checks GMML only when it actually ran', function () {
    Exceptions::fake();

    // Never ran: nothing to check.
    $this->artisan('prices:canary', ['source' => 'gmml'])->assertSuccessful();

    // Ran and wrote less than the minimum (5).
    PriceIngestionRun::factory()->create(['source' => 'gmml', 'status' => PriceRunStatus::Success, 'rows_written' => 4]);
    $this->artisan('prices:canary', ['source' => 'gmml'])->assertFailed();

    Exceptions::assertReported(fn (PriceCanaryTripped $e): bool => $e->source === 'gmml' && $e->reason === 'below_min_rows');
});

it('fails for an unknown source without reporting', function () {
    Exceptions::fake();

    $this->artisan('prices:canary', ['source' => 'bogus'])->assertFailed();

    Exceptions::assertNothingReported();
});

it('turns a 10 day outage into a trip while the plan keeps reading the old quote as stale', function () {
    Exceptions::fake();
    $product = Product::factory()->create(['unit' => 'kg']);
    PriceObservation::factory()->create(['product_id' => $product->id, 'source' => 'plazavea', 'period_start' => '2026-09-25', 'period_end' => '2026-09-25']);
    plazaVeaRun(PriceRunStatus::Success);

    $this->artisan('prices:canary', ['source' => 'plazavea'])->assertFailed();

    // Ten days old is stale for the plan (9-21 days), not unknown, and not an error.
    $quotes = app(App\Repositories\Contracts\PriceRepositoryContract::class)
        ->quotesFor([$product->id], app(App\Services\Prices\PriceSourceRegistry::class)->cutoffsFor(CarbonImmutable::now()));
    expect($quotes)->toHaveCount(1);
});
