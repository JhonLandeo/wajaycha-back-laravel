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

it('puede categorizar una transacción no-manual', function () {
    // Bloquear la edición entera dejaba cada importación permanentemente sin
    // categorizar: este es el único camino para asignarle una categoría a una
    // fila que trajo el banco.
    $user = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($user);

    $detail = Detail::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'category_id' => null,
        'is_manual' => false,
    ]);

    $this->putJson("/api/transactions/{$transaction->id}", [
        'amount' => $transaction->amount,
        'date_operation' => (string) $transaction->date_operation,
        'type_transaction' => $transaction->type_transaction,
        'category_id' => $category->id,
    ], $headers)->assertOk();

    expect($transaction->fresh()->category_id)->toBe($category->id);
});

it('ignora el dato del banco al categorizar una transacción no-manual', function () {
    // El monto, la fecha y el tipo son el asiento del banco. Un cliente que los
    // mande distintos no los escribe: la regla se decide sobre la fila cargada,
    // no confiando en el payload.
    $user = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($user);

    $detail = Detail::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'amount' => 50.00,
        'type_transaction' => 'expense',
        'is_manual' => false,
    ]);
    $originalDate = $transaction->date_operation;

    $this->putJson("/api/transactions/{$transaction->id}", [
        'amount' => 9999.99,
        'date_operation' => now()->addYear()->toIso8601String(),
        'type_transaction' => 'income',
        'category_id' => $category->id,
    ], $headers)->assertOk();

    $fresh = $transaction->fresh();
    expect((float) $fresh->amount)->toBe(50.00)
        ->and($fresh->type_transaction)->toBe('expense')
        // Se comparan como instantes: la ida y vuelta por la base devuelve el
        // mismo momento con el offset explicito ('-05'), y comparar los strings
        // crudos fallaria por el formato aunque la fecha no se haya movido.
        ->and(Carbon::parse((string) $fresh->date_operation)->toDateTimeString())
        ->toBe(Carbon::parse((string) $originalDate)->toDateTimeString())
        ->and($fresh->category_id)->toBe($category->id);
});

it('sigue permitiendo editar el monto de una transacción manual', function () {
    $user = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($user);

    $detail = Detail::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'amount' => 50.00,
        'is_manual' => true,
    ]);

    $this->putJson("/api/transactions/{$transaction->id}", [
        'amount' => 200.00,
        'date_operation' => now()->toIso8601String(),
        'type_transaction' => 'expense',
    ], $headers)->assertOk();

    expect((float) $transaction->fresh()->amount)->toBe(200.00);
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

it('no categoriza los movimientos de otro usuario al marcar uno como frecuente', function () {
    // El bulk update de `is_frequent` une por `d.description`, no por `d.id`, así
    // que sin filtro de usuario alcanza a cualquiera que le haya puesto el mismo
    // nombre al comercio. Estuvo latente mientras editar un movimiento importado
    // era imposible; permitir categorizarlos lo vuelve el camino normal.
    $owner = $this->createUserWithCategories();
    $other = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($owner);

    $ownerDetail = Detail::factory()->create(['user_id' => $owner->id, 'description' => 'RAPPI']);
    $otherDetail = Detail::factory()->create(['user_id' => $other->id, 'description' => 'RAPPI']);

    $category = Category::factory()->create(['user_id' => $owner->id, 'type' => 'expense']);

    $ownerTransaction = Transaction::factory()->create([
        'user_id' => $owner->id,
        'detail_id' => $ownerDetail->id,
        'category_id' => null,
        'is_manual' => false,
    ]);
    $otherTransaction = Transaction::factory()->create([
        'user_id' => $other->id,
        'detail_id' => $otherDetail->id,
        'category_id' => null,
        'is_manual' => false,
    ]);

    $this->putJson("/api/transactions/{$ownerTransaction->id}", [
        'amount' => $ownerTransaction->amount,
        'date_operation' => (string) $ownerTransaction->date_operation,
        'type_transaction' => $ownerTransaction->type_transaction,
        'category_id' => $category->id,
        'is_frequent' => true,
    ], $headers)->assertOk();

    expect($ownerTransaction->fresh()->category_id)->toBe($category->id)
        ->and($otherTransaction->fresh()->category_id)->toBeNull();
});

it('lista transacciones recurrentes sin romperse', function () {
    // `recurring=true` es la única rama que llama a `get_transactions_by_detail`
    // (TransactionRepository.php:22). Esa función quedó meses apuntando a una
    // tabla borrada en producción sin que nada la ejercitara, porque el resto de
    // la pantalla pasa por `get_transactions`.
    //
    // Este test no habría detectado aquella deriva —las migraciones crean la
    // función correcta en cada corrida— pero deja la rama cubierta, que es lo
    // que faltaba para que un error de esta forma aparezca antes que un usuario.
    $user = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($user);

    $detail = Detail::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->count(2)->create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'type_transaction' => 'expense',
        'category_id' => null,
    ]);

    $this->getJson(
        '/api/transactions?per_page=10&page=1&amount=0.00&category=without_category'
        .'&recurring=true&weekend=true&workday=false',
        $headers
    )->assertOk();
});
