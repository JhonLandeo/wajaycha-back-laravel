<?php

declare(strict_types=1);

use App\DTOs\Coaching\ClaimAttempt;
use App\Models\Category;
use App\Models\CoachingObservation;
use App\Models\User;
use App\Services\Coaching\SpokenObservationLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->ledger = app(SpokenObservationLedger::class);
    $this->user = User::factory()->create();
    $this->category = Category::factory()->create(['user_id' => $this->user->id, 'type' => 'expense']);
    $this->periodMonth = CarbonImmutable::now('America/Lima')->startOfMonth();
});

/**
 * Builds a ClaimAttempt for SpokenObservationLedger::claim(), overridable per
 * scenario so every test only states what actually varies (task 4.0, decision 1).
 */
function claimAttempt(Tests\TestCase $test, array $overrides = []): ClaimAttempt
{
    $args = array_merge([
        'userId' => $test->user->id,
        'periodMonth' => $test->periodMonth,
        'subjectKey' => "category:{$test->category->id}",
        'band' => 'over_budget',
        'categoryId' => $test->category->id,
        'isLumpy' => false,
        'budgetAmount' => 400.0,
        'spentAmount' => 450.0,
        'projectedAmount' => null,
        'dayOfMonth' => 12,
        'entryPoint' => 'sweep',
    ], $overrides);

    return new ClaimAttempt(...$args);
}

it('claims a band the first time it is spoken', function () {
    expect($this->ledger->claim(claimAttempt($this)))->toBeTrue()
        ->and(CoachingObservation::count())->toBe(1);
});

it('refuses a second claim of the same user, period, subject and band', function () {
    $this->ledger->claim(claimAttempt($this));

    expect($this->ledger->claim(claimAttempt($this)))->toBeFalse()
        ->and(CoachingObservation::count())->toBe(1);
});

it('does not poison the enclosing transaction when a duplicate claim is caught (25P02 regression)', function () {
    $this->ledger->claim(claimAttempt($this));

    DB::transaction(function () {
        // Savepoint propio dentro de claim(): esto prueba que atrapar la
        // violacion de constraint no deja abortada la transaccion que envuelve
        // esta llamada, cosa que en PostgreSQL (SQLSTATE 25P02) pasaria sin el
        // savepoint que claim() abre internamente.
        expect($this->ledger->claim(claimAttempt($this)))->toBeFalse();

        // La prueba real: una escritura DISTINTA, en la MISMA transaccion
        // envolvente, inmediatamente despues de la violacion atrapada.
        expect($this->ledger->claim(claimAttempt($this, ['band' => 'projected_over', 'projectedAmount' => 460.0])))
            ->toBeTrue();
    });

    expect(CoachingObservation::count())->toBe(2);
});

