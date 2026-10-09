<?php

declare(strict_types=1);

use App\Enums\AcquisitionSource;
use App\Enums\Unit;
use App\Models\Category;
use App\Models\ConsumptionHabit;
use App\Models\GroceryBudgetLink;
use App\Models\PantryItem;
use App\Models\Product;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects an unauthenticated request', function () {
    $this->getJson('/api/shopping/weekly-plan')->assertStatus(401);
});

it('returns only the difference between the declared habit and the available pantry', function () {
    [$user, $headers] = $this->userWithAuth();
    $product = Product::factory()->create(['name' => 'Arroz', 'unit' => Unit::Kg->value]);
    ConsumptionHabit::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'weekly_quantity' => 2.0,
        'unit' => Unit::Kg->value,
    ]);
    PantryItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 0.5,
        'unit' => Unit::Kg->value,
        'acquisition_source' => AcquisitionSource::Purchased->value,
    ]);

    $response = $this->getJson('/api/shopping/weekly-plan', $headers)->assertOk();

    $lines = $response->json('data.lines');
    expect($lines)->toHaveCount(1)
        ->and($lines[0]['product_id'])->toBe($product->id)
        ->and((float) $lines[0]['needed_quantity'])->toBe(2.0)
        ->and($lines[0]['available_quantity'])->toBe(0.5)
        ->and($lines[0]['to_buy_quantity'])->toBe(1.5);
});

it('excludes a product a gift fully covers and touches no Transaction', function () {
    [$user, $headers] = $this->userWithAuth();
    $product = Product::factory()->create(['name' => 'Palta', 'unit' => Unit::Unidad->value]);
    ConsumptionHabit::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'weekly_quantity' => 1.0,
        'unit' => Unit::Unidad->value,
    ]);
    PantryItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 2.0,
        'unit' => Unit::Unidad->value,
        'acquisition_source' => AcquisitionSource::Gift->value,
    ]);

    $response = $this->getJson('/api/shopping/weekly-plan', $headers)->assertOk();

    expect($response->json('data.lines'))->toBe([])
        ->and($response->json('data.covered'))->toHaveCount(1)
        ->and($response->json('data.covered.0.product_id'))->toBe($product->id)
        ->and(Transaction::query()->count())->toBe(0);
});

it('relists a product whose stock expired and keeps the expired pantry row', function () {
    [$user, $headers] = $this->userWithAuth();
    $product = Product::factory()->create(['name' => 'Leche', 'unit' => Unit::L->value]);
    ConsumptionHabit::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'weekly_quantity' => 1.0,
        'unit' => Unit::L->value,
    ]);
    $expired = PantryItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 2.0,
        'unit' => Unit::L->value,
        'acquisition_source' => AcquisitionSource::Purchased->value,
        'expires_on' => CarbonImmutable::now('America/Lima')->subDays(3)->toDateString(),
    ]);

    $response = $this->getJson('/api/shopping/weekly-plan', $headers)->assertOk();

    expect($response->json('data.lines'))->toHaveCount(1)
        ->and($response->json('data.lines.0.product_id'))->toBe($product->id)
        ->and((float) $response->json('data.lines.0.expired_quantity'))->toBe(2.0)
        ->and(PantryItem::query()->whereKey($expired->id)->exists())->toBeTrue();
});

it('states the overspend and still lists every needed product when the ceiling is exceeded', function () {
    [$user, $headers] = $this->userWithAuth();
    $product = Product::factory()->create(['name' => 'Arroz', 'unit' => Unit::Kg->value]);
    ConsumptionHabit::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'weekly_quantity' => 2.0,
        'unit' => Unit::Kg->value,
    ]);

    $category = Category::factory()->create(['user_id' => $user->id, 'monthly_budget' => 100]);
    GroceryBudgetLink::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'resolved_by' => 'manual',
    ]);
    $asOf = CarbonImmutable::now();
    Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'type_transaction' => 'expense',
        'amount' => 250.00,
        'date_operation' => $asOf->startOfMonth()->addDays(2),
    ]);

    $response = $this->getJson('/api/shopping/weekly-plan', $headers)->assertOk();

    expect($response->json('data.ceiling_state'))->toBe('set')
        ->and($response->json('data.ceiling.is_exceeded'))->toBeTrue()
        ->and((float) $response->json('data.ceiling.overspend'))->toBe(150.0)
        // The overspend never hides or reorders the needed product.
        ->and($response->json('data.lines'))->toHaveCount(1)
        ->and($response->json('data.lines.0.product_id'))->toBe($product->id);
});
