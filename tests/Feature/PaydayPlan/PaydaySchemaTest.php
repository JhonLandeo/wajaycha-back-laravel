<?php

declare(strict_types=1);

use App\Models\PaydayCycle;
use App\Models\PaydayPlan;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function paydayColumn(string $table, string $column): ?object
{
    /** @var object|null $row */
    $row = DB::table('information_schema.columns')
        ->where('table_name', $table)
        ->where('column_name', $column)
        ->select('data_type', 'is_nullable', 'column_default')
        ->first();

    return $row;
}

it('creates both tables with the column types the design specifies', function () {
    expect(Schema::hasTable('payday_plans'))->toBeTrue()
        ->and(Schema::hasTable('payday_cycles'))->toBeTrue();

    expect(paydayColumn('payday_plans', 'payday_day')->data_type)->toBe('smallint')
        ->and(paydayColumn('payday_plans', 'declared_salary')->data_type)->toBe('numeric')
        ->and(paydayColumn('payday_plans', 'savings_percent')->data_type)->toBe('smallint')
        ->and(paydayColumn('payday_plans', 'is_active')->data_type)->toBe('boolean')
        ->and(paydayColumn('payday_plans', 'is_active')->column_default)->toContain('true')
        ->and(paydayColumn('payday_plans', 'created_at')->data_type)->toBe('timestamp with time zone');

    expect(paydayColumn('payday_cycles', 'period_month')->data_type)->toBe('date')
        ->and(paydayColumn('payday_cycles', 'payday_on')->data_type)->toBe('date')
        ->and(paydayColumn('payday_cycles', 'plan_snapshot')->data_type)->toBe('jsonb')
        ->and(paydayColumn('payday_cycles', 'status')->data_type)->toBe('text')
        ->and(paydayColumn('payday_cycles', 'status')->column_default)->toContain('pending')
        ->and(paydayColumn('payday_cycles', 'skip_reason')->is_nullable)->toBe('YES')
        ->and(paydayColumn('payday_cycles', 'send_attempts')->data_type)->toBe('smallint')
        ->and(paydayColumn('payday_cycles', 'sent_at')->data_type)->toBe('timestamp with time zone')
        ->and(paydayColumn('payday_cycles', 'sent_at')->is_nullable)->toBe('YES')
        ->and(paydayColumn('payday_cycles', 'answered_at')->is_nullable)->toBe('YES')
        ->and(paydayColumn('payday_cycles', 'reminded_at')->is_nullable)->toBe('YES')
        ->and(paydayColumn('payday_cycles', 'reminder_due_on')->data_type)->toBe('date');
});

it('names the unique constraints and the partial reminder index', function () {
    expect(DB::table('pg_constraint')->where('conname', 'unq_payday_plans_user_id')->exists())->toBeTrue()
        ->and(DB::table('pg_constraint')->where('conname', 'unq_payday_cycles_user_id_period_month')->exists())->toBeTrue();

    $index = DB::table('pg_indexes')->where('indexname', 'idx_payday_cycles_reminder_due_on')->value('indexdef');

    expect($index)->toContain('reminder_due_on')->and($index)->toContain("status = 'pending'");
});

it('allows one plan per user and rejects a second', function () {
    $user = User::factory()->create();
    PaydayPlan::factory()->create(['user_id' => $user->id]);

    expect(fn () => DB::transaction(fn () => PaydayPlan::factory()->create(['user_id' => $user->id])))
        ->toThrow(UniqueConstraintViolationException::class);

    // A different user is unaffected.
    PaydayPlan::factory()->create();
    expect(PaydayPlan::query()->count())->toBe(2);
});

it('allows one cycle per user and month but a new month is fine', function () {
    $user = User::factory()->create();
    PaydayCycle::factory()->create(['user_id' => $user->id, 'period_month' => '2026-10-01']);

    expect(fn () => DB::transaction(fn () => PaydayCycle::factory()->create(['user_id' => $user->id, 'period_month' => '2026-10-01'])))
        ->toThrow(UniqueConstraintViolationException::class);

    PaydayCycle::factory()->create(['user_id' => $user->id, 'period_month' => '2026-11-01']);
    PaydayCycle::factory()->create(['period_month' => '2026-10-01']);

    expect(PaydayCycle::query()->count())->toBe(3);
});

it('deletes plans and cycles when the user is deleted', function () {
    // Without events: the observer seeds a workspace whose tables do not cascade from users.
    $user = User::withoutEvents(fn () => User::factory()->create());
    PaydayPlan::factory()->create(['user_id' => $user->id]);
    PaydayCycle::factory()->create(['user_id' => $user->id]);

    $user->delete();

    expect(PaydayPlan::query()->count())->toBe(0)
        ->and(PaydayCycle::query()->count())->toBe(0);
});

it('round-trips the snapshot as jsonb and casts the status to its enum', function () {
    $snapshot = ['payday_day' => 3, 'declared_salary' => '3000.00', 'savings_percent' => 10, 'savings_amount' => '300.00'];
    $cycle = PaydayCycle::factory()->create(['plan_snapshot' => $snapshot]);

    $fresh = PaydayCycle::query()->findOrFail($cycle->id);

    expect($fresh->plan_snapshot)->toEqualCanonicalizing($snapshot)
        ->and($fresh->status)->toBe(App\Enums\PaydayCycleStatus::Pending)
        ->and($fresh->period_month->toDateString())->toBe($cycle->period_month->toDateString());
});

it('keeps the declared salary as an exact decimal string', function () {
    $plan = PaydayPlan::factory()->create(['declared_salary' => 3000.5]);

    expect(PaydayPlan::query()->findOrFail($plan->id)->declared_salary)->toBe('3000.50');
});
