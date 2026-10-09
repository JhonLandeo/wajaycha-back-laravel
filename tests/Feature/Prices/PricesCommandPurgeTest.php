<?php

declare(strict_types=1);

use App\Enums\PriceRunStatus;
use App\Models\PriceIngestionRun;
use App\Models\PriceObservation;

/**
 * `prices:purge {source}` (spec "Purge command for takedown"): the 24-48 hour
 * takedown commitment as one command.
 */
it('deletes only that source, prints the count and leaves one purged row', function () {
    PriceObservation::factory()->count(40)->create(['source' => 'plazavea']);
    PriceObservation::factory()->count(20)->create(['source' => 'inei']);

    $this->artisan('prices:purge', ['source' => 'plazavea'])
        ->expectsOutputToContain('40')
        ->assertSuccessful();

    expect(PriceObservation::query()->where('source', 'plazavea')->count())->toBe(0)
        ->and(PriceObservation::query()->where('source', 'inei')->count())->toBe(20);

    $runs = PriceIngestionRun::query()->where('status', PriceRunStatus::Purged)->get();
    expect($runs)->toHaveCount(1)
        ->and($runs[0]->source)->toBe('plazavea')
        ->and($runs[0]->details)->toBe(['deleted' => 40]);
});

it('fails with a non-zero exit and deletes nothing for an unknown source', function () {
    PriceObservation::factory()->count(3)->create(['source' => 'plazavea']);

    $this->artisan('prices:purge', ['source' => 'bogus'])->assertFailed();

    expect(PriceObservation::query()->count())->toBe(3)
        ->and(PriceIngestionRun::query()->count())->toBe(0);
});

it('is idempotent: running it again succeeds with a zero count', function () {
    PriceObservation::factory()->count(2)->create(['source' => 'plazavea']);

    $this->artisan('prices:purge', ['source' => 'plazavea'])->assertSuccessful();
    $this->artisan('prices:purge', ['source' => 'plazavea'])->expectsOutputToContain('0')->assertSuccessful();

    expect(PriceObservation::query()->count())->toBe(0)
        ->and(PriceIngestionRun::query()->where('status', PriceRunStatus::Purged)->count())->toBe(2);
});

it('warns when the source is still enabled and stays quiet when it is off', function () {
    config(['prices.sources.plazavea.enabled' => true]);
    $this->artisan('prices:purge', ['source' => 'plazavea'])
        ->expectsOutputToContain('still enabled')
        ->assertSuccessful();

    config(['prices.sources.plazavea.enabled' => false]);
    $this->artisan('prices:purge', ['source' => 'plazavea'])
        ->doesntExpectOutputToContain('still enabled')
        ->assertSuccessful();
});

it('keeps the run log: earlier runs survive a purge', function () {
    PriceIngestionRun::factory()->count(3)->create(['source' => 'plazavea', 'status' => PriceRunStatus::Success]);

    $this->artisan('prices:purge', ['source' => 'plazavea'])->assertSuccessful();

    expect(PriceIngestionRun::query()->where('status', PriceRunStatus::Success)->count())->toBe(3);
});
