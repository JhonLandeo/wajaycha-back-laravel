<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// design.md D6: subject_key is NOT NULL on purpose, because PostgreSQL treats
// NULLs as distinct in a unique index. A nullable category_id inside the unique
// key would let the blindness report be spoken every single day. This test
// proves the database-level guard exists — not that the migration file merely
// mentions it.
it('rejects a row with subject_key NULL at the database level', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);

    // Wrapped in its own DB::transaction() on purpose: Postgres aborts the
    // enclosing transaction on a constraint violation (25P02), and without this
    // savepoint the assertion below would fail for the wrong reason — the outer
    // (RefreshDatabase) transaction, not the missing subject_key.
    $insertRowWithNullSubjectKey = function () use ($user, $category) {
        DB::transaction(function () use ($user, $category) {
            DB::table('coaching_observations')->insert([
                'user_id' => $user->id,
                'period_month' => now()->startOfMonth()->toDateString(),
                'subject_key' => null,
                'category_id' => $category->id,
                'band' => 'over_budget',
                'is_lumpy' => false,
                'budget_amount' => 400.00,
                'spent_amount' => 450.00,
                'projected_amount' => null,
                'day_of_month' => 12,
                'entry_point' => 'sweep',
                'spoken_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    };

    expect($insertRowWithNullSubjectKey)->toThrow(QueryException::class);
    expect(DB::table('coaching_observations')->count())->toBe(0);
});

it('accepts the same row once subject_key is present', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);

    DB::table('coaching_observations')->insert([
        'user_id' => $user->id,
        'period_month' => now()->startOfMonth()->toDateString(),
        'subject_key' => "category:{$category->id}",
        'category_id' => $category->id,
        'band' => 'over_budget',
        'is_lumpy' => false,
        'budget_amount' => 400.00,
        'spent_amount' => 450.00,
        'projected_amount' => null,
        'day_of_month' => 12,
        'entry_point' => 'sweep',
        'spoken_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('coaching_observations')->count())->toBe(1);
});
