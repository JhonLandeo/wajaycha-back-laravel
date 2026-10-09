<?php

declare(strict_types=1);

use App\DTOs\Prices\GmmlRow;
use App\Exceptions\Prices\PriceSourceFormatChanged;
use App\Services\Prices\Parsers\GmmlBulletinParser;

/**
 * MIDAGRI's daily GMML bulletin as smalot reads it (spec "Layer 2", fallback).
 * Each row: product, four mass columns, unit of measure, "Equiv. en kg" and then
 * THREE price columns whose header reads "Ayer | Hoy | Ultimos 7 dias". The
 * bulletin's own date is "Hoy"; neighbouring numbers are glued
 * ("1.0014.7514.7514.75") and are split on their two decimals.
 */
function gmmlText(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/prices/gmml/boletin-2026-10-07.txt');
}

/**
 * @return array<string, GmmlRow>
 */
function gmmlRowsByLabel(): array
{
    $byLabel = [];
    foreach ((new GmmlBulletinParser)->parse(gmmlText())->rows as $row) {
        $byLabel[$row->label] = $row;
    }

    return $byLabel;
}

it('reads the bulletin date from its header, not from anything else', function () {
    expect((new GmmlBulletinParser)->parse(gmmlText())->date->toDateString())->toBe('2026-10-07')
        ->and((new GmmlBulletinParser)->parse("BOLETIN DIARIO\nlunes, 5 de Setiembre de 2026\nPapa Blanca\t1 : : :Kilogramo 1.00 1.10 1.08 1.05")->date->toDateString())->toBe('2026-09-05');
});

it('parses a plain kilogram row into its three price columns', function () {
    $papa = gmmlRowsByLabel()['Papa Blanca'];

    expect($papa->unit)->toBe('Kilogramo')
        ->and($papa->equivalentKg)->toBe('1.00')
        ->and($papa->priceYesterday)->toBe('1.10')
        ->and($papa->priceToday)->toBe('1.08')
        ->and($papa->priceWeek)->toBe('1.05');
});

it('splits glued numbers on their two decimals', function () {
    $rows = gmmlRowsByLabel();

    expect($rows['Aji Amarillo Seco']->priceToday)->toBe('14.75')
        ->and($rows['Aji Amarillo Seco']->equivalentKg)->toBe('1.00')
        ->and($rows['Aji Rocoto']->unit)->toBe('Cajon')
        ->and($rows['Aji Rocoto']->equivalentKg)->toBe('18.00')
        ->and($rows['Aji Rocoto']->priceYesterday)->toBe('76.25')
        ->and($rows['Aji Rocoto']->priceToday)->toBe('83.75')
        ->and($rows['Aji Rocoto']->priceWeek)->toBe('79.82')
        // Three-digit prices glue the same way.
        ->and($rows['Choclo (Tipo Cusco)']->equivalentKg)->toBe('42.00')
        ->and($rows['Choclo (Tipo Cusco)']->priceToday)->toBe('317.50');
});

it('reads two-word units and rows with no tab before the mass columns', function () {
    $rows = gmmlRowsByLabel();

    expect($rows['Tomate']->unit)->toBe('Cajon Chico')
        ->and($rows['Tomate']->equivalentKg)->toBe('27.00')
        ->and($rows['Tomate']->priceToday)->toBe('91.25')
        ->and($rows['Zanahoria']->unit)->toBe('Saco Chico')
        ->and($rows['Zanahoria']->priceToday)->toBe('117.50')
        ->and($rows['Cebolla China']->unit)->toBe('Atado Grande')
        ->and($rows['Arveja Verde Blanca Serrana']->priceToday)->toBe('6.53');
});

it('strips footnote markers from labels', function () {
    $rows = gmmlRowsByLabel();

    expect($rows)->toHaveKey('Papa Canchan')->toHaveKey('Papa Unica')->toHaveKey('Papa Yungay')
        ->and($rows['Papa Yungay']->priceToday)->toBe('1.08');
});

it('reads the rows of every page of the real bulletin of 07/10/2026', function () {
    $labels = array_keys(gmmlRowsByLabel());

    expect(count($labels))->toBeGreaterThan(70)
        ->and($labels)->toContain('Cebolla Cabeza Roja', 'Limon Sutil Bolsa', 'Manzana Cte/Para Agua', 'Yuca Amarilla', 'Camote Amarillo', 'Ajo Criollo O Napuri');
});

it('ignores the header lines that look nothing like a row', function () {
    $labels = array_keys(gmmlRowsByLabel());

    expect($labels)->not->toContain('BOLETIN DIARIO')->not->toContain('Productos')->not->toContain('Unidad de');
});

it('fails by name when the bulletin carries no date', function () {
    expect(fn () => (new GmmlBulletinParser)->parse("Papa Blanca\t1 : : :Kilogramo 1.00 1.10 1.08 1.05"))
        ->toThrow(PriceSourceFormatChanged::class);
});
