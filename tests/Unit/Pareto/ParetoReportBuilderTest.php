<?php

declare(strict_types=1);

use App\DTOs\Pareto\BudgetedCategoryRow;
use App\DTOs\Pareto\ParetoBandRow;
use App\DTOs\Pareto\ParetoWindow;
use App\DTOs\Pareto\ParetoWindowTotals;
use App\Enums\BudgetPeriod;
use App\Services\Pareto\ParetoReportBuilder;
use Illuminate\Support\Carbon;

/**
 * The Pareto rules, asserted against values.
 *
 * There is no database in this file, and that is the point. While the report was
 * `get_pareto_monthly_report` every one of these questions needed a seeded workspace,
 * a user, three bands and a handful of transactions to ask — which is exactly the
 * cost [ADR-0009](../../../docs/decisions/0009-coach-narrates-does-not-advise.md)
 * names when it requires Financial Analysis rules to leave PostgreSQL.
 */

function paretoBand(int $id, string $name, float $percentage = 50): ParetoBandRow
{
    return new ParetoBandRow($id, $name, $percentage);
}

function paretoLeaf(
    int $id,
    string $name,
    float $budget,
    ?int $bandId,
    BudgetPeriod $period = BudgetPeriod::MONTHLY
): BudgetedCategoryRow {
    return new BudgetedCategoryRow($id, $name, 'expense', $budget, $period, $bandId);
}

function paretoWindowOf(?int $month, ?int $year, int $monthsWithActivity = 1, string $today = '2026-08-19'): ParetoWindow
{
    return ParetoWindow::forFilter($month, $year, $monthsWithActivity, Carbon::parse($today));
}

function paretoBuild(
    array $bands,
    array $categories,
    array $spentInWindow = [],
    array $spentInYear = [],
    ?ParetoWindow $window = null,
    ?ParetoWindowTotals $totals = null
): array {
    $reports = (new ParetoReportBuilder)->build(
        $bands,
        $categories,
        $spentInWindow,
        $spentInYear,
        $window ?? paretoWindowOf(3, 2025),
        $totals ?? new ParetoWindowTotals(0, 0)
    );

    return collect($reports)->keyBy('id')->all();
}

// ------------------------------------------------------------------ el ritmo

it('deja el sobre anual fuera del presupuesto de la banda', function () {
    $report = paretoBuild(
        [paretoBand(1, 'Necesidades')],
        [paretoLeaf(10, 'Comida', 400, 1), paretoLeaf(20, 'Salud', 1200, 1, BudgetPeriod::YEARLY)]
    );

    // 400, no 1600. El sobre no participa de una cifra mensual.
    expect($report[1]->monthlyBudget)->toBe(400.0);
});

it('deja el consumo del sobre anual fuera del gastado de la banda', function () {
    $report = paretoBuild(
        [paretoBand(1, 'Necesidades')],
        [paretoLeaf(10, 'Comida', 400, 1), paretoLeaf(20, 'Salud', 1200, 1, BudgetPeriod::YEARLY)],
        spentInWindow: [10 => 100.0, 20 => 300.0],
        spentInYear: [10 => 100.0, 20 => 300.0]
    );

    expect($report[1]->spent)->toBe(100.0);
    expect($report[1]->percentageSpent())->toBe(25.0);
    expect($report[1]->availableBudget())->toBe(300.0);
});

it('reporta cero por ciento consumido en vez de dividir por un presupuesto vacio', function () {
    $report = paretoBuild([paretoBand(1, 'Necesidades')], [paretoLeaf(10, 'Comida', 0, 1)], spentInWindow: [10 => 50.0]);

    expect($report[1]->percentageSpent())->toBe(0.0);
});

// ------------------------------------------------------------------ el badge

