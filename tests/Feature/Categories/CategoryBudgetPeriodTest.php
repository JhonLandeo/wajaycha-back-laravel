<?php

declare(strict_types=1);

/**
 * The `budget_period` column as it travels through the HTTP boundary.
 *
 * The column decides which arithmetic the coach applies to a category — a rate
 * that can be projected, or a yearly envelope that can only be consumed — so a
 * value that fails to round-trip does not surface as a validation error. It
 * surfaces months later as the coach saying "ya pasaste el presupuesto" about a
 * budget the user has not exceeded, which is the exact defect this column was
 * added to end.
 *
 * The update case is the one worth having. `budget_period` is optional on both
 * requests, so a client that predates the column omits it on every save — and if
 * absent were coerced to 'monthly' anywhere between the request and the row, an
 * unrelated rename would quietly demote a yearly envelope back to a monthly one.
 */

use App\Enums\BudgetPeriod;
use App\Models\Category;
use App\Models\ParetoClassification;
use App\Models\User;

/**
 * @return array{0: User, 1: array<string, string>}
 */
function budgetPeriodOwner(): array
{
    /** @var \Tests\TestCase $t */
    return test()->userWithAuth();
}

it('crea una categoria mensual cuando el cliente no manda budget_period', function () {
    [$user, $headers] = budgetPeriodOwner();
    $pareto = ParetoClassification::factory()->create(['user_id' => $user->id]);

    $this->postJson('/api/categories', [
        'name' => 'Comida',
        'type' => 'expense',
        'monthly_budget' => 400,
        'pareto_classification_id' => $pareto->id,
    ], $headers)->assertCreated();

    expect(Category::query()->where('name', 'Comida')->value('budget_period'))
        ->toBe(BudgetPeriod::MONTHLY->value);
});

it('guarda un sobre anual cuando el cliente lo pide', function () {
    [$user, $headers] = budgetPeriodOwner();
    $pareto = ParetoClassification::factory()->create(['user_id' => $user->id]);

    $this->postJson('/api/categories', [
        'name' => 'Salud',
        'type' => 'expense',
        'monthly_budget' => 1200,
        'budget_period' => 'yearly',
        'pareto_classification_id' => $pareto->id,
    ], $headers)->assertCreated();

    expect(Category::query()->where('name', 'Salud')->value('budget_period'))
        ->toBe(BudgetPeriod::YEARLY->value);
});

it('rechaza un budget_period que no existe', function () {
    [$user, $headers] = budgetPeriodOwner();
    $pareto = ParetoClassification::factory()->create(['user_id' => $user->id]);

    $this->postJson('/api/categories', [
        'name' => 'Salud',
        'type' => 'expense',
        'monthly_budget' => 1200,
        'budget_period' => 'quarterly',
        'pareto_classification_id' => $pareto->id,
    ], $headers)->assertJsonValidationErrors('budget_period');
});

it('cambia una categoria mensual a sobre anual', function () {
    [$user, $headers] = budgetPeriodOwner();
    $pareto = ParetoClassification::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'monthly_budget' => 100,
        'budget_period' => BudgetPeriod::MONTHLY->value,
    ]);

    $this->putJson("/api/categories/{$category->id}", [
        'name' => 'Salud',
        'type' => 'expense',
        'monthly_budget' => 1200,
        'budget_period' => 'yearly',
        'pareto_classification_id' => $pareto->id,
    ], $headers)->assertOk();

    expect($category->fresh()->budget_period)->toBe(BudgetPeriod::YEARLY->value);
});

it('no degrada un sobre anual cuando la actualizacion no menciona el periodo', function () {
    [$user, $headers] = budgetPeriodOwner();
    $pareto = ParetoClassification::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'name' => 'Salud',
        'monthly_budget' => 1200,
        'budget_period' => BudgetPeriod::YEARLY->value,
    ]);

    // Un cliente que no conoce la columna renombrando la categoria.
    $this->putJson("/api/categories/{$category->id}", [
        'name' => 'Salud y farmacia',
        'type' => 'expense',
        'monthly_budget' => 1200,
        'pareto_classification_id' => $pareto->id,
    ], $headers)->assertOk();

    expect($category->fresh())
        ->name->toBe('Salud y farmacia')
        ->budget_period->toBe(BudgetPeriod::YEARLY->value);
});
