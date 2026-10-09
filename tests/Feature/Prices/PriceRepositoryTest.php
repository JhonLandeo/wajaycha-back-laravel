<?php

declare(strict_types=1);

use App\DTOs\Prices\ObservationDraft;
use App\DTOs\Prices\Quote;
use App\Enums\PriceBasis;
use App\Enums\PriceKind;
use App\Enums\PriceRunStatus;
use App\Models\PriceIngestionRun;
use App\Models\PriceObservation;
use App\Models\Product;
use App\Models\User;
use App\Repositories\Contracts\PriceRepositoryContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function priceRepository(): PriceRepositoryContract
{
    return app(PriceRepositoryContract::class);
}

function priceDraft(
    int $productId,
    string $source = 'plazavea',
    string $unitPrice = '8.9000',
    string $periodEnd = '2026-10-05',
    string $unit = 'kg',
    PriceKind $kind = PriceKind::Retail,
    bool $quarantined = false,
    ?string $periodStart = null,
): ObservationDraft {
    return new ObservationDraft(
        productId: $productId,
        source: $source,
        kind: $kind,
        unit: $unit,
        unitPrice: $unitPrice,
        referencePrice: null,
        priceMin: null,
        priceMax: null,
        sampleSize: 3,
        basis: PriceBasis::Measured,
        periodStart: CarbonImmutable::parse($periodStart ?? $periodEnd),
        periodEnd: CarbonImmutable::parse($periodEnd),
        observedAt: CarbonImmutable::parse('2026-10-05 05:00:00', 'America/Lima'),
        sourceRef: 'sku:1',
        isQuarantined: $quarantined,
    );
}

// --------------------------------------------------------------- upsert

