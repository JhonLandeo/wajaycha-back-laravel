<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Services\Shopping\NutritionCalculator;
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
        ->and(Recipe::where('slug', 'arroz-con-pollo')->exists())->toBeTrue()
        ->and(Recipe::whereIn('slug', ['quinua-con-leche', 'siete-semillas', 'soya'])->count())->toBe(3);

    $soya = Recipe::where('slug', 'soya')->sole();
    $soyaProduct = Product::where('slug', 'soya')->sole();

    expect($soya->course->value)->toBe('drink')
        ->and($soyaProduct->name)->toBe('Soya molida')
        ->and($soyaProduct->unit)->toBe('kg');

    $arroz = Recipe::where('slug', 'arroz-con-pollo')->with('ingredients.product')->sole();
    expect($arroz->ingredients)->not->toBeEmpty()
        ->and($arroz->ingredients->first()->product)->not->toBeNull()
        ->and($arroz->diet_tags)->toContain('high_protein')
        ->and($arroz->cuisine->value)->toBe('peruvian')
        ->and(Recipe::where('slug', 'aji-de-gallina')->where('cuisine', 'peruvian')->exists())->toBeTrue()
        ->and(Recipe::where('slug', 'avena-con-platano')->where('cuisine', 'international')->exists())->toBeTrue()
        ->and(Recipe::where('slug', 'pancakes')->where('cuisine', 'international')->exists())->toBeTrue()
        ->and(Recipe::where('slug', 'ensalada-de-fruta-con-yogur-griego')->exists())->toBeTrue();

    $this->seed(ProductCatalogueSeeder::class);
    $this->seed(RecipeCatalogueSeeder::class);

    expect(Recipe::whereNull('user_id')->count())->toBe($count)
        ->and(RecipeIngredient::count())->toBe($ingredients);

    $calculator = app(NutritionCalculator::class);
    $partial = Recipe::with('ingredients.product')->get()
        ->filter(fn (Recipe $recipe): bool => ! $calculator->perServing($recipe)->isComplete());

    expect($partial->pluck('name')->all())->toBe([]);
});
