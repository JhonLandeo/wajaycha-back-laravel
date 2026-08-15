<?php

declare(strict_types=1);

use App\Mail\NotificationSummaryByMonth;
use App\Models\Category;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * Task 6.3/design.md §8: the command hardcodes `$userId = 1` — a pre-existing
 * characteristic, unrelated to this phase's scope, not something being fixed
 * here. Postgres sequences are NOT rolled back by `RefreshDatabase`'s per-test
 * transaction (`nextval()` survives ROLLBACK by design), so "the first user
 * created in this file" only equals id 1 when the file happens to run first in
 * the whole suite. Forcing `id: 1` explicitly makes the fixture deterministic
 * regardless of test execution order — the row itself is still rolled back per
 * test, so id 1 is free again even mid-suite.
 */
function monthlySummaryOwner(): User
{
    return User::factory()->create(['id' => 1]);
}

function monthlySummaryOverBudgetCategory(User $user): Category
{
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'monthly_budget' => 200.0,
    ]);

    $detail = Detail::factory()->create(['user_id' => $user->id]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'category_id' => $category->id,
        'type_transaction' => 'expense',
        'amount' => 350.0,
        'date_operation' => now()->subMonth()->toDateTimeString(),
    ]);

    return $category;
}

/**
 * Before this fix `getBudgetDeviation()` returns `spent`, not `real` — the blade
 * and the WhatsApp block both read a property that never existed, rendering
 * `S/ 0.00` everywhere. This proves the queued mail carries a real, non-zero
 * figure and a real category name for the fixture category, not a blank row.
 */
it('queues the monthly email with a non-zero real spend and a non-blank category name', function () {
    $user = monthlySummaryOwner();
    $category = monthlySummaryOverBudgetCategory($user);

    Mail::fake();
    Http::fake();

    Artisan::call('app:send-summary-transaction-by-month');

    Mail::assertQueued(NotificationSummaryByMonth::class, function (NotificationSummaryByMonth $mail) use ($category) {
        $row = $mail->budgetDeviation->firstWhere('id', $category->id);

        return $row !== null
            && $row->name !== ''
            && (float) $row->spent === 350.0;
    });
});

/**
 * D-level assertion (design.md §8, "the WhatsApp push goes away entirely"): the
 * command must make ZERO outbound HTTP calls. Triangulated against the test
 * above using the exact same over-budget fixture that used to trigger the
 * WhatsApp block's "Excedido" branch.
 */
it('makes zero WhatsApp calls', function () {
    $user = monthlySummaryOwner();
    monthlySummaryOverBudgetCategory($user);

    Mail::fake();
    Http::fake();

    Artisan::call('app:send-summary-transaction-by-month');

    Http::assertNothingSent();
});

/**
 * Design.md §8's "Additional finding": the blade read `$item->category`, `$item->real`,
 * `$item->variance` and `$item->status` while the service returned `id, name, budgeted,
 * spent, available_budget, percentage_spent` — three of four properties never existed,
 * so the table rendered a blank category and S/ 0.00 in every row. `Mail::fake()`
 * intercepts before the view renders, so the two tests above cannot catch this — only
 * an actual `->render()` proves the template itself is fixed. `UserObserver` seeds
 * default zero-budget categories on user creation, so the table legitimately contains
 * other "S/ 0.00" rows; this asserts the fixture category's own real figures render,
 * not a blanket absence of zero anywhere in the table.
 */
it('renders the category name and the real spent amount in the email body, not a blank row', function () {
    $user = monthlySummaryOwner();
    $category = monthlySummaryOverBudgetCategory($user);

    $lastMonth = now()->subMonth();
    $budgetDeviation = app(\App\Services\FinancialReportService::class)
        ->getBudgetDeviation($user->id, $lastMonth->month, $lastMonth->year);

    $html = (new NotificationSummaryByMonth($budgetDeviation, $lastMonth->translatedFormat('F')))->render();

    expect($html)->toContain($category->name)
        ->and($html)->toContain('S/ 200.00')
        ->and($html)->toContain('S/ 350.00')
        ->and($html)->toContain('S/ -150.00');
});
