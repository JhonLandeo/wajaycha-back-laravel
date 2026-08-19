<?php

declare(strict_types=1);

use App\Actions\Pareto\BuildParetoReportAction;
use App\Models\Category;
use App\Models\Detail;
use App\Models\ParetoClassification;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Pareto report end to end, from real rows.
 *
 * The RULES are asserted without a database in `tests/Unit/Pareto`. What is left here
 * is everything only a real schema can answer: which rows the repository picks up,
 * which window each query actually covers, and that one user never sees another's
 * budget. Duplicating the arithmetic here would only make it slower to run and
 * slower to change.
 *
 * A seeded workspace arrives with three bands and a tree of categories, all on
 * `monthly_budget = 0`, so they weigh nothing and the numbers below stay exact.
 */

/** A past year, so a whole-year window is deterministically twelve months. */
const REPORT_YEAR = 2025;

function paretoReport(User $user, ?int $month, ?int $year = REPORT_YEAR): array
{
    $items = app(BuildParetoReportAction::class)
        ->execute($user->id, $month, $year, 1, 50)
        ->items();

    return collect($items)->keyBy('id')->all();
}

function bandWith(User $user, string $name, float $percentage): ParetoClassification
{
    return ParetoClassification::factory()->create([
        'user_id' => $user->id,
        'name' => $name,
        'percentage' => $percentage,
    ]);
}

function categoryInBand(
    User $user,
    ?ParetoClassification $band,
    string $name,
    float $budget,
    string $period = 'monthly',
    string $type = 'expense',
    ?int $parentId = null
): Category {
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'name' => $name,
        'type' => $type,
        'monthly_budget' => $budget,
        'budget_period' => $period,
        'parent_id' => $parentId,
    ]);

    if ($band !== null) {
        DB::table('category_pareto_assignments')->insert([
            'category_id' => $category->id,
            'pareto_classification_id' => $band->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $category;
}

function paretoSpendOn(User $user, Category $category, float $amount, string $date, string $type = 'expense'): void
{
    $detail = Detail::factory()->create(['user_id' => $user->id]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'category_id' => $category->id,
        'type_transaction' => $type,
        'amount' => $amount,
        'date_operation' => Carbon::parse($date)->toDateTimeString(),
    ]);
}

function linesOf(array $report, int $bandId): Illuminate\Support\Collection
{
    return collect($report[$bandId]['categories'])->keyBy('id');
}

// ------------------------------------------------------- que filas se levantan

it('ignora la categoria padre, que no lleva presupuesto propio', function () {
    $user = User::factory()->create();
    $band = bandWith($user, 'Necesidades', 50);
    $parent = categoryInBand($user, $band, 'Hogar', 9999);
    categoryInBand($user, $band, 'Luz', 400, parentId: $parent->id);

    // Contar padre e hija sumaria la misma plata dos veces.
    $report = paretoReport($user, 3);

    expect((float) $report[$band->id]['monthly_budget'])->toBe(400.0);
});

it('ignora las categorias de ingreso', function () {
    $user = User::factory()->create();
    $band = bandWith($user, 'Necesidades', 50);
    categoryInBand($user, $band, 'Comida', 400);
    categoryInBand($user, $band, 'Sueldo', 5000, type: 'income');

    expect((float) paretoReport($user, 3)[$band->id]['monthly_budget'])->toBe(400.0);
});

it('no deja que el presupuesto de otro usuario entre en el reporte', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $band = bandWith($user, 'Necesidades', 50);
    categoryInBand($user, $band, 'Comida', 400);

    $strangerBand = bandWith($stranger, 'Ajena', 50);
    $strangerCategory = categoryInBand($stranger, $strangerBand, 'Ajena', 9999);
    paretoSpendOn($stranger, $strangerCategory, 5000, '2025-03-10');

    $report = paretoReport($user, 3);

    expect($report)->toHaveCount(4); // tres sembradas + la propia
    expect(array_keys($report))->not->toContain($strangerBand->id);
    expect((float) $report[$band->id]['monthly_budget'])->toBe(400.0);
});

// ---------------------------------------------------- que ventana mide cada uno

it('mide la categoria mensual sobre el mes filtrado y el sobre sobre el año', function () {
    $user = User::factory()->create();
    $band = bandWith($user, 'Necesidades', 50);
    $comida = categoryInBand($user, $band, 'Comida', 400);
    $salud = categoryInBand($user, $band, 'Salud', 1200, 'yearly');

    paretoSpendOn($user, $comida, 90, '2025-01-15');
    paretoSpendOn($user, $comida, 150, '2025-03-10');
    paretoSpendOn($user, $salud, 340, '2025-01-15');

    $report = paretoReport($user, 3);
    $lines = linesOf($report, $band->id);

    expect((float) $lines[$comida->id]['spent'])->toBe(150.0);
    // El sobre reporta enero aunque se haya pedido marzo.
    expect((float) $lines[$salud->id]['spent'])->toBe(340.0);
    // Y ese consumo del sobre no toca la barra de la banda.
    expect((float) $report[$band->id]['spent'])->toBe(150.0);
});

