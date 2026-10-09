<?php

declare(strict_types=1);

use App\Models\Product;
use Database\Seeders\ProductCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects an unauthenticated request', function () {
    $this->getJson('/api/shopping/products')->assertStatus(401);
});

it('shows the same seeded catalogue to every user', function () {
    (new ProductCatalogueSeeder)->run();
    [, $headersA] = $this->userWithAuth();
    [, $headersB] = $this->userWithAuth();

    $responseA = $this->getJson('/api/shopping/products', $headersA)->assertOk();
    $responseB = $this->getJson('/api/shopping/products', $headersB)->assertOk();

    $namesA = collect($responseA->json('data'))->pluck('name')->sort()->values()->all();
    $namesB = collect($responseB->json('data'))->pluck('name')->sort()->values()->all();

    expect(count($namesA))->toBe(count(config('shopping.catalogue')))
        ->and($namesA)->toBe($namesB);
});

it('keeps a user\'s own addition invisible to another user', function () {
    [$owner, $ownerHeaders] = $this->userWithAuth();
    [, $strangerHeaders] = $this->userWithAuth();
    Product::factory()->ownedBy($owner->id)->create(['name' => 'Choclo serrano especial']);

    $ownerResponse = $this->getJson('/api/shopping/products', $ownerHeaders)->assertOk();
    $strangerResponse = $this->getJson('/api/shopping/products', $strangerHeaders)->assertOk();

    $ownerNames = collect($ownerResponse->json('data'))->pluck('name');
    $strangerNames = collect($strangerResponse->json('data'))->pluck('name');

    expect($ownerNames)->toContain('Choclo serrano especial')
        ->and($strangerNames)->not->toContain('Choclo serrano especial');
});

it('creates a private addition invisible to another user', function () {
    [$owner, $ownerHeaders] = $this->userWithAuth();
    [, $strangerHeaders] = $this->userWithAuth();

    $this->postJson('/api/shopping/products', [
        'name' => 'Tarwi',
        'unit' => 'kg',
    ], $ownerHeaders)->assertCreated();

    $product = Product::where('name', 'Tarwi')->sole();
    expect($product->user_id)->toBe($owner->id)
        ->and($product->slug)->toBeNull();

    $strangerResponse = $this->getJson('/api/shopping/products', $strangerHeaders)->assertOk();
    expect(collect($strangerResponse->json('data'))->pluck('name'))->not->toContain('Tarwi');
});

it('rejects a name colliding case-insensitively with a visible global product', function () {
    Product::factory()->create(['user_id' => null, 'slug' => 'palta', 'name' => 'Palta', 'unit' => 'unidad']);
    [, $headers] = $this->userWithAuth();

    $this->postJson('/api/shopping/products', [
        'name' => 'palta',
        'unit' => 'unidad',
    ], $headers)->assertStatus(422);
});

it('rejects an unsupported unit', function () {
    [, $headers] = $this->userWithAuth();

    $this->postJson('/api/shopping/products', [
        'name' => 'Producto con unidad rara',
        'unit' => 'toneladas',
    ], $headers)->assertStatus(422);
});
