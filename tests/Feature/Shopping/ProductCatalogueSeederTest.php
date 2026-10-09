<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\User;
use Database\Seeders\ProductCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * design.md: "Idempotent, by upsert on the slug." Verified defect this test
 * guards against: `PaymentServicesSeeder::insert()` duplicates Yape and Plin
 * on a second run because `insert()` has no conflict handling — the seeder
 * for `products` must not repeat that mistake.
 */
it('inserts no duplicate row when run twice', function () {
    $catalogueSize = count(config('shopping.catalogue'));
    expect($catalogueSize)->toBeGreaterThan(0);

    (new ProductCatalogueSeeder)->run();
    (new ProductCatalogueSeeder)->run();

    expect(Product::whereNull('user_id')->count())->toBe($catalogueSize);
});

it('never touches a user-owned product', function () {
    $user = User::factory()->create();
    $mine = Product::factory()->ownedBy($user->id)->create(['name' => 'Mi producto casero']);

    (new ProductCatalogueSeeder)->run();

    $mine->refresh();
    expect($mine->slug)->toBeNull()
        ->and($mine->user_id)->toBe($user->id)
        ->and($mine->name)->toBe('Mi producto casero');
});
