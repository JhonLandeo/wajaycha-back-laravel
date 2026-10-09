<?php

declare(strict_types=1);

use App\Actions\Prices\IngestPriceUnitAction;
use App\Enums\PriceBasis;
use App\Enums\PriceKind;
use App\Enums\PriceRunStatus;
use App\Exceptions\Prices\PriceSourceNoData;
use App\Models\PriceIngestionRun;
use App\Models\PriceObservation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Tests\Support\PriceSeed;

/**
 * EMMSA end to end with the network faked (spec "Layer 2 - wholesale trend
 * source"): one request for the whole basket, always for D-1, wholesale rows per
 * kilogram, one run row for the day.
 */
function emmsaHtml(string $name = 'rpt07-2026-10-07'): string
{
    return (string) file_get_contents(dirname(__DIR__, 3)."/Fixtures/prices/emmsa/{$name}.html");
}

function ingestEmmsa(string $asOf = '2026-10-08'): PriceIngestionRun
{
    return app(IngestPriceUnitAction::class)->execute('emmsa', 'all', CarbonImmutable::parse($asOf, 'America/Lima'));
}

beforeEach(function () {
    $this->products = PriceSeed::products();
});

it('asks once for the whole basket of the previous day, all varieties, with the identifying User-Agent', function () {
    Http::fake(['old.emmsa.com.pe/*' => Http::response(emmsaHtml())]);
    config(['prices.user_agent' => 'WajaychaPriceBot/0.1 (test)']);

    ingestEmmsa('2026-10-08');

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        $codes = explode(',', (string) $request['vprod']);
        sort($codes);

        return $request->method() === 'POST'
            && $request->url() === 'https://old.emmsa.com.pe/emmsa_spv/app/reportes/ajax/rpt07_gettable_new_web.php'
            && $request['vid_tipo'] === '1'
            && $request['vvari'] === ''
            && $request['vfecha'] === '07/10/2026'
            && $codes === ['02', '03', '12', '14', '30', '38', '48', '52', '53', '73', '78']
            && $request->header('User-Agent') === ['WajaychaPriceBot/0.1 (test)'];
    });
});

it('stores one wholesale row per mapped product, per kilogram, dated D-1', function () {
    Http::fake(['old.emmsa.com.pe/*' => Http::response(emmsaHtml())]);

    $run = ingestEmmsa();

    $papa = PriceObservation::query()->where('product_id', $this->products['papa-blanca']->id)->sole();
    expect($papa->source)->toBe('emmsa')
        ->and($papa->price_kind)->toBe(PriceKind::Wholesale)
        ->and($papa->unit)->toBe('kg')
        ->and($papa->unit_price)->toBe('1.2300')
        ->and($papa->price_min)->toBe('1.1000')
        ->and($papa->price_max)->toBe('1.3000')
        ->and($papa->basis)->toBe(PriceBasis::Measured)
        ->and($papa->period_start->toDateString())->toBe('2026-10-07')
        ->and($papa->period_end->toDateString())->toBe('2026-10-07')
        ->and($papa->source_ref)->toBe('emmsa:2026-10-07');

    $ajo = PriceObservation::query()->where('product_id', $this->products['ajo']->id)->sole();
    expect($ajo->unit_price)->toBe('3.4400')->and($ajo->sample_size)->toBe(2);

    $mapped = count(PriceSeed::mappedSlugs('emmsa'));
    expect(PriceObservation::query()->count())->toBe($mapped)
        ->and($run->status)->toBe(PriceRunStatus::Success)
        ->and($run->unit_key)->toBe('all')
        ->and($run->rows_written)->toBe($mapped)
        ->and($run->details)->toMatchArray(['day' => '2026-10-07']);
});

it('writes exactly one run row for the day', function () {
    Http::fake(['old.emmsa.com.pe/*' => Http::response(emmsaHtml())]);

    ingestEmmsa();

    expect(PriceIngestionRun::query()->where('source', 'emmsa')->count())->toBe(1);
});

it('is idempotent: running the same day again updates instead of duplicating', function () {
    Http::fake(['old.emmsa.com.pe/*' => Http::response(emmsaHtml())]);

    ingestEmmsa();
    ingestEmmsa();

    expect(PriceObservation::query()->count())->toBe(count(PriceSeed::mappedSlugs('emmsa')))
        ->and(PriceIngestionRun::query()->count())->toBe(2);
});

it('fails the run by name when the day has no table rows, so the fallback can fill it', function () {
    Exceptions::fake();
    Http::fake(['old.emmsa.com.pe/*' => Http::response(emmsaHtml('rpt07-empty-2026-10-08'))]);

    $run = ingestEmmsa('2026-10-09');

    expect($run->status)->toBe(PriceRunStatus::Failed)
        ->and($run->error)->toContain('emmsa')->toContain('2026-10-08')
        ->and(PriceObservation::query()->count())->toBe(0);
    Exceptions::assertReported(PriceSourceNoData::class);
});

it('fails the run on a server error and leaves earlier observations alone', function () {
    Exceptions::fake();
    $earlier = PriceObservation::factory()->wholesale()->create([
        'product_id' => $this->products['papa-blanca']->id,
        'unit_price' => '1.0000',
        'period_start' => '2026-10-06',
        'period_end' => '2026-10-06',
    ]);
    Http::fake(['old.emmsa.com.pe/*' => Http::response('boom', 500)]);

    $run = ingestEmmsa();

    expect($run->status)->toBe(PriceRunStatus::Failed)
        ->and($run->error)->toContain('500')
        ->and($earlier->fresh()->unit_price)->toBe('1.0000');
});

it('counts the products the report did not list instead of failing', function () {
    $html = (string) preg_replace('#<tr>\s*<td>CAMOTE.*?</tr>#s', '', emmsaHtml());
    Http::fake(['old.emmsa.com.pe/*' => Http::response($html)]);

    $run = ingestEmmsa();

    expect($run->status)->toBe(PriceRunStatus::Success)
        ->and($run->rows_rejected)->toBe(1)
        ->and($run->details['rejections'])->toBe(['no_matching_variety' => 1])
        ->and(PriceObservation::query()->where('product_id', $this->products['camote']->id)->exists())->toBeFalse();
});

it('does not fetch while the source is disabled', function () {
    config(['prices.sources.emmsa.enabled' => false]);
    Http::fake();

    $run = ingestEmmsa();

    expect($run->status)->toBe(PriceRunStatus::Skipped);
    Http::assertNothingSent();
});
