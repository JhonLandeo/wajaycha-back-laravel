<?php

declare(strict_types=1);

use App\Enums\BudgetPeriod;
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

// El parte se despliega apagado: `coaching.digest_enabled` tiene default false
// para que el deploy no sea, por si solo, la decision de empezar a escribirle a
// la gente. Cada test que espera un envio tiene que declarar que lo enciende —
// heredar el default y confiar en que sea true es como estos tests se volverian
// verdes el dia que alguien cambie esa perilla por otra razon.
beforeEach(function () {
    config(['coaching.digest_enabled' => true]);
});

/**
 * The morning digest end to end.
 *
 * The load-bearing test here is the one asserting the digest writes NO
 * `coaching_observations` row and can run twice with the same result. That is
 * the entire reason it is a separate command: the coach's ledger guarantees each
 * band is spoken once a month, and a status board that obeyed it would go quiet
 * on day two and stay quiet — reporting nothing for the rest of the month while
 * looking like it was working.
 */
function digestReachableUser(): User
{
    $user = User::factory()->create();

    ChannelIdentity::factory()->create([
        'user_id' => $user->id,
        'channel' => 'telegram',
        'external_id' => (string) $user->id,
    ]);

    return $user;
}

function digestSpend(User $user, Category $category, float $amount): void
{
    $detail = Detail::factory()->create(['user_id' => $user->id]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'category_id' => $category->id,
        'type_transaction' => 'expense',
        'amount' => $amount,
        'date_operation' => now()->toDateTimeString(),
    ]);
}

function digestUserOverBudget(User $user): Category
{
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'name' => 'Comida',
        'monthly_budget' => 300,
    ]);

    digestSpend($user, $category, 350);

    return $category;
}

it('manda el parte a un usuario con un presupuesto pasado', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $user = digestReachableUser();
    digestUserOverBudget($user);

    Artisan::call('app:send-budget-digest');

    Http::assertSentCount(1);
    Http::assertSent(function ($request) {
        return str_contains((string) ($request['text'] ?? ''), 'Ya pasaste:')
            && str_contains((string) ($request['text'] ?? ''), 'Comida: S/ 350.00 de S/ 300.00, S/ 50.00 encima.');
    });
});

it('no reclama nada en el ledger, y por eso puede repetirse todos los dias', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $user = digestReachableUser();
    digestUserOverBudget($user);

    Artisan::call('app:send-budget-digest');
    Artisan::call('app:send-budget-digest');

    // Dos mañanas, dos partes. El coach diria lo suyo una sola vez en el mes; un
    // tablero de estado que hiciera lo mismo enmudeceria el dia 2.
    expect(CoachingObservation::count())->toBe(0);
    Http::assertSentCount(2);
});

it('se calla cuando no hay nada pasado ni en camino', function () {
    Http::fake();

    $user = digestReachableUser();
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'monthly_budget' => 1000,
    ]);
    digestSpend($user, $category, 10);

    Artisan::call('app:send-budget-digest');

    // No existe un mensaje de "todo bien": si llegara igual todas las mañanas,
    // dejaria de leerse antes de la primera que importe.
    Http::assertNothingSent();
});

it('dry-run compone e imprime sin enviar', function () {
    Http::fake();

    $user = digestReachableUser();
    digestUserOverBudget($user);

    Artisan::call('app:send-budget-digest', ['--dry-run' => true]);

    expect(Artisan::output())->toContain('Ya pasaste:');
    Http::assertNothingSent();
});

it('respeta el kill switch propio del parte sin tocar al coach', function () {
    Http::fake();
    config(['coaching.digest_enabled' => false]);

    $user = digestReachableUser();
    digestUserOverBudget($user);

    Artisan::call('app:send-budget-digest');

    Http::assertNothingSent();
});

it('se calla tambien cuando se apaga el subsistema entero', function () {
    Http::fake();
    config(['coaching.enabled' => false]);

    $user = digestReachableUser();
    digestUserOverBudget($user);

    Artisan::call('app:send-budget-digest');

    // Un kill switch que deja una voz hablando no es un kill switch.
    Http::assertNothingSent();
});

it('separa un sobre anual pasado de un presupuesto mensual pasado', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $user = digestReachableUser();
    digestUserOverBudget($user);

    $health = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'name' => 'Salud',
        'monthly_budget' => 1200,
        'budget_period' => BudgetPeriod::YEARLY->value,
    ]);
    digestSpend($user, $health, 1350);

    Artisan::call('app:send-budget-digest');

    Http::assertSent(function ($request) {
        $text = (string) ($request['text'] ?? '');

        return str_contains($text, 'Sobres anuales:')
            && str_contains($text, 'Salud: S/ 1,350.00 de S/ 1,200.00 al año, S/ 150.00 encima.');
    });
});

it('cuenta a un usuario sin canal en vez de dejarlo caer en silencio', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $speaking = digestReachableUser();
    digestUserOverBudget($speaking);

    User::factory()->create();

    Artisan::call('app:send-budget-digest');
    $output = Artisan::output();

    expect($output)->toContain('2 usuarios')
        ->and($output)->toContain('1 alcanzables')
        ->and($output)->toContain('1 sin canal')
        ->and($output)->toContain('1 partes enviados');
});
