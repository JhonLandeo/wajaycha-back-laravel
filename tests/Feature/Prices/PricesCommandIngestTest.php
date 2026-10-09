<?php

declare(strict_types=1);

use App\Enums\PriceRunStatus;
use App\Jobs\IngestPriceUnit;
use App\Models\PriceIngestionRun;
use App\Models\PriceObservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PriceSeed;

/**
 * `prices:ingest {source}` (spec "Source registry and kill switch", "Layer 3"):
 * one job per unit of work, spaced for Plaza Vea, and a logged `skipped` row
 * instead of any job when the source is off or already covered.
 */
beforeEach(function () {
    // Monday 05:00 in Lima.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 05:00:00', 'America/Lima'));
});

it('queues one job per mapped product, each at least five seconds after the previous', function () {
    Queue::fake();
    config(['prices.sources.plazavea.enabled' => true]);

    $this->artisan('prices:ingest', ['source' => 'plazavea'])->assertSuccessful();

    $jobs = Queue::pushed(IngestPriceUnit::class);
    expect($jobs)->toHaveCount(37)
        ->and($jobs->map(fn (IngestPriceUnit $j): string => $j->unitKey)->all())->toBe(PriceSeed::mappedSlugs('plazavea'))
        ->and($jobs->every(fn (IngestPriceUnit $j): bool => $j->source === 'plazavea' && $j->asOf === '2026-10-05'))->toBeTrue();

    $delays = $jobs->map(fn (IngestPriceUnit $j): int => (int) CarbonImmutable::now()->diffInSeconds($j->delay, false))->values()->all();
    expect($delays[0])->toBe(0);
    for ($i = 1; $i < count($delays); $i++) {
        expect($delays[$i] - $delays[$i - 1])->toBeGreaterThanOrEqual(5);
    }
    expect($delays[36])->toBe(36 * 6);
});

it('never spaces Plaza Vea jobs closer than five seconds, even if the config says less', function () {
    Queue::fake();
    config(['prices.sources.plazavea.enabled' => true, 'prices.sources.plazavea.spacing_seconds' => 1]);

    $this->artisan('prices:ingest', ['source' => 'plazavea'])->assertSuccessful();

    $jobs = Queue::pushed(IngestPriceUnit::class)->values();
    expect((int) CarbonImmutable::now()->diffInSeconds($jobs[1]->delay, false))->toBe(5);
});

it('logs one skipped row and queues nothing when the source is disabled', function () {
    Queue::fake();
    Http::fake();

    $this->artisan('prices:ingest', ['source' => 'plazavea'])->assertSuccessful();

    $run = PriceIngestionRun::query()->sole();
    expect($run->source)->toBe('plazavea')
        ->and($run->unit_key)->toBe('all')
        ->and($run->status)->toBe(PriceRunStatus::Skipped)
        ->and($run->details)->toMatchArray(['reason' => 'source_disabled']);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('rejects an unknown source with a failure exit and writes nothing', function () {
    Queue::fake();

    $this->artisan('prices:ingest', ['source' => 'bogus'])->assertFailed();

    expect(PriceIngestionRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('queues a single job for the sources that fetch a whole table', function (string $source) {
    Queue::fake();

    $this->artisan('prices:ingest', ['source' => $source])->assertSuccessful();

    $jobs = Queue::pushed(IngestPriceUnit::class);
    expect($jobs)->toHaveCount(1)
        ->and($jobs->first()->source)->toBe($source)
        ->and($jobs->first()->unitKey)->toBe('all');
})->with(['inei', 'emmsa']);

it('skips GMML, with a logged row and no job, when EMMSA covered yesterday', function (PriceRunStatus $emmsa) {
    Queue::fake();
    PriceIngestionRun::factory()->create(['source' => 'emmsa', 'status' => $emmsa, 'details' => ['day' => '2026-10-04']]);

    $this->artisan('prices:ingest', ['source' => 'gmml'])->assertSuccessful();

    $run = PriceIngestionRun::query()->where('source', 'gmml')->sole();
    expect($run->status)->toBe(PriceRunStatus::Skipped)
        ->and($run->details)->toMatchArray(['reason' => 'emmsa_covers_day', 'day' => '2026-10-04']);
    Queue::assertNothingPushed();
})->with([PriceRunStatus::Success, PriceRunStatus::Partial]);

it('queues GMML when EMMSA has no successful run for yesterday', function () {
    Queue::fake();
    PriceIngestionRun::factory()->create(['source' => 'emmsa', 'status' => PriceRunStatus::Failed, 'details' => ['day' => '2026-10-04']]);

    $this->artisan('prices:ingest', ['source' => 'gmml'])->assertSuccessful();

    expect(Queue::pushed(IngestPriceUnit::class))->toHaveCount(1)
        ->and(PriceIngestionRun::query()->where('source', 'gmml')->count())->toBe(0);
});

it('runs end to end through the queue: the command, the job, the adapter and the rows', function () {
    PriceSeed::products(['papa-blanca']);
    Http::fake(['old.emmsa.com.pe/*' => Http::response((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/prices/emmsa/rpt07-2026-10-07.html'))]);
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00:00', 'America/Lima'));

    $this->artisan('prices:ingest', ['source' => 'emmsa'])->assertSuccessful();

    expect(PriceObservation::query()->sole()->unit_price)->toBe('1.2300')
        ->and(PriceIngestionRun::query()->sole()->status)->toBe(PriceRunStatus::Success);
});
