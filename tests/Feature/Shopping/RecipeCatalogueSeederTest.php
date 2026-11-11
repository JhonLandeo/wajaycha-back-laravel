<?php

declare(strict_types=1);

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Database\Seeders\ProductCatalogueSeeder;
use Database\Seeders\RecipeCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the shared recipe catalogue idempotently and maps ingredients to products', function () {
    $this->seed(ProductCatalogueSeeder::class);
    $this->seed(RecipeCatalogueSeeder::class);

    $count = Recipe::whereNull('user_id')->count();
    $ingredients = RecipeIngredient::count();

    expect($count)->toBeGreaterThan(0)
        ->and(Recipe::where('slug', 'arroz-con-pollo')->exists())->toBeTrue();

    $arroz = Recipe::where('slug', 'arroz-con-pollo')->with('ingredients.product')->sole();
    expect($arroz->ingredients)->not->toBeEmpty()
        ->and($arroz->ingredients->first()->product)->not->toBeNull();

    $this->seed(ProductCatalogueSeeder::class);
    $this->seed(RecipeCatalogueSeeder::class);

    expect(Recipe::whereNull('user_id')->count())->toBe($count)
        ->and(RecipeIngredient::count())->toBe($ingredients);
});