it('pesa el sobre anual a un doceavo en el porcentaje configurado', function () {
    $report = paretoBuild(
        [paretoBand(1, 'Ritmo'), paretoBand(2, 'Sobres')],
        [paretoLeaf(10, 'Comida', 100, 1), paretoLeaf(20, 'Salud', 1200, 2, BudgetPeriod::YEARLY)]
    );

    // S/ 1200 al año pesan lo mismo que S/ 100 al mes.
    expect($report[1]->actualPercentage)->toBe(50.0);
    expect($report[2]->actualPercentage)->toBe(50.0);
});

it('cuenta las categorias sin banda en el total del que sale cada porcentaje', function () {
    $report = paretoBuild(
        [paretoBand(1, 'Necesidades')],
        [paretoLeaf(10, 'Comida', 100, 1), paretoLeaf(99, 'Sin banda', 300, null)]
    );

    // 100 de 400. Ignorar la huerfana daria 100% e inflaria toda banda existente.
    expect($report[1]->actualPercentage)->toBe(25.0);
});

it('no divide por cero cuando nadie presupuesto nada', function () {
    $report = paretoBuild([paretoBand(1, 'Necesidades')], [paretoLeaf(10, 'Comida', 0, 1)]);

    expect($report[1]->actualPercentage)->toBe(0.0);
});

// ----------------------------------------------------------------- la ventana

it('escala el presupuesto mensual a los meses de la ventana', function () {
    $report = paretoBuild(
        [paretoBand(1, 'Necesidades')],
        [paretoLeaf(10, 'Comida', 400, 1)],
        spentInWindow: [10 => 400.0],
        window: paretoWindowOf(null, 2025)
    );

    // Antes: S/ 400 de un mes contra el gasto de doce -> toda banda al 100%+.
    expect($report[1]->monthlyBudget)->toBe(4800.0);
    expect($report[1]->percentageSpent())->toBe(8.33);
});

it('no escala el sobre anual, que ya cubre el año', function () {
    $report = paretoBuild(
        [paretoBand(1, 'Necesidades')],
        [paretoLeaf(20, 'Salud', 1200, 1, BudgetPeriod::YEARLY)],
        window: paretoWindowOf(null, 2025)
    );

    expect($report[1]->categories[0]->budgetInWindow)->toBe(1200.0);
});

it('cuenta solo los meses transcurridos del año en curso', function () {
    $window = paretoWindowOf(null, 2026, today: '2026-08-19');

    // Doce contra ocho meses de gasto halagaria a toda banda hasta diciembre.
    expect($window->budgetMonths)->toBe(8);
});

it('cuenta el año entero cuando ya paso', function () {
    expect(paretoWindowOf(null, 2025, today: '2026-08-19')->budgetMonths)->toBe(12);
});

it('cae en los meses con movimiento cuando no se filtra nada', function () {
    expect(paretoWindowOf(null, null, monthsWithActivity: 7)->budgetMonths)->toBe(7);
});

it('nunca deja la ventana en cero meses', function () {
    expect(paretoWindowOf(null, null, monthsWithActivity: 0)->budgetMonths)->toBe(1);
});

// ------------------------------------------------------- consumo por categoria

it('mide el sobre sobre el año y la mensual sobre el mes filtrado', function () {
    $report = paretoBuild(
        [paretoBand(1, 'Necesidades')],
        [paretoLeaf(10, 'Comida', 400, 1), paretoLeaf(20, 'Salud', 1200, 1, BudgetPeriod::YEARLY)],
        spentInWindow: [10 => 150.0, 20 => 0.0],
        spentInYear: [10 => 900.0, 20 => 340.0]
    );

    $lines = collect($report[1]->categories)->keyBy('id');

    expect($lines[10]->spent)->toBe(150.0);
    // El sobre reporta enero aunque se haya pedido marzo.
    expect($lines[20]->spent)->toBe(340.0);
});

// ------------------------------------------------------------------- el orden