it('updates instead of duplicating when the same product, source and period are ingested again', function () {
    $product = Product::factory()->create();

    $first = priceRepository()->upsertObservation(priceDraft($product->id, unitPrice: '8.9000'));
    $second = priceRepository()->upsertObservation(priceDraft($product->id, unitPrice: '9.5000'));

    expect(PriceObservation::query()->count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and(PriceObservation::query()->firstOrFail()->unit_price)->toBe('9.5000');
});

it('keeps distinct periods and distinct sources as separate rows', function () {
    $product = Product::factory()->create();

    priceRepository()->upsertObservation(priceDraft($product->id, periodEnd: '2026-10-05'));
    priceRepository()->upsertObservation(priceDraft($product->id, periodEnd: '2026-10-12'));
    priceRepository()->upsertObservation(priceDraft($product->id, source: 'inei', periodEnd: '2026-10-05'));

    expect(PriceObservation::query()->count())->toBe(3);
});

it('stores the product unit the price is expressed in', function () {
    $kg = Product::factory()->create(['unit' => 'kg']);
    $each = Product::factory()->create(['unit' => 'unidad']);

    priceRepository()->upsertObservation(priceDraft($kg->id, unit: 'kg'));
    priceRepository()->upsertObservation(priceDraft($each->id, unit: 'unidad', unitPrice: '1.6000'));

    expect(PriceObservation::query()->where('product_id', $kg->id)->firstOrFail()->unit)->toBe('kg')
        ->and(PriceObservation::query()->where('product_id', $each->id)->firstOrFail()->unit)->toBe('unidad')
        ->and(PriceObservation::query()->where('product_id', $each->id)->firstOrFail()->unit_price)->toBe('1.6000');
});

it('persists the optional fields and the quarantine flag on a rerun', function () {
    $product = Product::factory()->create();
    $draft = new ObservationDraft(
        productId: $product->id, source: 'plazavea', kind: PriceKind::Retail, unit: 'kg', unitPrice: '6.0000',
        referencePrice: '6.5000', priceMin: '4.0000', priceMax: '20.0000', sampleSize: 3, basis: PriceBasis::Equivalence,
        periodStart: CarbonImmutable::parse('2026-10-05'), periodEnd: CarbonImmutable::parse('2026-10-05'),
        observedAt: CarbonImmutable::parse('2026-10-05 05:00:00', 'America/Lima'), sourceRef: 'sku:1,sku:2', isQuarantined: false,
    );

    priceRepository()->upsertObservation($draft);
    $row = PriceObservation::query()->firstOrFail();

    expect($row->reference_price)->toBe('6.5000')
        ->and($row->price_min)->toBe('4.0000')
        ->and($row->price_max)->toBe('20.0000')
        ->and($row->basis)->toBe(PriceBasis::Equivalence)
        ->and($row->source_ref)->toBe('sku:1,sku:2')
        ->and($row->is_quarantined)->toBeFalse();

    priceRepository()->upsertObservation(priceDraft($product->id, unitPrice: '6.0000', quarantined: true));

    expect(PriceObservation::query()->firstOrFail()->is_quarantined)->toBeTrue()
        // The rerun carries no optional fields, so they are cleared, not kept stale.
        ->and(PriceObservation::query()->firstOrFail()->reference_price)->toBeNull();
});

// --------------------------------------------------------------- quotesFor

it('reads quotes as values, newest first within each product and source', function () {
    $product = Product::factory()->create();
    priceRepository()->upsertObservation(priceDraft($product->id, periodEnd: '2026-10-05'));
    priceRepository()->upsertObservation(priceDraft($product->id, periodEnd: '2026-10-12', unitPrice: '9.1000'));

    $quotes = priceRepository()->quotesFor([$product->id], ['plazavea' => CarbonImmutable::parse('2026-09-01')]);

    expect($quotes)->toHaveCount(2)
        ->and($quotes[0])->toBeInstanceOf(Quote::class)
        ->and($quotes[0]->unitPrice)->toBe('9.1000')
        ->and($quotes[0]->periodEnd?->toDateString())->toBe('2026-10-12')
        ->and($quotes[1]->periodEnd?->toDateString())->toBe('2026-10-05')
        ->and($quotes[0]->kind)->toBe(PriceKind::Retail)
        ->and($quotes[0]->unit)->toBe('kg')
        ->and($quotes[0]->basis)->toBe(PriceBasis::Measured)
        ->and($quotes[0]->productId)->toBe($product->id);
});

it('reads only the sources it is asked for and brings them back when asked again', function () {
    $product = Product::factory()->create();
    priceRepository()->upsertObservation(priceDraft($product->id, source: 'plazavea'));
    priceRepository()->upsertObservation(priceDraft($product->id, source: 'inei'));
    priceRepository()->upsertObservation(priceDraft($product->id, source: 'emmsa', kind: PriceKind::Wholesale));
    $since = CarbonImmutable::parse('2026-09-01');

    $withoutPlazaVea = priceRepository()->quotesFor([$product->id], ['inei' => $since, 'emmsa' => $since]);
    $withEverything = priceRepository()->quotesFor([$product->id], ['plazavea' => $since, 'inei' => $since, 'emmsa' => $since]);

    expect(array_map(fn (Quote $q): string => $q->source, $withoutPlazaVea))->toEqualCanonicalizing(['inei', 'emmsa'])
        ->and(array_map(fn (Quote $q): string => $q->source, $withEverything))->toEqualCanonicalizing(['plazavea', 'inei', 'emmsa'])
        // Nothing was deleted by leaving the source out: the row is still stored.
        ->and(PriceObservation::query()->where('source', 'plazavea')->count())->toBe(1);
});

it('applies each source cutoff to period_end, inclusive', function () {
    $product = Product::factory()->create();
    priceRepository()->upsertObservation(priceDraft($product->id, source: 'plazavea', periodEnd: '2026-09-19'));
    priceRepository()->upsertObservation(priceDraft($product->id, source: 'inei', periodEnd: '2026-09-19'));

    $quotes = priceRepository()->quotesFor([$product->id], [
        'plazavea' => CarbonImmutable::parse('2026-09-19'), // equal: kept
        'inei' => CarbonImmutable::parse('2026-09-20'),     // one day too old: dropped
    ]);

    expect(array_map(fn (Quote $q): string => $q->source, $quotes))->toBe(['plazavea']);
});

it('leaves quarantined observations out of every read', function () {
    $product = Product::factory()->create();
    priceRepository()->upsertObservation(priceDraft($product->id, source: 'inei', unitPrice: '17.0300', quarantined: true));
    priceRepository()->upsertObservation(priceDraft($product->id, source: 'plazavea', unitPrice: '8.9000'));

    $quotes = priceRepository()->quotesFor([$product->id], [
        'inei' => CarbonImmutable::parse('2026-09-01'),
        'plazavea' => CarbonImmutable::parse('2026-09-01'),
    ]);

    expect($quotes)->toHaveCount(1)
        ->and($quotes[0]->source)->toBe('plazavea')
        // Stored for audit, just never read.
        ->and(PriceObservation::query()->where('is_quarantined', true)->count())->toBe(1);
});

it('reads only the requested products', function () {
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    priceRepository()->upsertObservation(priceDraft($a->id));
    priceRepository()->upsertObservation(priceDraft($b->id));

    $quotes = priceRepository()->quotesFor([$a->id], ['plazavea' => CarbonImmutable::parse('2026-09-01')]);

    expect($quotes)->toHaveCount(1)
        ->and($quotes[0]->productId)->toBe($a->id);
});

it('does not touch the database when there is nothing to ask for', function () {
    $product = Product::factory()->create();
    priceRepository()->upsertObservation(priceDraft($product->id));

    DB::enableQueryLog();
    DB::flushQueryLog();

    $noProducts = priceRepository()->quotesFor([], ['plazavea' => CarbonImmutable::parse('2026-09-01')]);
    $noSources = priceRepository()->quotesFor([$product->id], []);

    expect($noProducts)->toBe([])
        ->and($noSources)->toBe([])
        ->and(DB::getQueryLog())->toBe([]);
});

// --------------------------------------------------------------- purge / runs

it('deletes every row of one source in chunks and reports how many', function () {
    $products = Product::factory()->count(5)->create();
    foreach ($products as $product) {
        priceRepository()->upsertObservation(priceDraft($product->id, source: 'plazavea'));
    }
    priceRepository()->upsertObservation(priceDraft($products[0]->id, source: 'inei'));

    $deleted = priceRepository()->deleteBySource('plazavea', chunkSize: 2);

    expect($deleted)->toBe(5)
        ->and(PriceObservation::query()->where('source', 'plazavea')->count())->toBe(0)
        ->and(PriceObservation::query()->where('source', 'inei')->count())->toBe(1);
});

it('reports zero when there is nothing left to delete', function () {
    expect(priceRepository()->deleteBySource('plazavea'))->toBe(0);
});

it('records a finished run row without any price in it', function () {
    $run = priceRepository()->recordRun('plazavea', 'all', PriceRunStatus::Purged, rowsWritten: 0, details: ['deleted' => 40]);

    $stored = PriceIngestionRun::query()->findOrFail($run->id);

    expect($stored->source)->toBe('plazavea')
        ->and($stored->unit_key)->toBe('all')
        ->and($stored->status)->toBe(PriceRunStatus::Purged)
        ->and($stored->details)->toBe(['deleted' => 40])
        ->and($stored->started_at)->not->toBeNull()
        ->and($stored->finished_at)->not->toBeNull()
        ->and($stored->error)->toBeNull();
});

// ------------------------------------------------------------ run lifecycle

it('opens a run as running and closes it with its outcome', function () {
    $run = priceRepository()->startRun('inei', 'all');

    expect($run->status)->toBe(PriceRunStatus::Running)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->toBeNull();

    $closed = priceRepository()->finishRun($run, PriceRunStatus::Partial, 33, 1, null, ['edition' => '8596356']);

    expect(PriceIngestionRun::query()->count())->toBe(1)
        ->and($closed->fresh()->status)->toBe(PriceRunStatus::Partial)
        ->and($closed->fresh()->finished_at)->not->toBeNull()
        ->and($closed->fresh()->rows_written)->toBe(33)
        ->and($closed->fresh()->rows_rejected)->toBe(1)
        ->and($closed->fresh()->details)->toBe(['edition' => '8596356']);
});

it('records the error of a failed run', function () {
    $run = priceRepository()->startRun('plazavea', 'palta');

    priceRepository()->finishRun($run, PriceRunStatus::Failed, error: 'HTTP 500');

    expect($run->fresh()->status)->toBe(PriceRunStatus::Failed)->and($run->fresh()->error)->toBe('HTTP 500');
});

// ------------------------------------------------------- catalogue lookup

it('finds seeded catalogue products by slug and never a private or inactive one', function () {
    $papa = Product::factory()->create(['user_id' => null, 'slug' => 'papa-blanca', 'unit' => 'kg']);
    Product::factory()->create(['user_id' => null, 'slug' => 'palta', 'unit' => 'unidad', 'is_active' => false]);
    Product::factory()->ownedBy(User::factory()->create()->id)->create(['unit' => 'kg']);

    $found = priceRepository()->productsBySlug(['papa-blanca', 'palta', 'yuca']);

    expect(array_keys($found))->toBe(['papa-blanca'])
        ->and($found['papa-blanca']->id)->toBe($papa->id)
        ->and($found['papa-blanca']->unit)->toBe('kg')
        ->and(priceRepository()->productsBySlug([]))->toBe([]);
});

// ------------------------------------------------------ edition bookkeeping

it('knows whether a source already stored a reference', function () {
    PriceObservation::factory()->create(['source' => 'inei', 'source_ref' => '8596356']);

    expect(priceRepository()->hasObservationWithRef('inei', '8596356'))->toBeTrue()
        ->and(priceRepository()->hasObservationWithRef('inei', '1'))->toBeFalse()
        ->and(priceRepository()->hasObservationWithRef('plazavea', '8596356'))->toBeFalse();
});
