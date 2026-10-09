<?php

declare(strict_types=1);

use App\Actions\Prices\IngestPriceUnitAction;
use App\Enums\PriceKind;
use App\Enums\PriceRunStatus;
use App\Models\PriceIngestionRun;
use App\Models\PriceObservation;
use App\Services\Prices\Sources\PdfPageReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakePdfPageReader;
use Tests\Support\PriceSeed;

/**
 * GMML, the wholesale fallback (spec "Layer 2"): only when EMMSA did not cover
 * D-1, only for D-1, never mixed with an EMMSA row of the same product and day,
 * converted per kilogram with "Equiv. en kg".
 */
function gmmlCollectionHtml(string $month = 'octubre-2026'): string
{
    return '<a href="/institucion/midagri/informes-publicaciones/8435296-reporte-de-ingreso-y-precios-en-el-gran-mercado-mayorista-de-lima-gmml-agosto-2026">Agosto</a>'
        .'<a href="/institucion/midagri/informes-publicaciones/8666398-reporte-de-ingreso-y-precios-en-el-gran-mercado-mayorista-de-lima-gmml-'.$month.'">Mes</a>';
}

function gmmlMonthHtml(): string
{
    $links = '';
    foreach (['05-10-2026', '06-10-2026', '07-10-2026'] as $date) {
        $links .= '<a href="https://cdn.www.gob.pe/uploads/document/file/107/8666398-reporte-de-ingreso-y-precios-en-el-gran-mercado-mayorista-de-lima-'.$date.'.pdf?v=1791387418">'.$date.'</a>';
    }

    return $links;
}

function fakeGmmlNetwork(?string $month = null, ?string $monthHtml = null): void
{
    Http::fake([
        'www.gob.pe/institucion/midagri/colecciones/*' => Http::response(gmmlCollectionHtml($month ?? 'octubre-2026')),
        'www.gob.pe/institucion/midagri/informes-publicaciones/*' => Http::response($monthHtml ?? gmmlMonthHtml()),
        'cdn.www.gob.pe/*' => Http::response('%PDF-fake'),
    ]);
}

