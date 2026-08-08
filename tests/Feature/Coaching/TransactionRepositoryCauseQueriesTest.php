<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\TransactionRepository;
use Carbon\CarbonImmutable;

function monthRange(): array
{
    $startsAt = CarbonImmutable::create(2026, 8, 1, 0, 0, 0, 'America/Lima');

    return [$startsAt, $startsAt->addMonthNoOverflow()];
}

it('returns a single-transaction merchant, proving there is no HAVING COUNT > 1 hole', function () {
    [$startsAt, $endsAt] = monthRange();
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);
    $detail = Detail::factory()->create(['user_id' => $user->id, 'description' => 'Farmacia Unica']);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'detail_id' => $detail->id,
        'type_transaction' => 'expense',
        'amount' => 45.50,
        'date_operation' => $startsAt->addDays(5)->toDateTimeString(),
    ]);

    $repository = new TransactionRepository;
    $merchants = $repository->topMerchantsForCategoryMonth($user->id, $category->id, $startsAt, $endsAt, 3);

    expect($merchants)->toHaveCount(1);
    expect($merchants[0]->detail_name)->toBe('Farmacia Unica');
    expect((float) $merchants[0]->total)->toBe(45.50);
    expect((int) $merchants[0]->frequency)->toBe(1);
});

it('sums repeated merchants and orders by total spend descending', function () {
    [$startsAt, $endsAt] = monthRange();
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);
    $bigMerchant = Detail::factory()->create(['user_id' => $user->id, 'description' => 'Supermercado Grande']);
    $smallMerchant = Detail::factory()->create(['user_id' => $user->id, 'description' => 'Bodega Chica']);

    Transaction::factory()->count(3)->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'detail_id' => $bigMerchant->id,
        'type_transaction' => 'expense',
        'amount' => 100,
        'date_operation' => $startsAt->addDays(2)->toDateTimeString(),
    ]);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'detail_id' => $smallMerchant->id,
        'type_transaction' => 'expense',
        'amount' => 10,
        'date_operation' => $startsAt->addDays(3)->toDateTimeString(),
    ]);

    $repository = new TransactionRepository;
    $merchants = $repository->topMerchantsForCategoryMonth($user->id, $category->id, $startsAt, $endsAt, 5);

    expect($merchants)->toHaveCount(2);
    expect($merchants[0]->detail_name)->toBe('Supermercado Grande');
    expect((float) $merchants[0]->total)->toBe(300.0);
    expect((int) $merchants[0]->frequency)->toBe(3);
    expect($merchants[1]->detail_name)->toBe('Bodega Chica');
});

it('excludes transactions outside the half-open month range', function () {
    [$startsAt, $endsAt] = monthRange();
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);
    $detail = Detail::factory()->create(['user_id' => $user->id, 'description' => 'Comerciante Fronterizo']);

    // Inside range: the first instant of the month.
    Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'detail_id' => $detail->id,
        'type_transaction' => 'expense',
        'amount' => 20,
        'date_operation' => $startsAt->toDateTimeString(),
    ]);

    // Outside range: the last instant of the previous month.
    Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'detail_id' => $detail->id,
        'type_transaction' => 'expense',
        'amount' => 999,
        'date_operation' => $startsAt->subSecond()->toDateTimeString(),
    ]);

    // Outside range: exactly the first instant of the next month (half-open end).
    Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'detail_id' => $detail->id,
        'type_transaction' => 'expense',
        'amount' => 999,
        'date_operation' => $endsAt->toDateTimeString(),
    ]);

    $repository = new TransactionRepository;
    $merchants = $repository->topMerchantsForCategoryMonth($user->id, $category->id, $startsAt, $endsAt, 5);

    expect($merchants)->toHaveCount(1);
    expect((float) $merchants[0]->total)->toBe(20.0);
});

it('only reads expense transactions, never income, from v_unified_transactions', function () {
    [$startsAt, $endsAt] = monthRange();
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);
    $detail = Detail::factory()->create(['user_id' => $user->id, 'description' => 'Reembolso y gasto']);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'detail_id' => $detail->id,
        'type_transaction' => 'expense',
        'amount' => 30,
        'date_operation' => $startsAt->addDays(1)->toDateTimeString(),
    ]);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'detail_id' => $detail->id,
        'type_transaction' => 'income',
        'amount' => 500,
        'date_operation' => $startsAt->addDays(1)->toDateTimeString(),
    ]);

    $repository = new TransactionRepository;
    $merchants = $repository->topMerchantsForCategoryMonth($user->id, $category->id, $startsAt, $endsAt, 5);

    expect($merchants)->toHaveCount(1);
    expect((float) $merchants[0]->total)->toBe(30.0);
});

it('returns the single largest expense row for a category-month', function () {
    [$startsAt, $endsAt] = monthRange();
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);
    $detail = Detail::factory()->create(['user_id' => $user->id, 'description' => 'Tienda de Electronica']);
    $smallDetail = Detail::factory()->create(['user_id' => $user->id, 'description' => 'Kiosko']);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'detail_id' => $smallDetail->id,
        'type_transaction' => 'expense',
        'amount' => 15,
        'date_operation' => $startsAt->addDays(4)->toDateTimeString(),
    ]);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'detail_id' => $detail->id,
        'type_transaction' => 'expense',
        'amount' => 850,
        'date_operation' => $startsAt->addDays(10)->toDateTimeString(),
    ]);

    $repository = new TransactionRepository;
    $largest = $repository->largestExpenseForCategoryMonth($user->id, $category->id, $startsAt, $endsAt);

    expect($largest)->not->toBeNull();
    expect($largest->detail_name)->toBe('Tienda de Electronica');
    expect((float) $largest->amount)->toBe(850.0);
});

it('returns null for largest expense when the category has no expense this month', function () {
    [$startsAt, $endsAt] = monthRange();
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);

    $repository = new TransactionRepository;
    $largest = $repository->largestExpenseForCategoryMonth($user->id, $category->id, $startsAt, $endsAt);

    expect($largest)->toBeNull();
});
