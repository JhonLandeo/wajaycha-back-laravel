<?php

declare(strict_types=1);

/**
 * Characterisation tests for the dashboard read model.
 *
 * Written before the refactor that moves these queries out of the controller, and
 * deliberately asserting only what a caller can observe: status, payload shape,
 * arithmetic, and — the invariant that matters — that one user's figures never
 * include another user's rows.
 *
 * They assert nothing about DB::select, repositories or contracts. A test that
 * knows how the data was fetched would have to change when the fetching changes,
 * which is exactly the regression proof a refactor cannot afford to lose.
 */

use App\Models\Category;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\User;

/**
 * @return array{0: User, 1: array<string, string>}
 */
function dashboardOwner(): array
{
    /** @var \Tests\TestCase $t */
    $t = test();

    return $t->userWithAuth();
}

function expenseFor(User $user, string $date, float $amount, ?int $categoryId = null): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $user->id,
        'detail_id' => Detail::factory()->create(['user_id' => $user->id])->id,
        'category_id' => $categoryId,
        'amount' => $amount,
        'date_operation' => $date,
        'type_transaction' => 'expense',
    ]);
}

function incomeFor(User $user, string $date, float $amount): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $user->id,
        'detail_id' => Detail::factory()->create(['user_id' => $user->id])->id,
        'category_id' => null,
        'amount' => $amount,
        'date_operation' => $date,
        'type_transaction' => 'income',
    ]);
}

// ------------------------------------------------------------------- kpi-data

it('devuelve los KPI del mes con la forma que el cliente espera', function () {
    [$user, $headers] = dashboardOwner();
    expenseFor($user, '2026-03-10 12:00:00', 100.00);
    incomeFor($user, '2026-03-11 12:00:00', 250.00);

    $response = $this->postJson('/api/kpi-data', ['year' => 2026, 'month' => 3], $headers);

    $response->assertOk()->assertJsonStructure([
        'avg_daily_income',
        'avg_daily_expense',
        'total_income' => ['amount'],
        'total_expense' => ['amount'],
        'balance' => ['amount'],
    ]);

    expect((float) $response->json('total_income.amount'))->toBe(250.00);
    expect((float) $response->json('total_expense.amount'))->toBe(100.00);
    expect((float) $response->json('balance.amount'))->toBe(150.00);
});

it('no mezcla en los KPI las transacciones de otro usuario', function () {
    [$user, $headers] = dashboardOwner();
    $stranger = User::factory()->create();

    expenseFor($user, '2026-03-10 12:00:00', 100.00);
    expenseFor($stranger, '2026-03-10 12:00:00', 999.00);

    $response = $this->postJson('/api/kpi-data', ['year' => 2026, 'month' => 3], $headers);

    expect((float) $response->json('total_expense.amount'))->toBe(100.00);
});

it('excluye del mes consultado las transacciones de otro mes', function () {
    [$user, $headers] = dashboardOwner();
    expenseFor($user, '2026-03-10 12:00:00', 100.00);
    expenseFor($user, '2026-04-10 12:00:00', 500.00);

    $response = $this->postJson('/api/kpi-data', ['year' => 2026, 'month' => 3], $headers);

    expect((float) $response->json('total_expense.amount'))->toBe(100.00);
});

// ------------------------------------------------------------------- top-data

it('responde top-data para el periodo pedido', function () {
    [$user, $headers] = dashboardOwner();
    expenseFor($user, '2026-03-10 12:00:00', 100.00);

    $this->postJson('/api/top-data', ['year' => 2026, 'month' => 3], $headers)->assertOk();
});

// ---------------------------------------------------------------- weekly-data

it('responde weekly-data para el periodo pedido', function () {
    [$user, $headers] = dashboardOwner();
    expenseFor($user, '2026-03-10 12:00:00', 100.00);

    $this->postJson(
        '/api/weekly-data',
        ['year' => 2026, 'month' => 3, 'isChecked' => false],
        $headers
    )->assertOk();
});

