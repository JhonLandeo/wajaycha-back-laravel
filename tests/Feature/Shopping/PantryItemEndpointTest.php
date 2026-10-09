<?php

declare(strict_types=1);

use App\Models\PantryItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects an unauthenticated store request', function () {
    $this->postJson('/api/shopping/pantry-items', [])->assertStatus(401);
});

it('persists a pantry item with its acquisition source', function () {
    [$user, $headers] = $this->userWithAuth();
    $product = Product::factory()->create(['unit' => 'kg']);

    $this->postJson('/api/shopping/pantry-items', [
        'product_id' => $product->id,
        'quantity' => 2.5,
        'unit' => 'kg',
        'acquisition_source' => 'gift',
    ], $headers)->assertCreated();

    $item = PantryItem::where('user_id', $user->id)->sole();
    expect($item->product_id)->toBe($product->id)
        ->and((float) $item->quantity)->toBe(2.5)
        ->and($item->acquisition_source)->toBe('gift');
});

it('rejects a unit that does not match the product canonical unit', function () {
    [, $headers] = $this->userWithAuth();
    $product = Product::factory()->create(['unit' => 'kg']);

    $this->postJson('/api/shopping/pantry-items', [
        'product_id' => $product->id,
        'quantity' => 1,
        'unit' => 'l',
        'acquisition_source' => 'purchased',
    ], $headers)->assertStatus(422);

    expect(PantryItem::where('product_id', $product->id)->exists())->toBeFalse();
});

it('lists only the caller\'s own pantry items', function () {
    [$owner, $ownerHeaders] = $this->userWithAuth();
    [$stranger] = $this->userWithAuth();

    PantryItem::factory()->count(2)->create(['user_id' => $owner->id]);
    PantryItem::factory()->create(['user_id' => $stranger->id]);

    $response = $this->getJson('/api/shopping/pantry-items', $ownerHeaders)->assertOk();

    expect($response->json('data'))->toHaveCount(2);
});

it('lets the owner update their own pantry item', function () {
    [$owner, $headers] = $this->userWithAuth();
    $product = Product::factory()->create(['unit' => 'kg']);
    $item = PantryItem::factory()->create([
        'user_id' => $owner->id,
        'product_id' => $product->id,
        'unit' => 'kg',
        'quantity' => 1,
    ]);

    $this->putJson("/api/shopping/pantry-items/{$item->id}", [
        'product_id' => $product->id,
        'quantity' => 3,
        'unit' => 'kg',
        'acquisition_source' => 'harvested',
    ], $headers)->assertOk();

    expect((float) $item->fresh()->quantity)->toBe(3.0)
        ->and($item->fresh()->acquisition_source)->toBe('harvested');
});

it('lets the owner delete their own pantry item', function () {
    [$owner, $headers] = $this->userWithAuth();
    $item = PantryItem::factory()->create(['user_id' => $owner->id]);

    $this->deleteJson("/api/shopping/pantry-items/{$item->id}", [], $headers)->assertOk();

    expect(PantryItem::find($item->id))->toBeNull();
});
