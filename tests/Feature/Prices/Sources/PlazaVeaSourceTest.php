<?php

declare(strict_types=1);

use App\Actions\Prices\IngestPriceUnitAction;
use App\Enums\PriceBasis;
use App\Enums\PriceKind;
use App\Enums\PriceRunStatus;
use App\Jobs\IngestPriceUnit;
use App\Models\PriceIngestionRun;
use App\Models\PriceObservation;
use App\Services\Prices\Sources\PlazaVeaSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Tests\Support\PriceSeed;

/**
 * The Plaza Vea adapter end to end with the network faked (spec "Layer 3 - Plaza
 * Vea weekly retail"): one job per product, the kill switch, the legacy search
 * URL, the median row and the one-run-row-per-product log.
 */
function plazaVeaBody(string $slug): string
{
    return (string) file_get_contents(dirname(__DIR__, 3)."/Fixtures/prices/vtex/{$slug}.json");
}

function ingestPlazaVea(string $slug): PriceIngestionRun
{
    return app(IngestPriceUnitAction::class)->execute('plazavea', $slug, CarbonImmutable::parse('2026-10-05', 'America/Lima'));
}

beforeEach(function () {
    config(['prices.sources.plazavea.enabled' => true]);
    $this->products = PriceSeed::products();
    // Monday 05:03 in Lima.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 05:03:00', 'America/Lima'));
});

