<?php

use App\Models\Transaction;
use App\Models\Detail;
use App\Models\Category;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;

it('puede crear una transacción manual con detail_description nuevo', function () {
    Queue::fake();
    $user = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($user);
    $category = Category::where('user_id', $user->id)->first();

    $payload = [
        'amount' => 100.50,
        'date_operation' => now()->toIso8601String(),
        'detail_description' => 'Compra en supermercado', // <--- Aquí mandas description
        'category_id' => $category->id,
        'is_recurrent' => false,
        'type_transaction' => 'expense'
    ];

    $response = $this->postJson('/api/transactions', $payload, $headers);

    $response->assertStatus(201);

    Queue::assertPushed(\App\Jobs\GenerateEmbeddingForDetail::class);

    // CORRECCIÓN AQUÍ: Cambia 'name' por 'description'
    $this->assertDatabaseHas('details', [
        'description' => 'Compra en supermercado',
        'user_id' => $user->id
    ]);
});

it('puede crear una transacción manual con detail_id existente', function () {
    $user = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($user);
    $category = Category::where('user_id', $user->id)->first();
    $detail = Detail::factory()->create(['user_id' => $user->id]);

    $payload = [
        'amount' => 50.00,
        'date_operation' => now()->toIso8601String(),
        'detail_id' => $detail->id,
        'category_id' => $category->id,
        'type_transaction' => 'expense'
    ];

    $response = $this->postJson('/api/transactions', $payload, $headers);

    $response->assertStatus(201);
});

it('rechaza crear transacción sin autenticación', function () {
    $response = $this->postJson('/api/transactions', [
        'amount' => 100
    ]);

    $response->assertStatus(401);
});

it('puede actualizar una transacción manual propia', function () {
    $user = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($user);

    $detail = Detail::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'is_manual' => true
    ]);

    $response = $this->putJson("/api/transactions/{$transaction->id}", [
        'amount' => 200.00,
        'date_operation' => now()->toIso8601String(),
        'type_transaction' => 'expense'
    ], $headers);

    $response->assertStatus(200);
    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'amount' => 200.00
    ]);
});

it('no puede actualizar una transacción no-manual', function () {
    $user = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($user);

    $detail = Detail::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'is_manual' => false
    ]);

    $response = $this->putJson("/api/transactions/{$transaction->id}", [
        'amount' => 200.00,
        'date_operation' => now()->toIso8601String(),
        'type_transaction' => 'expense'
    ], $headers);

    $response->assertStatus(403);
});

it('no puede actualizar una transacción de otro usuario', function () {
    $owner = $this->createUserWithCategories();
    $intruder = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($intruder);

    $detail = Detail::factory()->create(['user_id' => $owner->id]);
    $transaction = Transaction::factory()->create([
        'user_id' => $owner->id,
        'detail_id' => $detail->id,
        'is_manual' => true
    ]);

    $response = $this->putJson("/api/transactions/{$transaction->id}", [
        'amount' => 200.00,
        'date_operation' => now()->toIso8601String(),
        'type_transaction' => 'expense'
    ], $headers);

    // Some apps return 404 for missing authorization on find, or 403 on update
    // We assert it's a client error (403 or 404)
    $this->assertTrue(in_array($response->status(), [403, 404]));
});

it('puede eliminar una transacción manual propia', function () {
    $user = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($user);

    $detail = Detail::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'is_manual' => true
    ]);

    $response = $this->deleteJson("/api/transactions/{$transaction->id}", [], $headers);

    $response->assertStatus(200);
});

it('no puede eliminar una transacción no-manual', function () {
    $user = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($user);

    $detail = Detail::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'is_manual' => false
    ]);

    $response = $this->deleteJson("/api/transactions/{$transaction->id}", [], $headers);

    $response->assertStatus(403);
});

it('puede listar transacciones paginadas con filtros de mes y año', function () {
    $user = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($user);

    $response = $this->getJson('/api/transactions?month=4&year=2026', $headers);

    // En el test
    $response->assertStatus(200)
        ->assertJsonStructure([
            'data',
            'current_page',
            'first_page_url',
            'last_page',
            'per_page',
            'total'
        ]);
});

it('el endpoint get-summary-by-category retorna datos agrupados', function () {
    $user = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($user);

    $response = $this->getJson('/api/get-summary-by-category?month=4&year=2026', $headers);

    $response->assertStatus(200);
});
