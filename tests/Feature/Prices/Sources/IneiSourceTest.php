<?php

declare(strict_types=1);

use App\Actions\Prices\IngestPriceUnitAction;
use App\Enums\PriceBasis;
use App\Enums\PriceKind;
use App\Enums\PriceRunStatus;
use App\Exceptions\Prices\PriceSourceFormatChanged;
use App\Jobs\IngestPriceUnit;
use App\Models\PriceIngestionRun;
use App\Models\PriceObservation;
use App\Services\Prices\Sources\PdfPageReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakePdfPageReader;
use Tests\Support\PriceSeed;

/**
 * The INEI adapter end to end with the network and the PDF reader faked:
 * edition discovery, the seen-edition shortcut, mapping, normalisation,
 * quarantine and the run log (spec "Layer 1 - INEI Cuadro N.19").
 */
const INEI_COLLECTION = 'https://www.gob.pe/institucion/inei/colecciones/6630-indicadores-de-precios-de-la-economia';
const INEI_EDITION = 'https://www.gob.pe/institucion/inei/informes-publicaciones/8596356-indicadores-de-precios-de-la-economia-agosto-2026';
const INEI_PDF = 'https://cdn.www.gob.pe/uploads/document/file/10621692/8596356-indicadores-de-precios-de-la-economia-agosto-2026.pdf?v=1789480160';

function ineiCollectionHtml(): string
{
    return <<<'HTML'
        <a href="/institucion/inei/informes-publicaciones/7285018-indicadores-de-precios-de-la-economia-setiembre-2025">Setiembre 2025</a>
        <a href="/institucion/inei/informes-publicaciones/9999999-boletin-anual-indicadores-de-precios-de-la-economia-2025">Boletin anual</a>
        <a href="/institucion/inei/informes-publicaciones/8596356-indicadores-de-precios-de-la-economia-agosto-2026">Agosto 2026</a>
        <a href="/institucion/inei/colecciones/6630-indicadores-de-precios-de-la-economia?sheet=2">2</a>
        HTML;
}

function ineiEditionHtml(): string
{
    return '<img src="https://cdn.www.gob.pe/uploads/document/file/10621692/preview_8596356-indicadores-de-precios-de-la-economia-agosto-2026.jpg?v=1789480160">'
        .'<a href="'.INEI_PDF.'">Descargar</a>';
}

/**
 * @return list<string>
 */
function ineiRealPages(): array
{
    return explode('=====PAGE=====', (string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/prices/inei/cuadro19-2026-08.txt'));
}

function fakeIneiNetwork(): void
{
    Http::fake([
        'www.gob.pe/institucion/inei/colecciones/*' => Http::response(ineiCollectionHtml()),
        'www.gob.pe/institucion/inei/informes-publicaciones/*' => Http::response(ineiEditionHtml()),
        'cdn.www.gob.pe/*' => Http::response('%PDF-fake'),
    ]);
}

/**
 * @param  list<string>  $pages
 */
function fakeIneiPdf(array $pages): FakePdfPageReader
{
    $reader = new FakePdfPageReader($pages);
    app()->instance(PdfPageReader::class, $reader);

    return $reader;
}

function ingestInei(): PriceIngestionRun
{
    return app(IngestPriceUnitAction::class)->execute('inei', 'all', CarbonImmutable::parse('2026-10-08', 'America/Lima'));
}

beforeEach(function () {
    PriceSeed::products();
    // A page that mentions the table title but is not Cuadro 19 must be skipped.
    $this->decoy = "PRECIOS PROMEDIO MENSUAL DE LOS PRINCIPALES PRODUCTOS\nSin cuadro";
});

it('stores a new edition: one retail row per mapped label, period from the header', function () {
    fakeIneiNetwork();
    fakeIneiPdf([$this->decoy, ...ineiRealPages()]);

    $run = ingestInei();

    expect($run->status)->toBe(PriceRunStatus::Success)
        ->and($run->unit_key)->toBe('all')
        ->and($run->rows_written)->toBe(count(PriceSeed::mappedSlugs('inei')))
        ->and($run->rows_rejected)->toBe(0)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->details)->toMatchArray(['edition' => '8596356']);

    $row = PriceObservation::query()->whereHas('product', fn ($q) => $q->where('slug', 'papa-blanca'))->sole();
    expect($row->source)->toBe('inei')
        ->and($row->price_kind)->toBe(PriceKind::Retail)
        ->and($row->unit)->toBe('kg')
        ->and($row->unit_price)->toBe('2.6100')
        ->and($row->basis)->toBe(PriceBasis::Measured)
        ->and($row->period_start->toDateString())->toBe('2026-08-01')
        ->and($row->period_end->toDateString())->toBe('2026-08-31')
        ->and($row->source_ref)->toBe('8596356')
        ->and($row->is_quarantined)->toBeFalse();
});

it('converts a count product priced per kilo through the curated equivalence', function () {
    fakeIneiNetwork();
    fakeIneiPdf(ineiRealPages());

    ingestInei();

    // HUEVO A GRANEL is printed per kilogram; the catalogue buys eggs by the unit.
    $huevos = PriceObservation::query()->whereHas('product', fn ($q) => $q->where('slug', 'huevos'))->sole();
    expect($huevos->unit)->toBe('unidad')
        ->and($huevos->basis)->toBe(PriceBasis::Equivalence)
        // 7.69 per kilo x 0.06 kg per egg.
        ->and($huevos->unit_price)->toBe('0.4614');

    // A tin is one unit as printed.
    $atun = PriceObservation::query()->whereHas('product', fn ($q) => $q->where('slug', 'atun-en-lata'))->sole();
    expect($atun->unit)->toBe('unidad')->and($atun->unit_price)->toBe('5.7600')->and($atun->basis)->toBe(PriceBasis::Measured);
});

it('ignores the annual bulletin and picks the newest monthly edition', function () {
    fakeIneiNetwork();
    fakeIneiPdf(ineiRealPages());

    ingestInei();

    Http::assertSent(fn ($request): bool => $request->url() === INEI_EDITION);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'boletin-anual'));
});