it('lets exactly one of two concurrent claims of the same band win, and the loser returns cleanly', function () {
    // Simula de forma deterministica al competidor que aterriza entre nuestro
    // intento y el commit: se inserta por query builder para no re-disparar
    // este mismo evento (patron identico a ChannelUpdateDeduplicatorTest).
    CoachingObservation::creating(function () {
        DB::table('coaching_observations')->insert([
            'user_id' => $this->user->id,
            'period_month' => $this->periodMonth->toDateString(),
            'subject_key' => "category:{$this->category->id}",
            'category_id' => $this->category->id,
            'band' => 'over_budget',
            'is_lumpy' => false,
            'budget_amount' => 400.0,
            'spent_amount' => 450.0,
            'projected_amount' => null,
            'day_of_month' => 12,
            'entry_point' => 'sweep',
            'spoken_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        $result = $this->ledger->claim(claimAttempt($this));
    } finally {
        // En finally a proposito: si claim() llegara a propagar una excepcion no
        // esperada, el listener quedaria vivo e insertaria una fila fantasma en
        // cualquier test posterior que cree un CoachingObservation.
        CoachingObservation::flushEventListeners();
    }

    // Pierde limpio: false, no excepcion. En PostgreSQL una violacion de
    // constraint sin savepoint aborta la transaccion entera y esta consulta
    // reventaria con 25P02. El competidor simulado desaparece con el rollback
    // del savepoint porque, a diferencia de la concurrencia real, comparte
    // transaccion con el intento perdedor — por eso el conteo no se afirma
    // aqui, solo que la transaccion sigue viva y que una escritura nueva
    // todavia puede reclamar.
    expect($result)->toBeFalse()
        ->and(fn () => CoachingObservation::count())->not->toThrow(Exception::class)
        ->and($this->ledger->claim(claimAttempt($this)))
        ->toBeTrue();
});

it('lets a worse band claim after a better one was already spoken', function () {
    expect($this->ledger->claim(claimAttempt($this, ['band' => 'projected_over', 'projectedAmount' => 460.0])))->toBeTrue();

    // over_budget y projected_over son tuplas distintas en el indice unico:
    // claim() no conoce la escalera, solo arbitra por el (user, mes, sujeto,
    // banda) exacto. Ambas escrituras tienen que sobrevivir.
    expect($this->ledger->claim(claimAttempt($this, ['band' => 'over_budget'])))->toBeTrue()
        ->and(CoachingObservation::count())->toBe(2);
});

it('reports the highest severity band regardless of insertion order', function () {
    $this->ledger->claim(claimAttempt($this, ['band' => 'projected_over', 'projectedAmount' => 460.0]));
    $this->ledger->claim(claimAttempt($this, ['band' => 'over_budget']));

    expect($this->ledger->highestBandFor($this->user->id, $this->periodMonth, "category:{$this->category->id}"))
        ->toBe('over_budget');
});

it('returns null from highestBandFor when nothing has been spoken yet', function () {
    expect($this->ledger->highestBandFor($this->user->id, $this->periodMonth, "category:{$this->category->id}"))
        ->toBeNull();
});

it('claims blindness once per month', function () {
    expect($this->ledger->claim(claimAttempt($this, [
        'subjectKey' => 'blindness',
        'band' => 'blind',
        'categoryId' => null,
        'budgetAmount' => 0.0,
    ])))->toBeTrue();

    expect($this->ledger->claim(claimAttempt($this, [
        'subjectKey' => 'blindness',
        'band' => 'blind',
        'categoryId' => null,
        'budgetAmount' => 0.0,
    ])))->toBeFalse();

    expect(CoachingObservation::count())->toBe(1);
});

it('starts a new period_month with an empty ladder', function () {
    $this->ledger->claim(claimAttempt($this, ['band' => 'over_budget']));

    $nextMonth = $this->periodMonth->addMonthNoOverflow();

    expect($this->ledger->highestBandFor($this->user->id, $nextMonth, "category:{$this->category->id}"))
        ->toBeNull();

    // Y el mes nuevo puede volver a reclamar exactamente la misma banda: el
    // mes cerrado nunca se vuelve a coachear (design.md D3).
    expect($this->ledger->claim(claimAttempt($this, ['periodMonth' => $nextMonth, 'band' => 'over_budget'])))
        ->toBeTrue();
});

it('marca como entregadas solo las observaciones que se le nombran', function () {
    $this->ledger->claim(claimAttempt($this));
    $entregada = CoachingObservation::sole();

    $this->ledger->claim(claimAttempt($this, ['band' => 'projected_over', 'projectedAmount' => 460.0]));
    $sinEntregar = CoachingObservation::where('id', '!=', $entregada->id)->sole();

    $this->ledger->confirmDelivered([$entregada->id]);

    // spoken_at sin delivered_at es la señal que un operador puede consultar:
    // "se reclamo pero el envio nunca volvio". No puede quedar marcada de mas.
    expect($entregada->fresh()->delivered_at)->not->toBeNull()
        ->and($sinEntregar->fresh()->delivered_at)->toBeNull();
});

it('no toca nada cuando la lista viene vacia', function () {
    $this->ledger->claim(claimAttempt($this));

    $this->ledger->confirmDelivered([]);

    expect(CoachingObservation::sole()->delivered_at)->toBeNull();
});

it('no marca entregada una observacion que nunca se reclamo', function () {
    $this->ledger->claim(claimAttempt($this));
    $existente = CoachingObservation::sole();

    // Un id que no existe no puede crear ni resucitar nada.
    $this->ledger->confirmDelivered([$existente->id + 999]);

    expect($existente->fresh()->delivered_at)->toBeNull()
        ->and(CoachingObservation::count())->toBe(1);
});

it('trata cualquier dia del mismo mes como el mismo periodo', function () {
    $primero = CarbonImmutable::create(2026, 8, 1);
    $veintidos = CarbonImmutable::create(2026, 8, 22);

    expect($this->ledger->claim(claimAttempt($this, ['periodMonth' => $primero])))->toBeTrue();

    // Mismo sujeto, misma banda, otro dia del mismo mes. Si el ledger guardara la
    // fecha tal cual, el indice unico no ligaria y el coach hablaria de nuevo.
    expect($this->ledger->claim(claimAttempt($this, ['periodMonth' => $veintidos])))->toBeFalse()
        ->and(CoachingObservation::count())->toBe(1);

    expect($this->ledger->highestBandFor($this->user->id, $veintidos, 'category:1'))
        ->toBe($this->ledger->highestBandFor($this->user->id, $primero, 'category:1'));
});
