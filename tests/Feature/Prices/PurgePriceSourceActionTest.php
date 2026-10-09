<?php

declare(strict_types=1);

use App\Actions\Prices\PurgePriceSourceAction;
use App\DTOs\Prices\PurgeResult;
use App\Enums\PriceRunStatus;
use App\Exceptions\Prices\UnknownPriceSource;
use App\Models\PriceIngestionRun;
use App\Models\PriceObservation;

/**
 * The takedown path (spec "Purge command for takedown"): everything a source
 * ever gave us can be removed on request, nothing else is touched, and the run
 * log — which holds no third-party prices — stays as the audit trail.
 */
function purgeAction(): PurgePriceSourceAction
{
    return app(PurgePriceSourceAction::class);
}

it('deletes only the named source, reports the count and logs one purged run', function () {
    PriceObservation::factory()->count(4)->create(['source' => 'plazavea']);
    PriceObservation::factory()->count(2)->create(['source' => 'inei']);

    $result = purgeAction()->execute('plazavea');

    expect($result)->toBeInstanceOf(PurgeResult::class)
        ->and($result->deleted)->toBe(4)
        ->and(PriceObservation::query()->where('source', 'plazavea')->count())->toBe(0)
        ->and(PriceObservation::query()->where('source', 'inei')->count())->toBe(2);

    $runs = PriceIngestionRun::query()->where('status', PriceRunStatus::Purged)->get();

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->source)->toBe('plazavea')
        ->and($runs[0]->unit_key)->toBe('all')
        // The log records HOW MANY went, never WHAT — it survives the purge.
        ->and($runs[0]->details)->toBe(['deleted' => 4]);
});

it('rejects an unknown source before touching anything', function () {
    PriceObservation::factory()->count(3)->create(['source' => 'plazavea']);

    expect(fn () => purgeAction()->execute('bogus'))->toThrow(UnknownPriceSource::class);

    expect(PriceObservation::query()->count())->toBe(3)
        ->and(PriceIngestionRun::query()->count())->toBe(0);
});

it('is idempotent: a second purge deletes nothing and succeeds', function () {
    PriceObservation::factory()->count(2)->create(['source' => 'plazavea']);

    $first = purgeAction()->execute('plazavea');
    $second = purgeAction()->execute('plazavea');

    expect($first->deleted)->toBe(2)
        ->and($second->deleted)->toBe(0)
        ->and(PriceIngestionRun::query()->where('status', PriceRunStatus::Purged)->count())->toBe(2);
});

it('tells the caller when the purged source is still enabled', function () {
    PriceObservation::factory()->create(['source' => 'plazavea']);

    config(['prices.sources.plazavea.enabled' => true]);
    $enabled = purgeAction()->execute('plazavea');

    config(['prices.sources.plazavea.enabled' => false]);
    $disabled = purgeAction()->execute('plazavea');

    expect($enabled->sourceStillEnabled)->toBeTrue()
        ->and($disabled->sourceStillEnabled)->toBeFalse();
});

it('keeps the run log of earlier ingests', function () {
    PriceObservation::factory()->create(['source' => 'plazavea']);
    PriceIngestionRun::factory()->count(3)->create(['source' => 'plazavea', 'status' => PriceRunStatus::Success]);

    purgeAction()->execute('plazavea');

    expect(PriceIngestionRun::query()->where('status', PriceRunStatus::Success)->count())->toBe(3);
});
