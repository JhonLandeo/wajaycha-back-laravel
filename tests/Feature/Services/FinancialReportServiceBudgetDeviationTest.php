<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinancialReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Task 6.1/design.md §8: `getBudgetDeviation()` returns `id, name, budgeted, spent,
 * available_budget, percentage_spent` today — no `variance`, no `status`. The blade
 * that renders it (`resources/views/emails/summary_month.blade.php`) reads
 * `$item->variance` and `$item->status`, so both rows silently render blank. This
 * test proves the service boundary carries both fields, not just that a fix exists
 * somewhere in the pipeline.
 */
function budgetDeviationCategory(User $user, float $monthlyBudget, float $spentAmount): Category
{
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'monthly_budget' => $monthlyBudget,
    ]);

    $detail = Detail::factory()->create(['user_id' => $user->id]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'category_id' => $category->id,
        'type_transaction' => 'expense',
        'amount' => $spentAmount,
        'date_operation' => now()->toDateTimeString(),
    ]);

    return $category;
}

it('carries a non-null variance and an "Excedido" status when spend exceeds the budget', function () {
    $user = User::factory()->create();
    $category = budgetDeviationCategory($user, 200.0, 350.0);

    $service = app(FinancialReportService::class);

    $rows = $service->getBudgetDeviation($user->id, (int) now()->month, (int) now()->year);
    $row = $rows->firstWhere('id', $category->id);

    expect($row)->not->toBeNull()
        ->and((float) $row->variance)->toBe(-150.0)
        ->and($row->status)->toBe('Excedido');
});

/**
 * Triangulation: the same computation on the opposite side of the threshold — spend
 * under budget must yield a positive variance and "Dentro", proving `status` is a
 * real comparison and not a hardcoded string.
 */
it('carries a non-null variance and a "Dentro" status when spend stays under the budget', function () {
    $user = User::factory()->create();
    $category = budgetDeviationCategory($user, 500.0, 120.0);

    $service = app(FinancialReportService::class);

    $rows = $service->getBudgetDeviation($user->id, (int) now()->month, (int) now()->year);
    $row = $rows->firstWhere('id', $category->id);

    expect($row)->not->toBeNull()
        ->and((float) $row->variance)->toBe(380.0)
        ->and($row->status)->toBe('Dentro');
});