function fakeGmmlPdf(?string $text = null): FakePdfPageReader
{
    $reader = new FakePdfPageReader(explode('=====PAGE', $text ?? (string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/prices/gmml/boletin-2026-10-07.txt')));
    app()->instance(PdfPageReader::class, $reader);

    return $reader;
}

function ingestGmml(string $asOf = '2026-10-08'): PriceIngestionRun
{
    return app(IngestPriceUnitAction::class)->execute('gmml', 'all', CarbonImmutable::parse($asOf, 'America/Lima'));
}

function emmsaRan(PriceRunStatus $status, string $day = '2026-10-07'): void
{
    PriceIngestionRun::factory()->create(['source' => 'emmsa', 'unit_key' => 'all', 'status' => $status, 'details' => ['day' => $day]]);
}

beforeEach(function () {
    $this->products = PriceSeed::products();
});

it('fills D-1 per kilogram from the bulletin\'s own day when EMMSA has no successful run', function () {
    fakeGmmlNetwork();
    fakeGmmlPdf();
    emmsaRan(PriceRunStatus::Failed);

    $run = ingestGmml();

    $papa = PriceObservation::query()->where('product_id', $this->products['papa-blanca']->id)->sole();
    expect($papa->source)->toBe('gmml')
        ->and($papa->price_kind)->toBe(PriceKind::Wholesale)
        ->and($papa->unit)->toBe('kg')
        // "Hoy" column of the 07/10 bulletin: 1.08 per kilogram.
        ->and($papa->unit_price)->toBe('1.0800')
        ->and($papa->period_start->toDateString())->toBe('2026-10-07')
        ->and($papa->period_end->toDateString())->toBe('2026-10-07')
        ->and($papa->source_ref)->toBe('gmml:2026-10-07');

    // 83.75 per 18 kg crate, 91.25 per 27 kg small crate, 117.50 per 65 kg small sack.
    $byProduct = fn (string $slug): string => PriceObservation::query()->where('product_id', $this->products[$slug]->id)->sole()->unit_price;
    expect($byProduct('rocoto'))->toBe('4.6528')
        ->and($byProduct('tomate'))->toBe('3.3796')
        ->and($byProduct('zanahoria'))->toBe('1.8077')
        ->and($run->status)->toBe(PriceRunStatus::Success)
        ->and($run->unit_key)->toBe('all')
        ->and($run->details)->toMatchArray(['day' => '2026-10-07']);
});

it('converts a 25 kg sack priced 50 to 2.00 per kilogram', function () {
    fakeGmmlNetwork();
    fakeGmmlPdf("BOLETIN DIARIO\nmiércoles, 7 de Octubre de 2026\nPapa Blanca\t1 : : :Saco 25.00 50.00 50.00 50.00\n");

    ingestGmml();

    expect(PriceObservation::query()->where('product_id', $this->products['papa-blanca']->id)->sole()->unit_price)->toBe('2.0000');
});

it('is skipped, without a single request, when EMMSA already covered D-1', function (PriceRunStatus $status) {
    fakeGmmlNetwork();
    fakeGmmlPdf();
    emmsaRan($status);

    $run = ingestGmml();

    expect($run->status)->toBe(PriceRunStatus::Skipped)
        ->and($run->details)->toMatchArray(['reason' => 'emmsa_covers_day', 'day' => '2026-10-07'])
        ->and(PriceObservation::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([PriceRunStatus::Success, PriceRunStatus::Partial]);

it('is not blocked by an EMMSA run of another day or by a failed one', function () {
    fakeGmmlNetwork();
    fakeGmmlPdf();
    emmsaRan(PriceRunStatus::Success, '2026-10-06');
    emmsaRan(PriceRunStatus::Failed, '2026-10-07');

    expect(ingestGmml()->status)->toBe(PriceRunStatus::Success);
});

it('never mixes: a product EMMSA already has for that day keeps the EMMSA row only', function () {
    fakeGmmlNetwork();
    fakeGmmlPdf();
    emmsaRan(PriceRunStatus::Failed);
    PriceObservation::factory()->wholesale()->create([
        'product_id' => $this->products['papa-blanca']->id,
        'unit_price' => '1.2300',
        'period_start' => '2026-10-07',
        'period_end' => '2026-10-07',
    ]);

    $run = ingestGmml();

    expect(PriceObservation::query()->where('product_id', $this->products['papa-blanca']->id)->count())->toBe(1)
        ->and(PriceObservation::query()->where('product_id', $this->products['papa-blanca']->id)->sole()->source)->toBe('emmsa')
        ->and(PriceObservation::query()->where('source', 'gmml')->count())->toBe($run->rows_written)
        ->and($run->details['covered_by_emmsa'])->toBe(1);
});

it('finds the month page by the name of the month of D-1, including setiembre', function () {
    fakeGmmlNetwork('setiembre-2026', '<a href="https://cdn.www.gob.pe/uploads/document/file/1/8-reporte-de-ingreso-y-precios-en-el-gran-mercado-mayorista-de-lima-30-09-2026.pdf?v=1">30</a>');
    fakeGmmlPdf("BOLETIN DIARIO\nmartes, 30 de Setiembre de 2026\nPapa Blanca\t1 : : :Kilogramo 1.00 1.10 1.08 1.05\n");

    $run = ingestGmml('2026-10-01');

    expect($run->status)->toBe(PriceRunStatus::Success)
        ->and($run->details)->toMatchArray(['day' => '2026-09-30'])
        ->and(PriceObservation::query()->sole()->period_start->toDateString())->toBe('2026-09-30');
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'lima-30-09-2026.pdf'));
});

it('is skipped, not failed, when no bulletin was published for the day', function () {
    fakeGmmlNetwork();
    fakeGmmlPdf();

    // Saturday 10/10 has no PDF in the month page.
    $run = ingestGmml('2026-10-11');

    expect($run->status)->toBe(PriceRunStatus::Skipped)
        ->and($run->details)->toMatchArray(['reason' => 'no_bulletin_for_day', 'day' => '2026-10-10'])
        ->and(PriceObservation::query()->count())->toBe(0);
});

it('fails by name when the bulletin is dated differently from its file name', function () {
    Exceptions::fake();
    fakeGmmlNetwork();
    fakeGmmlPdf("BOLETIN DIARIO\nmartes, 6 de Octubre de 2026\nPapa Blanca\t1 : : :Kilogramo 1.00 1.10 1.08 1.05\n");

    $run = ingestGmml();

    expect($run->status)->toBe(PriceRunStatus::Failed)
        ->and($run->error)->toContain('gmml')
        ->and(PriceObservation::query()->count())->toBe(0);
});

it('fails when the collection has no page for the month', function () {
    Exceptions::fake();
    fakeGmmlNetwork('agosto-2026');
    fakeGmmlPdf();

    expect(ingestGmml('2026-11-02')->status)->toBe(PriceRunStatus::Failed);
});

it('does not fetch while the source is disabled', function () {
    config(['prices.sources.gmml.enabled' => false]);
    Http::fake();

    expect(ingestGmml()->status)->toBe(PriceRunStatus::Skipped);
    Http::assertNothingSent();
});
