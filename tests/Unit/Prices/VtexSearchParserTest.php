<?php

declare(strict_types=1);

use App\Exceptions\Prices\PriceSourceFormatChanged;
use App\Services\Prices\Parsers\VtexSearchParser;

function vtexFixture(string $slug): string
{
    return (string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/prices/vtex/{$slug}.json");
}

it('flattens a legacy VTEX search into one candidate per item with its first offer', function () {
    $candidates = (new VtexSearchParser)->parse(vtexFixture('palta'));

    expect($candidates)->toHaveCount(7)
        ->and($candidates[0]->name)->toBe('Palta Hass x kg')
        ->and($candidates[0]->measurementUnit)->toBe('kg')
        ->and($candidates[0]->price)->toBe('7.9900')
        ->and($candidates[0]->listPrice)->toBe('7.9900')
        ->and($candidates[0]->isAvailable)->toBeTrue()
        ->and($candidates[0]->availableQuantity)->toBe(45)
        ->and($candidates[2]->name)->toContain('Bandeja 500g')
        ->and($candidates[2]->measurementUnit)->toBe('un')
        ->and($candidates[5]->isAvailable)->toBeFalse()
        ->and($candidates[5]->availableQuantity)->toBe(0);
});

it('keeps the list price apart from the selling price', function () {
    $candidates = (new VtexSearchParser)->parse(vtexFixture('huevos'));

    // Huevos Pardos BELL'S Bandeja 30un: 15.90 selling, 17.90 list.
    expect($candidates[0]->price)->toBe('15.9000')->and($candidates[0]->listPrice)->toBe('17.9000');
});

it('treats an empty answer as no candidates, not as a failure', function () {
    expect((new VtexSearchParser)->parse('[]'))->toBe([]);
});

it('skips an item that has no seller instead of inventing a price', function () {
    $json = json_encode([['productId' => '1', 'productName' => 'Papa', 'items' => [
        ['itemId' => '1', 'name' => 'Papa', 'measurementUnit' => 'kg', 'unitMultiplier' => 1, 'sellers' => []],
        ['itemId' => '2', 'name' => 'Papa 2', 'measurementUnit' => 'kg', 'unitMultiplier' => 1, 'sellers' => [
            ['commertialOffer' => ['Price' => 2.5, 'ListPrice' => 2.5, 'IsAvailable' => true, 'AvailableQuantity' => 9]],
        ]],
    ]]]);

    $candidates = (new VtexSearchParser)->parse((string) $json);

    expect($candidates)->toHaveCount(1)->and($candidates[0]->itemName)->toBe('Papa 2');
});

it('fails by name when the answer is not a product list', function (string $body) {
    expect(fn () => (new VtexSearchParser)->parse($body))->toThrow(PriceSourceFormatChanged::class);
})->with([
    'html' => ['<html>Bad Request! Scripts are not allowed!</html>'],
    'an object' => ['{"error":"x"}'],
]);
