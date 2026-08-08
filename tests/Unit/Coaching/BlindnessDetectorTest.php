<?php

declare(strict_types=1);

use App\DTOs\Coaching\CategoryMonthSnapshot;
use App\Services\Coaching\BlindnessDetector;

/**
 * BlindnessDetector is deliberately NOT part of PaceEvaluator (design.md §5.1: `blind`
 * is "not comparable", so it is not a band on the pace ladder). It reports what the
 * coach cannot watch — an unbudgeted category with spend — rather than staying silent,
 * because silence reads as "you're fine" (owner decision, task 4.5b/4.5c).
 */
function blindSnapshot(
    int $categoryId = 1,
    string $name = 'Salud',
    string $type = 'expense',
    float $monthlyBudget = 0.0,
    float $spent = 80.0,
): CategoryMonthSnapshot {
    return new CategoryMonthSnapshot(
        categoryId: $categoryId,
        name: $name,
        type: $type,
        monthlyBudget: $monthlyBudget,
        spent: $spent,
        largestExpenseAmount: 0.0,
    );
}

it('reports a category with spend and monthly_budget = 0 as unwatchable', function () {
    $snapshot = blindSnapshot(name: 'Salud', monthlyBudget: 0.0, spent: 80.0);

    $blindness = (new BlindnessDetector)->detect([$snapshot]);

    expect($blindness)->not->toBeNull();
    expect($blindness->categoryNames)->toBe(['Salud']);
    expect($blindness->totalSpent)->toBe(80.0);
});

it('does not report a category with monthly_budget = 0 and no spend at all', function () {
    $snapshot = blindSnapshot(name: 'Salud', monthlyBudget: 0.0, spent: 0.0);

    $blindness = (new BlindnessDetector)->detect([$snapshot]);

    expect($blindness)->toBeNull();
});

it('never reports a budgeted category, no matter how much it spent', function () {
    $snapshot = blindSnapshot(name: 'Comida', monthlyBudget: 400.0, spent: 5000.0);

    $blindness = (new BlindnessDetector)->detect([$snapshot]);

    expect($blindness)->toBeNull();
});

it('only considers expense categories', function () {
    $income = blindSnapshot(name: 'Salario', type: 'income', monthlyBudget: 0.0, spent: 3000.0);
    $transfer = blindSnapshot(categoryId: 2, name: 'Ahorro', type: 'transfer', monthlyBudget: 0.0, spent: 500.0);

    $blindness = (new BlindnessDetector)->detect([$income, $transfer]);

    expect($blindness)->toBeNull();
});

it('produces exactly one observation for the whole set, never one per category', function () {
    $first = blindSnapshot(categoryId: 1, name: 'Salud', monthlyBudget: 0.0, spent: 80.0);
    $second = blindSnapshot(categoryId: 2, name: 'Mascotas', monthlyBudget: 0.0, spent: 70.5);
    $budgeted = blindSnapshot(categoryId: 3, name: 'Comida', monthlyBudget: 400.0, spent: 340.0);
    $noSpend = blindSnapshot(categoryId: 4, name: 'Educación', monthlyBudget: 0.0, spent: 0.0);

    $blindness = (new BlindnessDetector)->detect([$first, $second, $budgeted, $noSpend]);

    expect($blindness)->not->toBeNull();
    expect($blindness->categoryNames)->toBe(['Salud', 'Mascotas']);
    expect($blindness->totalSpent)->toBe(150.5);
});

it('returns null when nothing is blind', function () {
    $budgeted = blindSnapshot(name: 'Comida', monthlyBudget: 400.0, spent: 340.0);
    $noSpend = blindSnapshot(categoryId: 2, name: 'Educación', monthlyBudget: 0.0, spent: 0.0);

    $blindness = (new BlindnessDetector)->detect([$budgeted, $noSpend]);

    expect($blindness)->toBeNull();
});
