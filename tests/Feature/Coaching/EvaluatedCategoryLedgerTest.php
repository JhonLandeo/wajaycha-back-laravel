<?php

declare(strict_types=1);

use App\DTOs\Coaching\CategoryMonthSnapshot;
use App\DTOs\Coaching\MonthCursor;
use App\DTOs\Coaching\PaceObservation;
use App\Enums\BudgetPeriod;
use App\Models\Category;
use App\Models\CoachingEvaluation;
use App\Models\User;
use App\Services\Coaching\EvaluatedCategoryLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->ledger = app(EvaluatedCategoryLedger::class);
    $this->user = User::factory()->create();
    $this->category = Category::factory()->create(['user_id' => $this->user->id, 'type' => 'expense']);
    $this->cursor = MonthCursor::forInstant(CarbonImmutable::now('America/Lima'));
});

function evaluatedSnapshot(Tests\TestCase $test, array $overrides = []): CategoryMonthSnapshot
{
    $args = array_merge([
        'categoryId' => $test->category->id,
        'name' => 'Delivery',
        'type' => 'expense',
        'monthlyBudget' => 300.0,
        'spent' => 220.0,
        'largestExpenseAmount' => 40.0,
        'budgetPeriod' => BudgetPeriod::MONTHLY,
        'spentInYear' => 220.0,
    ], $overrides);

    return new CategoryMonthSnapshot(...$args);
}

function evaluatedObservation(Tests\TestCase $test, array $overrides = []): PaceObservation
{
    $args = array_merge([
        'subjectKey' => "category:{$test->category->id}",
        'categoryId' => $test->category->id,
        'name' => 'Delivery',
        'band' => 'over_budget',
        'isLumpy' => false,
        'spent' => 340.0,
        'budget' => 300.0,
        'projected' => null,
        'dayOfMonth' => 18,
    ], $overrides);

    return new PaceObservation(...$args);
}

it('records a budgeted category that crossed nothing as clean', function () {
    $this->ledger->record($this->user->id, $this->cursor, [evaluatedSnapshot($this)], []);

    $row = CoachingEvaluation::sole();

    expect($row->outcome)->toBe(CoachingEvaluation::OUTCOME_CLEAN)
        ->and($row->category_id)->toBe($this->category->id)
        ->and($row->period_month->toDateString())->toBe($this->cursor->periodMonth->startOfMonth()->toDateString())
        ->and((float) $row->budget_amount)->toBe(300.0)
        ->and((float) $row->spent_amount)->toBe(220.0);
});

it('records the band when the category did cross one', function () {
    $this->ledger->record(
        $this->user->id,
        $this->cursor,
        [evaluatedSnapshot($this, ['spent' => 340.0])],
        [evaluatedObservation($this)],
    );

    expect(CoachingEvaluation::sole()->outcome)->toBe('over_budget');
});

it('records a category with spending and no budget as blind, never clean', function () {
    $this->ledger->record(
        $this->user->id,
        $this->cursor,
        [evaluatedSnapshot($this, ['monthlyBudget' => 0.0, 'spent' => 150.0])],
        [],
    );

    expect(CoachingEvaluation::sole()->outcome)->toBe(CoachingEvaluation::OUTCOME_BLIND);
});

it('overwrites the month with the later verdict instead of appending a second row', function () {
    // Dia 3: limpia. Dia 18: pasada. El mes tiene que terminar registrado como
    // pasada — este es el caso que hace que "ultimo gana" sea la semantica y no
    // un efecto colateral del upsert.
    $this->ledger->record($this->user->id, $this->cursor, [evaluatedSnapshot($this)], []);
    $this->ledger->record(
        $this->user->id,
        $this->cursor,
        [evaluatedSnapshot($this, ['spent' => 340.0])],
        [evaluatedObservation($this)],
    );

    expect(CoachingEvaluation::count())->toBe(1)
        ->and(CoachingEvaluation::sole()->outcome)->toBe('over_budget')
        ->and((float) CoachingEvaluation::sole()->spent_amount)->toBe(340.0);
});

it('keeps one row per category-month, not per category', function () {
    $other = Category::factory()->create(['user_id' => $this->user->id, 'type' => 'expense']);

    $this->ledger->record($this->user->id, $this->cursor, [
        evaluatedSnapshot($this),
        evaluatedSnapshot($this, ['categoryId' => $other->id, 'name' => 'Transporte']),
    ], []);

    expect(CoachingEvaluation::count())->toBe(2);
});

it('stores the year figure beside an envelope verdict, not the month', function () {
    $this->ledger->record($this->user->id, $this->cursor, [
        evaluatedSnapshot($this, [
            'monthlyBudget' => 2000.0,
            'spent' => 480.0,
            'budgetPeriod' => BudgetPeriod::YEARLY,
            'spentInYear' => 1700.0,
        ]),
    ], []);

    $row = CoachingEvaluation::sole();

    expect($row->budget_period)->toBe('yearly')
        ->and((float) $row->spent_amount)->toBe(1700.0);
});

it('writes nothing when the month has no evaluable category', function () {
    $this->ledger->record($this->user->id, $this->cursor, [], []);

    expect(CoachingEvaluation::count())->toBe(0);
});