it('ordena las mensuales por monto y deja los sobres al final', function () {
    $report = paretoBuild(
        [paretoBand(1, 'Necesidades')],
        [
            paretoLeaf(20, 'Salud', 5000, 1, BudgetPeriod::YEARLY),
            paretoLeaf(10, 'Comida', 400, 1),
            paretoLeaf(30, 'Transporte', 900, 1),
        ]
    );

    expect(collect($report[1]->categories)->pluck('name')->all())
        ->toBe(['Transporte', 'Comida', 'Salud']);
});

// --------------------------------------------------------- contrato de salida

it('expone el monto crudo aparte del de la ventana', function () {
    $report = paretoBuild(
        [paretoBand(1, 'Necesidades')],
        [paretoLeaf(10, 'Comida', 400, 1)],
        window: paretoWindowOf(null, 2025)
    );

    $line = $report[1]->categories[0]->toArray();

    // El crudo es lo que la edicion inline reescribe en la columna; escalarlo
    // multiplicaria el presupuesto del usuario por doce en cada guardado.
    expect($line['monthly_budget'])->toBe(400.0);
    expect($line['budget_in_window'])->toBe(4800.0);
    expect($line['budget_period'])->toBe('monthly');
});

it('devuelve una banda vacia en vez de omitirla', function () {
    $report = paretoBuild([paretoBand(1, 'Vacia')], []);

    expect($report[1]->categories)->toBe([]);
    expect($report[1]->monthlyBudget)->toBe(0.0);
    expect($report[1]->toArray()['categories'])->toBe([]);
});

// ------------------------------------------------- lo que entra contra lo asignado

it('suma en lo asignado las categorias que no estan en ninguna banda', function () {
    $report = paretoBuild(
        [paretoBand(1, 'Necesidades')],
        [paretoLeaf(10, 'Comida', 400, 1), paretoLeaf(99, 'Sin banda', 600, null)]
    );

    // 1000, no 400. Una categoria fuera del Pareto sigue comprometiendo ingreso, y
    // omitirla reportaria como libre una plata que ya tiene dueño.
    expect($report[1]->totalBudgeted)->toBe(1000.0);
});

it('escala lo asignado a la ventana igual que al presupuesto de una categoria', function () {
    $report = paretoBuild(
        [paretoBand(1, 'Necesidades')],
        [paretoLeaf(10, 'Comida', 400, 1)],
        window: paretoWindowOf(null, 2025)
    );

    // Un año cerrado son doce meses de presupuesto contra doce de ingreso.
    expect($report[1]->totalBudgeted)->toBe(4800.0);
});

it('cuenta el sobre anual a un doceavo en lo asignado del mes', function () {
    $report = paretoBuild(
        [paretoBand(1, 'Necesidades')],
        [paretoLeaf(20, 'Salud', 1200, 1, BudgetPeriod::YEARLY)]
    );

    // 100, no 1200: contra un mes de ingreso, un sobre anual pesa su doceavo.
    expect($report[1]->totalBudgeted)->toBe(100.0);
});

it('publica el ingreso junto a lo asignado para que el reparto sea calculable', function () {
    $report = paretoBuild(
        [paretoBand(1, 'Necesidades')],
        [paretoLeaf(10, 'Comida', 400, 1)],
        totals: new ParetoWindowTotals(1000.0, 250.0)
    );

    $row = $report[1]->toArray();

    expect($row['total_income'])->toBe(1000.0);
    expect($row['total_budgeted'])->toBe(400.0);
    // El badge de la banda NO cambia de denominador: sigue siendo el reparto
    // interno entre bandas, no una porcion del ingreso.
    expect($row['actual_percentage'])->toBe(100.0);
});

it('no inventa un asignado cuando el usuario no presupuesto nada', function () {
    $report = paretoBuild(
        [paretoBand(1, 'Necesidades')],
        [],
        totals: new ParetoWindowTotals(1000.0, 0.0)
    );

    expect($report[1]->totalBudgeted)->toBe(0.0);
});
