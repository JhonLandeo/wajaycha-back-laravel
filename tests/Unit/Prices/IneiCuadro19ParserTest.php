<?php

declare(strict_types=1);

use App\Exceptions\Prices\PriceSourceFormatChanged;
use App\Services\Prices\Parsers\IneiCuadro19Parser;

/**
 * INEI Cuadro N.19 as smalot hands it over (spec "Layer 1 - INEI Cuadro N.19").
 * The real August 2026 pages are the fixture; the synthetic cases below build
 * the same shape by hand so each defect the spike found has its own case.
 */
function ineiParser(float $maxChange = 0.6): IneiCuadro19Parser
{
    return new IneiCuadro19Parser($maxChange);
}

function ineiFixtureText(): string
{
    return str_replace('=====PAGE=====', "\n", (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/prices/inei/cuadro19-2026-08.txt'));
}

/**
 * @param  list<string>  $cells  thirteen monthly cells, oldest first
 */
function ineiRow(string $label, string $unit, array $cells): string
{
    return $label."\t".$unit.' '.implode(' ', $cells).' ';
}

/**
 * @param  list<string>  $rows
 */
function ineiPage(array $rows, string $period = 'AGOSTO 2025 - AGOSTO 2026'): string
{
    return "PRINCIPALES PRODUCTOS UNIDAD DE\nMEDIDA AGO. SET. OCT. NOV. DIC. ENE. FEB. MAR. ABR. MAY. JUN. JUL. AGO.\t"
        .implode("\n", $rows)."\nCUADRO Nº19: PRECIOS PROMEDIO MENSUAL DE LOS PRINCIPALES PRODUCTOS\n{$period}\n";
}

/**
 * @return list<string>
 */
function ineiCells(string $previous, string $latest): array
{
    return [...array_fill(0, 11, $previous), $previous, $latest];
}

it('reads the real August 2026 pages: 98 rows and the published figures', function () {
    $result = ineiParser()->parse(ineiFixtureText());

    $byLabel = [];
    foreach ($result->rows as $row) {
        $byLabel[$row->label] = $row;
    }

    expect($result->rows)->toHaveCount(98)
        ->and($result->rejections)->toBe([])
        ->and($byLabel['POLLO EVISCERADO']->price)->toBe('10.2900')
        ->and($byLabel['POLLO EVISCERADO']->unit)->toBe('KILOGRAMO')
        ->and($byLabel['PAPA BLANCA']->price)->toBe('2.6100')
        ->and($byLabel['LECHE EVAPORADA']->unit)->toBe('LATA G,')
        ->and($byLabel['AJÍ AMARILLO MOLIDO']->price)->toBe('12.5100');
});

it('takes the period from the table header, never from anything else', function () {
    $august = ineiParser()->parse(ineiFixtureText());
    $march = ineiParser()->parse(ineiPage([ineiRow('PAPA BLANCA', 'KILOGRAMO', ineiCells('2,50', '2,61'))], 'MARZO 2025 - MARZO 2026'));

    expect($august->periodStart->toDateString())->toBe('2026-08-01')
        ->and($august->periodEnd->toDateString())->toBe('2026-08-31')
        ->and($march->periodStart->toDateString())->toBe('2026-03-01')
        ->and($march->periodEnd->toDateString())->toBe('2026-03-31');
});

it('repairs a doubled comma and a mixed decimal separator', function () {
    $cells = ineiCells('4,,86', '4,,90');
    $cells[5] = '9.72'; // the document itself mixes "." and "," decimals
    $result = ineiParser()->parse(ineiPage([ineiRow('PAPA BLANCA', 'KILOGRAMO', $cells)]));

    expect($result->rows)->toHaveCount(1)
        ->and($result->rows[0]->price)->toBe('4.9000')
        ->and($result->rows[0]->previousPrice)->toBe('4.8600');
});

it('rejects a row whose latest cell is not a two-decimal price and keeps the others', function () {
    $result = ineiParser()->parse(ineiPage([
        ineiRow('PAPA BLANCA', 'KILOGRAMO', ineiCells('2,50', '3.330')),
        ineiRow('CAMOTE AMARILLO', 'KILOGRAMO', ineiCells('3,00', '3,10')),
    ]));

    expect($result->rows)->toHaveCount(1)
        ->and($result->rows[0]->label)->toBe('CAMOTE AMARILLO')
        ->and($result->rejections)->toHaveCount(1)
        ->and($result->rejections[0]->subject)->toBe('PAPA BLANCA')
        ->and($result->rejections[0]->reason)->toBe('unparseable_price');
});

it('splits two products printed on the same line and unglues numbers', function () {
    $left = ineiRow('PAPA BLANCA', 'KILOGRAMO', ['2,10', '2,11', '2,12', '2,13', '2,14', '2,15', '2,16', '2.172,18', '2,19', '2,20', '2,21', '2,22']);
    $right = ineiRow('CAMOTE AMARILLO', 'KILOGRAMO', ineiCells('3,00', '3,10'));
    $result = ineiParser()->parse(ineiPage([$left.$right]));

    expect(array_map(fn ($r) => $r->label, $result->rows))->toBe(['PAPA BLANCA', 'CAMOTE AMARILLO'])
        ->and($result->rows[0]->price)->toBe('2.2200')
        ->and($result->rows[1]->price)->toBe('3.1000');
});

it('removes footnote markers from a label that is split across lines', function () {
    $row = "AZUCAR BLANCA \n1/ \n".'KILOGRAMO '.implode(' ', ineiCells('3,70', '3,80')).' ';
    $result = ineiParser()->parse(ineiPage([$row]));

    expect($result->rows)->toHaveCount(1)
        ->and($result->rows[0]->label)->toBe('AZUCAR BLANCA')
        ->and($result->rows[0]->unit)->toBe('KILOGRAMO');
});

it('quarantines a price that jumps beyond the bound and not one inside it', function () {
    $result = ineiParser(0.6)->parse(ineiPage([
        ineiRow('ARVEJA VERDE', 'KILOGRAMO', ineiCells('6,75', '13,91')),
        ineiRow('PAPA BLANCA', 'KILOGRAMO', ineiCells('2,50', '3,10')),
    ]));

    expect($result->rows[0]->label)->toBe('ARVEJA VERDE')
        ->and($result->rows[0]->isQuarantined)->toBeTrue()
        ->and($result->rows[1]->isQuarantined)->toBeFalse();
});

it('honours a tighter configured bound', function () {
    $rows = [ineiRow('PAPA BLANCA', 'KILOGRAMO', ineiCells('2,50', '3,10'))]; // +24%

    expect(ineiParser(0.2)->parse(ineiPage($rows))->rows[0]->isQuarantined)->toBeTrue()
        ->and(ineiParser(0.3)->parse(ineiPage($rows))->rows[0]->isQuarantined)->toBeFalse();
});

it('fails by name when the table header is missing', function () {
    expect(fn () => ineiParser()->parse('texto sin el cuadro'))->toThrow(PriceSourceFormatChanged::class);
});
