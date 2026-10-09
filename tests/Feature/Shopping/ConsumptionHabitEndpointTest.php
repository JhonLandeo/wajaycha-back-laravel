<?php

declare(strict_types=1);

use App\Models\ConsumptionHabit;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects an unauthenticated store request', function () {
    $this->postJson('/api/shopping/consumption-habits', [])->assertStatus(401);
});

it('persists a weekly quantity and unit scoped to the caller', function () {
    [$user, $headers] = $this->userWithAuth();
    $product = Product::factory()->create(['unit' => 'kg']);

    $this->postJson('/api/shopping/consumption-habits', [
        'product_id' => $product->id,
        'weekly_quantity' => 2,
        'unit' => 'kg',
    ], $headers)->assertSuccessful();

    $habit = ConsumptionHabit::where('user_id', $user->id)->sole();
    expect($habit->product_id)->toBe($product->id)
        ->and((float) $habit->weekly_quantity)->toBe(2.0)
        ->and($habit->unit)->toBe('kg');
});

it('rejects a unit that does not match the product canonical unit', function () {
    [, $headers] = $this->userWithAuth();
    $product = Product::factory()->create(['unit' => 'kg']);

    $this->postJson('/api/shopping/consumption-habits', [
        'product_id' => $product->id,
        'weekly_quantity' => 1,
        'unit' => 'l',
    ], $headers)->assertStatus(422);

    expect(ConsumptionHabit::where('product_id', $product->id)->exists())->toBeFalse();
});

it('updates instead of duplicating when the same product is declared twice', function () {
    [$user, $headers] = $this->userWithAuth();
    $product = Product::factory()->create(['unit' => 'kg']);

    $this->postJson('/api/shopping/consumption-habits', [
        'product_id' => $product->id,
        'weekly_quantity' => 2,
        'unit' => 'kg',
    ], $headers)->assertSuccessful();

    $this->postJson('/api/shopping/consumption-habits', [
        'product_id' => $product->id,
        'weekly_quantity' => 5,
        'unit' => 'kg',
    ], $headers)->assertSuccessful();

    $habits = ConsumptionHabit::where('user_id', $user->id)->where('product_id', $product->id)->get();
    expect($habits)->toHaveCount(1)
        ->and((float) $habits->first()->weekly_quantity)->toBe(5.0);
});

it('lists only the caller\'s own active habits', function () {
    [$owner, $ownerHeaders] = $this->userWithAuth();
    [$stranger] = $this->userWithAuth();

    ConsumptionHabit::factory()->count(2)->create(['user_id' => $owner->id]);
    ConsumptionHabit::factory()->create(['user_id' => $stranger->id]);

    $response = $this->getJson('/api/shopping/consumption-habits', $ownerHeaders)->assertOk();

    expect($response->json('data'))->toHaveCount(2);
});

it('lets the owner update their own consumption habit', function () {
    [$owner, $headers] = $this->userWithAuth();
    $product = Product::factory()->create(['unit' => 'kg']);
    $habit = ConsumptionHabit::factory()->create([
        'user_id' => $owner->id,
        'product_id' => $product->id,
        'unit' => 'kg',
        'weekly_quantity' => 1,
    ]);

    $this->putJson("/api/shopping/consumption-habits/{$habit->id}", [
        'product_id' => $product->id,
        'weekly_quantity' => 3,
        'unit' => 'kg',
    ], $headers)->assertOk();

    expect((float) $habit->fresh()->weekly_quantity)->toBe(3.0);
});

it('lets the owner delete their own consumption habit', function () {
    [$owner, $headers] = $this->userWithAuth();
    $habit = ConsumptionHabit::factory()->create(['user_id' => $owner->id]);

    $this->deleteJson("/api/shopping/consumption-habits/{$habit->id}", [], $headers)->assertOk();

    expect(ConsumptionHabit::find($habit->id))->toBeNull();
});
