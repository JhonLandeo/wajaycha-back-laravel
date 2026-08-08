<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\ChannelIdentity;
use App\Models\CoachingObservation;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function sweepReachableUser(): User
{
    $user = User::factory()->create();

    ChannelIdentity::factory()->create([
        'user_id' => $user->id,
        'channel' => 'telegram',
        'external_id' => (string) $user->id,
    ]);

    return $user;
}

function sweepUserOverBudget(User $user): Category
{
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'monthly_budget' => 300,
    ]);

    $detail = Detail::factory()->create(['user_id' => $user->id]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'category_id' => $category->id,
        'type_transaction' => 'expense',
        'amount' => 350,
        'date_operation' => now()->toDateTimeString(),
    ]);

    return $category;
}

/**
 * Task 4.8/design.md §12 rollout step 2: the first production run of the sweep
 * is `--dry-run`, so the owner reads the messages before anyone else does.
 * `--dry-run` MUST skip the claim — otherwise it burns the bands it exists to
 * preview. Asserted structurally: no `coaching_observations` row exists after
 * a dry run over a fixture that WOULD otherwise claim and send.
 */
it('dry-run composes but claims nothing — no coaching_observations row is inserted', function () {
    Http::fake();

    $user = sweepReachableUser();
    sweepUserOverBudget($user);

    Artisan::call('app:run-coaching-sweep', ['--dry-run' => true]);

    expect(CoachingObservation::count())->toBe(0);

    Http::assertNothingSent();
});

it('a normal run claims and sends for a user who crosses the threshold', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $user = sweepReachableUser();
    sweepUserOverBudget($user);

    Artisan::call('app:run-coaching-sweep');

    expect(CoachingObservation::count())->toBe(1);
    Http::assertSentCount(1);
});

/**
 * design.md D4: a WhatsApp-only user is never coached, but that exclusion must
 * be OBSERVABLE, never a silent drop (ADR-0007). The summary line names every
 * bucket: total, reachable, no-channel, sent, silent.
 */
it('summarises total, reachable, no-channel, sent and silent — a WhatsApp-only user is counted, never dropped', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $speaking = sweepReachableUser();
    sweepUserOverBudget($speaking);

    $silentUser = sweepReachableUser();
    Category::factory()->create(['user_id' => $silentUser->id, 'type' => 'expense', 'monthly_budget' => 1000]);

    $whatsAppOnly = User::factory()->create();
    ChannelIdentity::factory()->create([
        'user_id' => $whatsAppOnly->id,
        'channel' => 'whatsapp',
        'external_id' => '51999888777',
    ]);

    Artisan::call('app:run-coaching-sweep');
    $output = Artisan::output();

    expect($output)->toContain('3 usuarios')
        ->and($output)->toContain('2 alcanzables')
        ->and($output)->toContain('1 sin canal de coaching')
        ->and($output)->toContain('1 mensajes enviados')
        ->and($output)->toContain('1 silencios');
});
