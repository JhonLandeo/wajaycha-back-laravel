<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds the shared grocery catalogue and nothing else.
 *
 * `Product::upsert(..., uniqueBy: ['slug'])` is deliberate — NOT the
 * `PaymentServicesSeeder::insert()` shape. That seeder duplicates Yape and
 * Plin on a second run because `insert()` has no conflict handling.
 * `products` is global and re-seeded on every deploy after `migrate`
 * (design.md, "The seeded catalogue"), so a non-idempotent write would
 * duplicate the whole catalogue on the second deployment.
 *
 * Every row here carries `slug` NOT NULL and `user_id` NULL, so the upsert's
 * unique key (`slug`) never matches a user's own private addition — those
 * rows carry `slug = NULL` and sit outside `unq_products_slug` entirely.
 *
 * Not registered in `DatabaseSeeder` and not called from a user-creation
 * observer: the catalogue is shared, so seeding it per user would insert
 * ~40 rows on every registration. It runs once per deployment via
 * `php artisan db:seed --class=ProductCatalogueSeeder`.
 */
class ProductCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $rows = array_map(
            static fn (array $item): array => [
                'user_id' => null,
                'slug' => $item['slug'],
                'name' => $item['name'],
                'unit' => $item['unit'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            config('shopping.catalogue', []),
        );

        Product::upsert($rows, uniqueBy: ['slug'], update: ['name', 'unit', 'is_active']);
    }
}
