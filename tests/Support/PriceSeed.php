<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Product;

/**
 * Catalogue products for the ingestion tests. The unit of each comes from
 * `config/shopping.php`, so a test prices the same product the seeder would
 * create (a palta is bought by the unit, a papa by the kilo).
 */
final class PriceSeed
{
    /**
     * @param  string[]  $slugs  empty means the whole catalogue
     * @return array<string, Product> keyed by slug
     */
    public static function products(array $slugs = []): array
    {
        $products = [];

        foreach ((array) config('shopping.catalogue') as $entry) {
            if ($slugs !== [] && ! in_array($entry['slug'], $slugs, true)) {
                continue;
            }

            $products[$entry['slug']] = Product::factory()->create([
                'user_id' => null,
                'slug' => $entry['slug'],
                'name' => $entry['name'],
                'unit' => $entry['unit'],
            ]);
        }

        return $products;
    }

    /**
     * The slugs a source maps, straight from the mapping config.
     *
     * @return string[]
     */
    public static function mappedSlugs(string $source): array
    {
        return array_keys(array_filter(
            (array) config('prices.mapping'),
            fn (array $entry): bool => isset($entry[$source]),
        ));
    }
}
