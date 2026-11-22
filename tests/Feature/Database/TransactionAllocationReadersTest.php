<?php

declare(strict_types=1);

use App\DTOs\Dashboard\DashboardScope;
use App\Models\Category;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\DashboardRepository;
use App\Repositories\ParetoRepository;
use App\Repositories\TransactionRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Los lectores de la Fase 1 contra una transacción repartida de verdad.
 *
 * La prueba de la Fase 0 verificó que la vista resolviera el reparto. Esta
 * verifica lo otro: que cada agregado que antes leía `transactions.category_id`
 * lea `v_transaction_allocations`, y que el monto completo del ticket no se
 * cuele en ninguna categoría. Ver ADR-0050.
 */
beforeEach(function () {
    $this->user = User::factory()->create();

    $this->food = Category::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Comida',
        'type' => 'expense',
        'monthly_budget' => 500,
    ]);

    $this->cleaning = Category::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Limpieza',
        'type' => 'expense',
        'monthly_budget' => 200,
    ]);

    $this->detail = Detail::factory()->create([
        'user_id' => $this->user->id,
        'description' => 'Supermercado Mixto',
    ]);

    $this->transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'detail_id' => $this->detail->id,
        'category_id' => null,
        'type_transaction' => 'expense',
        'amount' => 100,
        'date_operation' => '2026-08-10 10:00:00',
    ]);

    DB::table('transaction_splits')->insert([
        [
            'transaction_id' => $this->transaction->id,
            'category_id' => $this->food->id,
            'user_id' => $this->user->id,
            'amount' => '60.00',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'transaction_id' => $this->transaction->id,
            'category_id' => $this->cleaning->id,
            'user_id' => $this->user->id,
            'amount' => '40.00',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
});

it('el reporte mensual de presupuesto suma la parte asignada a cada categoría', function () {
    $rows = DB::select(
        'SELECT id, spent FROM get_monthly_category_budget_report(?, ?, ?, ?, ?, ?)',
        [1, 50, $this->user->id, 8, 2026, null]
    );

    $byId = collect($rows)->keyBy('id');

    expect((float) $byId[$this->food->id]->spent)->toBe(60.0)
        ->and((float) $byId[$this->cleaning->id]->spent)->toBe(40.0);
});

it('Pareto atribuye a cada categoría solo su parte del movimiento', function () {
    $repository = new ParetoRepository;

    $spend = $repository->netSpendByCategory($this->user->id, 8, 2026);
    $totals = $repository->windowTotals($this->user->id, 8, 2026);

    expect($spend[$this->food->id])->toBe(60.0)
        ->and($spend[$this->cleaning->id])->toBe(40.0)
        ->and($totals->expense)->toBe(100.0);
});

it('el resumen por categoría cuenta la transacción en cada categoría, no en la suma total', function () {
    $paginator = (new TransactionRepository)->summaryByCategory(
        $this->user->id, 2026, 8, null, 50, 1
    );

    $byName = collect($paginator->items())->keyBy('name');

    expect((float) $byName['Comida']->total)->toBe(60.0)
        ->and((int) $byName['Comida']->quantity)->toBe(1)
        ->and((float) $byName['Limpieza']->total)->toBe(40.0)
        ->and((int) $byName['Limpieza']->quantity)->toBe(1)
        ->and($byName->has('Sin categorizar'))->toBeFalse();
});

it('el gasto por categoría del coach reparte el ticket y conserva lo no categorizado', function () {
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => null,
        'type_transaction' => 'expense',
        'amount' => 10,
        'date_operation' => '2026-08-11 10:00:00',
    ]);

    $rows = (new TransactionRepository)->expenseByCategoryBetween(
        $this->user->id,
        CarbonImmutable::parse('2026-08-01', 'America/Lima'),
        CarbonImmutable::parse('2026-09-01', 'America/Lima'),
    );

    $byCategory = collect($rows)->keyBy(fn (object $row): string => $row->category_id === null ? 'null' : (string) $row->category_id);

    expect((float) $byCategory[(string) $this->food->id]->total)->toBe(60.0)
        ->and((float) $byCategory[(string) $this->cleaning->id]->total)->toBe(40.0)
        ->and((float) $byCategory['null']->total)->toBe(10.0);
});

it('el top de comercios y el gasto más grande usan la parte asignada, no el ticket completo', function () {
    $repository = new TransactionRepository;
    $startsAt = CarbonImmutable::parse('2026-08-01', 'America/Lima');
    $endsAt = CarbonImmutable::parse('2026-09-01', 'America/Lima');

    $merchants = $repository->topMerchantsForCategoryMonth(
        $this->user->id, $this->food->id, $startsAt, $endsAt, 5
    );
    $largest = $repository->largestExpenseForCategoryMonth(
        $this->user->id, $this->food->id, $startsAt, $endsAt
    );

    expect($merchants)->toHaveCount(1)
        ->and($merchants[0]->detail_name)->toBe('Supermercado Mixto')
        ->and((float) $merchants[0]->total)->toBe(60.0)
        ->and((int) $merchants[0]->frequency)->toBe(1)
        ->and((float) $largest->amount)->toBe(60.0);
});

it('el dashboard agrega por categoría con el monto asignado', function () {
    $repository = new DashboardRepository;
    $scope = new DashboardScope($this->user->id, 2026, 8);

    $byName = collect($repository->spendByCategory($scope))->keyBy('name');

    expect((float) $byName['Comida']->total)->toBe(60.0)
        ->and((float) $byName['Limpieza']->total)->toBe(40.0);

    $daily = $repository->dailyExpenseForCategories(
        $this->user->id, 2026, 8, [$this->food->id, $this->cleaning->id]
    );

    expect($daily[10])->toBe(100.0);
});

it('el listado filtrado por categoría devuelve la parte asignada en cada fila', function () {
    $rows = DB::select(
        'SELECT * FROM get_transactions(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [10, 1, null, null, null, null, null, (string) $this->food->id, $this->user->id, false, false, false]
    );

    expect($rows)->toHaveCount(1)
        ->and((float) $rows[0]->amount)->toBe(100.0)
        ->and((float) $rows[0]->allocated_amount)->toBe(60.0)
        ->and((int) $rows[0]->total_count)->toBe(1);
});

it('el listado sin filtro mantiene el ticket completo y no inventa una asignación', function () {
    $rows = DB::select(
        'SELECT * FROM get_transactions(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [10, 1, null, null, null, null, null, null, $this->user->id, false, false, false]
    );

    expect($rows)->toHaveCount(1)
        ->and((float) $rows[0]->amount)->toBe(100.0)
        ->and($rows[0]->allocated_amount)->toBeNull();
});

it('sin categoría excluye el movimiento repartido aunque su category_id sea nulo', function () {
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => null,
        'type_transaction' => 'expense',
        'amount' => 10,
        'date_operation' => '2026-08-11 10:00:00',
    ]);

    $rows = DB::select(
        'SELECT id, amount FROM get_transactions(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [10, 1, null, null, null, null, null, 'without_category', $this->user->id, false, false, false]
    );

    expect($rows)->toHaveCount(1)
        ->and((float) $rows[0]->amount)->toBe(10.0);
});