// --------------------------------------------------------------- monthly-data

it('responde monthly-data para el año pedido', function () {
    [$user, $headers] = dashboardOwner();
    expenseFor($user, '2026-03-10 12:00:00', 100.00);

    $this->postJson(
        '/api/monthly-data',
        ['year' => 2026, 'isChecked' => false],
        $headers
    )->assertOk();
});

// ------------------------------------------------- transaction-by-category

it('agrupa el gasto por categoría y ordena de mayor a menor', function () {
    [$user, $headers] = dashboardOwner();
    $food = Category::factory()->create(['user_id' => $user->id, 'name' => 'Comida']);
    $transport = Category::factory()->create(['user_id' => $user->id, 'name' => 'Transporte']);

    expenseFor($user, '2026-03-10 12:00:00', 50.00, $food->id);
    expenseFor($user, '2026-03-11 12:00:00', 30.00, $food->id);
    expenseFor($user, '2026-03-12 12:00:00', 20.00, $transport->id);

    $response = $this->postJson(
        '/api/transaction-by-category',
        ['year' => 2026, 'month' => 3],
        $headers
    );

    $response->assertOk();
    $rows = collect($response->json());

    expect($rows->pluck('name')->first())->toBe('Comida');
    expect((float) $rows->firstWhere('name', 'Comida')['total'])->toBe(80.00);
    expect((int) $rows->firstWhere('name', 'Comida')['quantity'])->toBe(2);
    expect((float) $rows->firstWhere('name', 'Transporte')['total'])->toBe(20.00);
});

it('nombra "Sin categorizar" el gasto sin categoría', function () {
    [$user, $headers] = dashboardOwner();
    expenseFor($user, '2026-03-10 12:00:00', 40.00, null);

    $response = $this->postJson(
        '/api/transaction-by-category',
        ['year' => 2026, 'month' => 3],
        $headers
    );

    expect(collect($response->json())->pluck('name'))->toContain('Sin categorizar');
});

it('no incluye en el agrupado por categoría el gasto de otro usuario', function () {
    [$user, $headers] = dashboardOwner();
    $stranger = User::factory()->create();
    $strangerCategory = Category::factory()->create([
        'user_id' => $stranger->id,
        'name' => 'Ajeno',
    ]);

    expenseFor($user, '2026-03-10 12:00:00', 40.00);
    expenseFor($stranger, '2026-03-10 12:00:00', 999.00, $strangerCategory->id);

    $response = $this->postJson(
        '/api/transaction-by-category',
        ['year' => 2026, 'month' => 3],
        $headers
    );

    expect(collect($response->json())->pluck('name'))->not->toContain('Ajeno');
});

it('filtra el agrupado por categoría con el término de búsqueda', function () {
    [$user, $headers] = dashboardOwner();
    $food = Category::factory()->create(['user_id' => $user->id, 'name' => 'Comida']);
    $transport = Category::factory()->create(['user_id' => $user->id, 'name' => 'Transporte']);

    expenseFor($user, '2026-03-10 12:00:00', 50.00, $food->id);
    expenseFor($user, '2026-03-11 12:00:00', 20.00, $transport->id);

    $response = $this->postJson(
        '/api/transaction-by-category',
        ['year' => 2026, 'month' => 3, 'search' => 'comi'],
        $headers
    );

    $names = collect($response->json())->pluck('name');
    expect($names)->toContain('Comida');
    expect($names)->not->toContain('Transporte');
});

// ------------------------------------------------------------------- guarding

it('rechaza cada endpoint del dashboard sin autenticación', function (string $route) {
    $this->postJson($route, ['year' => 2026, 'month' => 3])->assertStatus(401);
})->with([
    '/api/kpi-data',
    '/api/top-data',
    '/api/weekly-data',
    '/api/monthly-data',
    '/api/transaction-by-category',
]);