it('skips an edition it already ingested without fetching the page or the PDF', function () {
    fakeIneiNetwork();
    fakeIneiPdf(ineiRealPages());
    PriceObservation::factory()->create(['source' => 'inei', 'source_ref' => '8596356']);

    $run = ingestInei();

    expect($run->status)->toBe(PriceRunStatus::Skipped)
        ->and($run->details)->toMatchArray(['reason' => 'edition_already_ingested', 'edition' => '8596356'])
        ->and(PriceObservation::query()->where('source', 'inei')->count())->toBe(1);

    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'cdn.www.gob.pe') || $request->url() === INEI_EDITION);
});

it('marks the run partial when a mapped row is rejected and keeps the rest', function () {
    fakeIneiNetwork();
    $pages = ineiRealPages();
    // Corrupt the latest cell of PAPA BLANCA: three decimals cannot be a price.
    $pages = array_map(fn (string $p): string => (string) preg_replace('/(PAPA BLANCA\s+KILOGRAMO(?: [\d.,]+){12}) 2,61/u', '$1 3.330', $p), $pages);
    fakeIneiPdf($pages);

    $run = ingestInei();

    expect($run->status)->toBe(PriceRunStatus::Partial)
        ->and($run->rows_rejected)->toBe(1)
        ->and($run->rows_written)->toBe(count(PriceSeed::mappedSlugs('inei')) - 1)
        ->and($run->details['rejections'])->toBe(['unparseable_price' => 1])
        ->and(PriceObservation::query()->whereHas('product', fn ($q) => $q->where('slug', 'papa-blanca'))->exists())->toBeFalse();
});

it('stores a price that jumps beyond the bound flagged as quarantined and marks the run partial', function () {
    fakeIneiNetwork();
    $pages = array_map(fn (string $p): string => (string) preg_replace('/(PAPA BLANCA\s+KILOGRAMO(?: [\d.,]+){12}) 2,61/u', '$1 9,99', $p), ineiRealPages());
    fakeIneiPdf($pages);

    $run = ingestInei();

    $papa = PriceObservation::query()->whereHas('product', fn ($q) => $q->where('slug', 'papa-blanca'))->sole();
    expect($papa->is_quarantined)->toBeTrue()
        ->and($papa->unit_price)->toBe('9.9900')
        ->and($run->status)->toBe(PriceRunStatus::Partial)
        ->and($run->details['quarantined'])->toBe(1);
});

it('records a failed run, reports it and leaves existing observations alone when the shape changed', function () {
    Exceptions::fake();
    fakeIneiNetwork();
    fakeIneiPdf(['una pagina sin el cuadro']);
    $existing = PriceObservation::factory()->create(['source' => 'inei', 'source_ref' => '7000000', 'unit_price' => '2.5000']);

    $run = ingestInei();

    expect($run->status)->toBe(PriceRunStatus::Failed)
        ->and($run->error)->toContain('inei')
        ->and($run->finished_at)->not->toBeNull()
        ->and($existing->fresh()->unit_price)->toBe('2.5000');
    Exceptions::assertReported(fn (PriceSourceFormatChanged $e): bool => str_contains($e->getMessage(), 'inei'));
});

it('records a failed run when the collection page answers 500', function () {
    Exceptions::fake();
    Http::fake(['www.gob.pe/*' => Http::response('boom', 500)]);

    $run = ingestInei();

    expect($run->status)->toBe(PriceRunStatus::Failed)
        ->and($run->error)->toContain('500')
        ->and(PriceObservation::query()->count())->toBe(0);
});

it('does not fetch anything when the source is disabled, at the action and again inside the job', function () {
    fakeIneiNetwork();
    config(['prices.sources.inei.enabled' => false]);

    $run = ingestInei();
    expect($run->status)->toBe(PriceRunStatus::Skipped)->and($run->details)->toMatchArray(['reason' => 'source_disabled']);

    // A job queued while the source was on, executed after it went off.
    IngestPriceUnit::dispatchSync('inei', 'all', '2026-10-08');

    expect(PriceIngestionRun::query()->where('status', PriceRunStatus::Skipped)->count())->toBe(2)
        ->and(PriceObservation::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('runs through the queued job with a single attempt and a timeout that covers its three fetches', function () {
    fakeIneiNetwork();
    fakeIneiPdf(ineiRealPages());

    IngestPriceUnit::dispatchSync('inei', 'all', '2026-10-08');

    $job = new IngestPriceUnit('inei', 'all', '2026-10-08');
    expect($job->tries)->toBe(1)
        ->and(PriceIngestionRun::query()->where('source', 'inei')->sole()->status)->toBe(PriceRunStatus::Success)
        ->and(PriceObservation::query()->where('source', 'inei')->count())->toBe(count(PriceSeed::mappedSlugs('inei')));
});
