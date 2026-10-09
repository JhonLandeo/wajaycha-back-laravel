<?php

declare(strict_types=1);

use App\Services\Prices\Normalization\Dimension;
use App\Services\Prices\Normalization\PackSizeParser;

/**
 * Pack sizes as Plaza Vea prints them in a product name. Grams and millilitres
 * become kilograms and litres here — nowhere else.
 *
 * @return list<array{string, string}> [dimension, quantity] in order of appearance
 */
function packSizes(string $name): array
{
    return array_map(
        fn ($m): array => [$m->dimension->value, $m->quantity],
        (new PackSizeParser)->measures($name),
    );
}

it('reads grams and kilograms as kilograms', function (string $name, string $quantity) {
    expect(packSizes($name))->toBe([['mass', $quantity]]);
})->with([
    ['Arroz Extra COSTEÑO Bolsa 750g', '0.75'],
    ['Pollo Entero PERDIX Bolsa 1.4kg', '1.4'],
    ['Sal de Maras Molida OLI21 Bolsa 1.25Kg', '1.25'],
    ['Sal LOBOS Parillera Frasco 750Gr', '0.75'],
    ['Arroz Superior PAISANA Bolsa 1Kg', '1'],
    ['Sal de Mesa BELL\'S Bolsa 1 Kg', '1'],
    ['Cafe molido Bolsa 1,5 kg', '1.5'],
]);

it('reads millilitres and litres as litres', function (string $name, string $quantity) {
    expect(packSizes($name))->toBe([['volume', $quantity]]);
})->with([
    ['Aceite Vegetal PRIMOR Botella 900ml', '0.9'],
    ['Aceite Vegetal COCINERO Botella 1L', '1'],
    ['Aceite Girasol Botella 1 Lt', '1'],
    ['Aceite Vegetal Botella 2 litros', '2'],
]);

it('reads unit counts, with or without the leading x', function (string $name, string $quantity) {
    expect(packSizes($name))->toBe([['count', $quantity]]);
})->with([
    ['Huevos Pardos BELL\'S Bandeja 30un', '30'],
    ['Huevos Pardos BELL\'S Paquete 8und', '8'],
    ['Pan de molde x 6 und', '6'],
    ['Pack 2u Arroz', '2'],
    ['Palta Hass Madura Paquete 3 unidades', '3'],
]);

it('returns every size in the name, in order of appearance', function () {
    expect(packSizes('Leche Evaporada Entera GLORIA Lata 390g Paquete 6un'))
        ->toBe([['mass', '0.39'], ['count', '6']])
        ->and(packSizes('Rollo de papel 12un x 40m'))->toBe([['count', '12']]);
});

it('finds nothing in a name without a size or with a bare "x kg"', function (string $name) {
    expect(packSizes($name))->toBe([]);
})->with([
    'Palta Hass x kg',
    'Pollo Entero Fresco con Menudencia x kg',
    'Sal BIOSAL 50% Menos Sodio',
    'Pan Francés x und.',
]);

it('does not mistake letters inside words for units', function () {
    // "5 g" inside "Mix 5 granos" is not five grams; "2 lentejas" is not litres.
    expect(packSizes('Mix 5 granos andinos'))->toBe([])
        ->and(packSizes('Bolsa 2 lentejas'))->toBe([]);
});

it('keeps the dimension as an enum value the normalizer understands', function () {
    $measures = (new PackSizeParser)->measures('Arroz 750g');

    expect($measures[0]->dimension)->toBe(Dimension::Mass)->and($measures[0]->bulk)->toBeFalse();
});
