<?php

use App\Models\ParetoClassification;
use App\Models\Category;
use App\Models\User;

it('puede crear una clasificación pareto', function () {
    $user = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($user);

    $payload = [
        'name' => 'Gastos Esenciales',
        'percentage' => 50.00
    ];

    $response = $this->postJson('/api/pareto-classification', $payload, $headers);

    $response->assertStatus(200);
    $this->assertDatabaseHas('pareto_classifications', [
        'name' => 'Gastos Esenciales',
        'percentage' => 50.00,
        'user_id' => $user->id
    ]);
});

it('puede listar clasificaciones pareto del usuario autenticado', function () {
    // 1. Al crear el usuario, tu Observer ya debería crear las 3 clasificaciones
    $user = User::factory()->create();
    $headers = $this->actingAsJwtUser($user);

    // 2. No llames a ParetoClassification::factory() aquí, porque duplicarías
    $response = $this->getJson('/api/all-pareto-classification', $headers);

    $response->assertStatus(200)
        ->assertJsonCount(3); // Esto debería pasar ahora
});

it('no puede eliminar pareto con categorías asignadas', function () {
    $user = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($user);

    $pareto = ParetoClassification::factory()->create(['user_id' => $user->id]);
    Category::factory()->create([
        'user_id' => $user->id,
        'pareto_classification_id' => $pareto->id
    ]);

    $response = $this->deleteJson('/api/pareto-classification/' . $pareto->id, [], $headers);

    $response->assertStatus(422);
});

it('puede obtener las categorías de una clasificación pareto', function () {
    $user = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($user);

    $pareto = ParetoClassification::factory()->create(['user_id' => $user->id]);
    Category::factory()->count(2)->create([
        'user_id' => $user->id,
        'pareto_classification_id' => $pareto->id
    ]);

    $response = $this->getJson('/api/pareto-classification/' . $pareto->id . '/categories', $headers);

    $response->assertStatus(200);
    $response->assertJsonCount(2);
});

it('el reporte mensual pareto devuelve la estructura correcta', function () {
    $user = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($user);

    $response = $this->getJson('/api/pareto-classification?month=4&year=2026', $headers);

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