it('ships disabled and does not fetch anything while it is off', function () {
    config(['prices.sources.plazavea.enabled' => false]);
    Http::fake();

    $run = ingestPlazaVea('palta');

    expect($run->status)->toBe(PriceRunStatus::Skipped)
        ->and($run->unit_key)->toBe('palta')
        ->and($run->details)->toMatchArray(['reason' => 'source_disabled'])
        ->and(PriceObservation::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('re-checks the switch inside the queued job, after it was queued while the source was on', function () {
    Http::fake();
    config(['prices.sources.plazavea.enabled' => false]);

    IngestPriceUnit::dispatchSync('plazavea', 'palta', '2026-10-05');

    expect(PriceIngestionRun::query()->sole()->status)->toBe(PriceRunStatus::Skipped);
    Http::assertNothingSent();
});

it('asks the legacy search for the mapped term and category, spacing encoded as %20, with the identifying User-Agent', function () {
    Http::fake(['www.plazavea.com.pe/*' => Http::response(plazaVeaBody('pan'))]);
    config(['prices.user_agent' => 'WajaychaPriceBot/0.1 (test)']);

    ingestPlazaVea('papa-blanca');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://www.plazavea.com.pe/api/catalog_system/pub/products/search?')
            && str_contains($request->url(), 'ft=papa%20blanca')
            && ! str_contains($request->url(), '+')
            && str_contains($request->url(), 'fq=C:/77/818/835/')
            && str_contains($request->url(), '_from=0&_to=49')
            && $request->header('User-Agent') === ['WajaychaPriceBot/0.1 (test)'];
    });
    Http::assertSentCount(1);
});

it('stores the median offer as one retail row dated with the Lima day of the observation', function () {
    Http::fake(['www.plazavea.com.pe/*' => Http::response(plazaVeaBody('palta'))]);

    $run = ingestPlazaVea('palta');

    $row = PriceObservation::query()->sole();
    expect($row->product_id)->toBe($this->products['palta']->id)
        ->and($row->source)->toBe('plazavea')
        ->and($row->price_kind)->toBe(PriceKind::Retail)
        ->and($row->unit)->toBe('unidad')
        ->and($row->unit_price)->toBe('2.2187')
        ->and($row->reference_price)->not->toBeNull()
        ->and($row->price_min)->toBe('1.5980')
        ->and($row->price_max)->toBe('3.5600')
        ->and($row->sample_size)->toBe(4)
        ->and($row->basis)->toBe(PriceBasis::Equivalence)
        ->and($row->period_start->toDateString())->toBe('2026-10-05')
        ->and($row->period_end->toDateString())->toBe('2026-10-05')
        ->and($row->source_ref)->not->toBeEmpty()
        ->and($run->status)->toBe(PriceRunStatus::Success)
        ->and($run->unit_key)->toBe('palta')
        ->and($run->rows_written)->toBe(1)
        ->and($run->details)->toMatchArray(['accepted' => 4]);
});

it('accepts a 206 answer as well as a 200', function () {
    Http::fake(['www.plazavea.com.pe/*' => Http::response(plazaVeaBody('pan'), 206)]);

    expect(ingestPlazaVea('pan')->status)->toBe(PriceRunStatus::Success)
        ->and(PriceObservation::query()->sole()->unit_price)->toBe('0.6320');
});

it('updates the same day instead of adding a second row when a unit runs again', function () {
    $changed = json_decode(plazaVeaBody('pan'), true);
    $changed[0]['items'][0]['sellers'][0]['commertialOffer']['Price'] = 9.5;
    Http::fake(['www.plazavea.com.pe/*' => Http::sequence()->push(plazaVeaBody('pan'))->push((string) json_encode($changed))]);

    ingestPlazaVea('pan');
    ingestPlazaVea('pan');

    expect(PriceObservation::query()->count())->toBe(1)
        ->and(PriceObservation::query()->sole()->unit_price)->toBe('0.7600')
        ->and(PriceIngestionRun::query()->count())->toBe(2);
});

it('writes no observation when no offer is accepted, and the run says why', function () {
    Http::fake(['www.plazavea.com.pe/*' => Http::response('[]')]);

    $run = ingestPlazaVea('palta');

    expect($run->status)->toBe(PriceRunStatus::Success)
        ->and($run->rows_written)->toBe(0)
        ->and($run->details)->toMatchArray(['reason' => 'no_accepted_candidates'])
        ->and(PriceObservation::query()->count())->toBe(0);
});

it('counts the rejections by reason in the run log and never a price', function () {
    // Salt asked, chicken answered: four listings are out of stock and the two
    // in stock do not match the salt patterns.
    Http::fake(['www.plazavea.com.pe/*' => Http::response(plazaVeaBody('pollo-entero'))]);

    $run = ingestPlazaVea('sal');

    expect($run->rows_written)->toBe(0)
        ->and($run->rows_rejected)->toBe(6)
        ->and($run->details['rejections'])->toEqual(['not_matched' => 2, 'unavailable' => 4])
        ->and(json_encode($run->details))->not->toContain('8.9');
});

it('records a failed run, reports it and leaves earlier observations and the other units alone', function () {
    Exceptions::fake();
    $earlier = PriceObservation::factory()->create([
        'product_id' => $this->products['palta']->id,
        'source' => 'plazavea',
        'unit' => 'unidad',
        'unit_price' => '2.0000',
        'period_start' => '2026-09-28',
        'period_end' => '2026-09-28',
    ]);

    Http::fake([
        'www.plazavea.com.pe/*ft=palta*' => Http::response('boom', 500),
        'www.plazavea.com.pe/*ft=huevos*' => Http::response(plazaVeaBody('huevos')),
    ]);

    $failed = ingestPlazaVea('palta');
    $ok = ingestPlazaVea('huevos');

    expect($failed->status)->toBe(PriceRunStatus::Failed)
        ->and($failed->error)->toContain('500')
        ->and($failed->unit_key)->toBe('palta')
        ->and($earlier->fresh()->unit_price)->toBe('2.0000')
        ->and($ok->status)->toBe(PriceRunStatus::Success)
        ->and(PriceObservation::query()->where('source', 'plazavea')->count())->toBe(2)
        ->and(PriceIngestionRun::query()->count())->toBe(2);
    Exceptions::assertReportedCount(1);
});

it('gives no quote to a product the mapping leaves out and does not even ask the store', function () {
    Http::fake();

    $run = ingestPlazaVea('perejil');

    expect($run->status)->toBe(PriceRunStatus::Success)
        ->and($run->rows_written)->toBe(0)
        ->and($run->details)->toMatchArray(['reason' => 'unmapped'])
        ->and(PriceObservation::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('offers one unit per mapped product, in the order of the mapping', function () {
    $units = array_map(fn ($u): string => $u->key, app(PlazaVeaSource::class)->units(CarbonImmutable::now()));

    expect($units)->toBe(PriceSeed::mappedSlugs('plazavea'))
        ->and($units)->toContain('palta', 'huevos', 'pan')
        ->and($units)->not->toContain('perejil', 'rocoto', 'aji-amarillo')
        ->and(count($units))->toBe(37);
});

it('ships with the Plaza Vea switch off in the example environment', function () {
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)->toContain('PRICES_PLAZAVEA_ENABLED=false')
        ->and($example)->not->toContain('PRICES_PLAZAVEA_ENABLED=true');
});
