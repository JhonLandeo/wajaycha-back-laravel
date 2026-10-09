<?php

declare(strict_types=1);

namespace App\Services\Prices\Parsers;

use App\DTOs\Prices\VtexCandidate;
use App\Exceptions\Prices\PriceSourceFormatChanged;

/**
 * VTEX legacy catalogue search (`/api/catalog_system/pub/products/search`): a
 * JSON list of products, each with items, each with sellers. Only the fields
 * needed to price an item are read; images, descriptions and brands are never
 * touched, let alone stored.
 *
 * An empty list is a valid answer ("nothing matched"). Anything that is not a
 * list — an HTML error page, a JSON object — is a changed shape and fails by
 * name, so the run is `failed` instead of silently empty.
 */
final class VtexSearchParser
{
    /**
     * @return list<VtexCandidate>
     */
    public function parse(string $json): array
    {
        $products = json_decode($json, true);

        if (! is_array($products) || ($products !== [] && ! array_is_list($products))) {
            throw PriceSourceFormatChanged::missing('plazavea', 'a product list');
        }

        $candidates = [];

        foreach ($products as $product) {
            $name = (string) ($product['productName'] ?? '');

            foreach ((array) ($product['items'] ?? []) as $item) {
                $offer = $item['sellers'][0]['commertialOffer'] ?? null;

                if (! is_array($offer)) {
                    continue;
                }

                $candidates[] = new VtexCandidate(
                    name: $name,
                    itemName: (string) ($item['name'] ?? ''),
                    measurementUnit: strtolower((string) ($item['measurementUnit'] ?? '')),
                    price: $this->money($offer['Price'] ?? 0),
                    listPrice: $this->money($offer['ListPrice'] ?? 0),
                    isAvailable: (bool) ($offer['IsAvailable'] ?? false),
                    availableQuantity: (int) ($offer['AvailableQuantity'] ?? 0),
                    skuId: (string) ($item['itemId'] ?? ''),
                );
            }
        }

        return $candidates;
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }
}
