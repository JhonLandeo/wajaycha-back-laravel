<?php

declare(strict_types=1);

use App\Enums\PriceBasis;
use App\Enums\PriceKind;
use App\Enums\PriceRunStatus;
use App\Models\PriceIngestionRun;
use App\Models\PriceObservation;
use App\Models\Product;
use Carbon\CarbonImmutable;

it('builds a valid observation from the factory and reads money back as an exact string', function () {
    $observation = PriceObservation::factory()->create(['unit_price' => '4.33333']);

    $fresh = PriceObservation::query()->findOrFail($observation->id);

    // numeric(12,4): the fifth decimal is rounded away by the column, and the
    // cast hands the value back as a string so no float ever touches it.
    expect($fresh->unit_price)->toBe('4.3333')
        ->and($fresh->price_kind)->toBe(PriceKind::Retail)
        ->and($fresh->basis)->toBe(PriceBasis::Measured)
        ->and($fresh->is_quarantined)->toBeFalse()
        ->and($fresh->period_end)->toBeInstanceOf(CarbonImmutable::class)
        ->and($fresh->product)->toBeInstanceOf(Product::class);
});

it('offers wholesale and quarantined states on the factory', function () {
    $wholesale = PriceObservation::factory()->wholesale()->create();
    $quarantined = PriceObservation::factory()->quarantined()->create();

    expect(PriceObservation::query()->findOrFail($wholesale->id)->price_kind)->toBe(PriceKind::Wholesale)
        ->and(PriceObservation::query()->findOrFail($quarantined->id)->is_quarantined)->toBeTrue();
});

it('builds a run-log row whose details round-trip as an array and carry no prices', function () {
    $run = PriceIngestionRun::factory()->create([
        'status' => PriceRunStatus::Success,
        'details' => ['reason' => 'no_accepted_candidates', 'rejected' => ['must_not_match' => 2]],
    ]);

    $fresh = PriceIngestionRun::query()->findOrFail($run->id);

    expect($fresh->status)->toBe(PriceRunStatus::Success)
        ->and($fresh->details)->toBe(['reason' => 'no_accepted_candidates', 'rejected' => ['must_not_match' => 2]])
        ->and($fresh->started_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($fresh->finished_at)->toBeNull();
});