it('no deja que el gasto de diciembre anterior se cuele en enero', function () {
    $user = User::factory()->create();
    $band = bandWith($user, 'Necesidades', 50);
    $comida = categoryInBand($user, $band, 'Comida', 400);

    paretoSpendOn($user, $comida, 500, '2024-12-31');
    paretoSpendOn($user, $comida, 120, '2025-01-01');

    expect((float) paretoReport($user, 1)[$band->id]['spent'])->toBe(120.0);
});

it('resta el ingreso categorizado del gasto neto, como hacia la funcion', function () {
    $user = User::factory()->create();
    $band = bandWith($user, 'Necesidades', 50);
    $comida = categoryInBand($user, $band, 'Comida', 400);

    paretoSpendOn($user, $comida, 200, '2025-03-10');
    paretoSpendOn($user, $comida, 50, '2025-03-12', type: 'income');

    expect((float) paretoReport($user, 3)[$band->id]['spent'])->toBe(150.0);
});

it('escala el presupuesto a los doce meses cuando no se filtra un mes', function () {
    $user = User::factory()->create();
    $band = bandWith($user, 'Necesidades', 50);
    $comida = categoryInBand($user, $band, 'Comida', 400);

    paretoSpendOn($user, $comida, 200, '2025-01-10');
    paretoSpendOn($user, $comida, 200, '2025-02-10');

    $report = paretoReport($user, null);

    expect((float) $report[$band->id]['monthly_budget'])->toBe(4800.0);
    expect((float) $report[$band->id]['spent'])->toBe(400.0);
    expect((float) $report[$band->id]['percentage_spent'])->toBe(8.33);
});

// -------------------------------------------------------- contrato de la salida

it('devuelve las mismas claves que consume la SPA', function () {
    $user = User::factory()->create();
    $headers = $this->actingAsJwtUser($user);
    $band = bandWith($user, 'Necesidades', 50);
    categoryInBand($user, $band, 'Salud', 1200, 'yearly');

    $this->getJson('/api/pareto-classification?month=3&year=2025', $headers)
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id', 'name', 'percentage', 'actual_percentage', 'monthly_budget',
                    'spent', 'available_budget', 'percentage_spent', 'total_income',
                    'total_expense',
                    'categories' => [
                        '*' => ['id', 'name', 'monthly_budget', 'budget_period', 'budget_in_window', 'spent', 'type'],
                    ],
                ],
            ],
            'current_page', 'last_page', 'per_page', 'total',
        ]);
});

it('serializa las cifras como numeros y no como strings', function () {
    $user = User::factory()->create();
    $headers = $this->actingAsJwtUser($user);
    $band = bandWith($user, 'Necesidades', 50);
    $comida = categoryInBand($user, $band, 'Comida', 800);
    paretoSpendOn($user, $comida, 1500, '2025-03-10');

    $payload = $this->getJson('/api/pareto-classification?month=3&year=2025', $headers)
        ->assertOk()
        ->json('data');

    $reported = collect($payload)->firstWhere('id', $band->id);

    // PDO devuelve `numeric` como string de PHP, y el JSON los llevaba entrecomillados
    // hasta aca. En JavaScript `"1500.00" > "800.00"` compara caracter por caracter y
    // da FALSE, asi que una banda 87% pasada de presupuesto se pintaba en verde. El
    // camino en PHP devuelve floats; esto es lo que impide que vuelvan a ser texto.
    // `not->toBeString()` y no `toBeFloat()`: el JSON de 1500.0 vuelve como int y eso
    // esta bien. Lo unico que rompe al cliente es que llegue entrecomillado.
    foreach (['spent', 'monthly_budget', 'percentage_spent', 'available_budget'] as $field) {
        expect($reported[$field])->toBeNumeric()->not->toBeString();
    }
    expect($reported['categories'][0]['budget_in_window'])->toBeNumeric()->not->toBeString();
    expect($reported['spent'] > $reported['monthly_budget'])->toBeTrue();
});

it('reporta los totales de la ventana en cada banda', function () {
    $user = User::factory()->create();
    $band = bandWith($user, 'Necesidades', 50);
    $comida = categoryInBand($user, $band, 'Comida', 400);

    paretoSpendOn($user, $comida, 200, '2025-03-10');
    paretoSpendOn($user, $comida, 900, '2025-03-11', type: 'income');

    $report = paretoReport($user, 3);

    expect((float) $report[$band->id]['total_expense'])->toBe(200.0);
    expect((float) $report[$band->id]['total_income'])->toBe(900.0);
});
