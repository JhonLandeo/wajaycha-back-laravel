<?php

declare(strict_types=1);

/**
 * `retry_after` is not a tuning knob, it is an invariant.
 *
 * It tells the queue how long a reserved job may stay reserved before the
 * worker holding it is assumed dead and the job is handed to someone else. Set
 * below a job's own `$timeout`, it guarantees the opposite of what it looks
 * like it does: a job that is still perfectly alive gets picked up a second
 * time while the first worker is mid-flight.
 *
 * For a capture job that means a Transaction registered by worker A and, in the
 * same minute, an "ocurrió un error inesperado" sent to the user by worker B —
 * which is how someone ends up sending the same receipt twice and having it
 * counted twice in their Pareto report.
 *
 * The bounded review found `retry_after` at 90 against jobs declaring 180, 500
 * and 600. This test derives the maximum from the job classes rather than
 * restating a number, so raising a `$timeout` cannot silently outgrow the
 * connection again — the same technique `OutboundHttpBudgetTest` uses for the
 * outbound budget.
 */

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Every queued job that declares a timeout, as class name => seconds.
 *
 * @return array<string, int>
 */
function declaredJobTimeouts(): array
{
    $timeouts = [];

    foreach (glob(app_path('Jobs/*.php')) ?: [] as $path) {
        $class = 'App\\Jobs\\'.basename($path, '.php');

        if (! class_exists($class) || ! is_a($class, ShouldQueue::class, true)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->hasProperty('timeout')) {
            continue;
        }

        $default = $reflection->getProperty('timeout')->getDefaultValue();

        if (is_int($default)) {
            $timeouts[$class] = $default;
        }
    }

    return $timeouts;
}

it('encuentra los jobs con timeout declarado, para que el caso de abajo no sea vacio', function () {
    // Una afirmación sobre "todos los jobs" que no encuentra ninguno pasa sola.
    $timeouts = declaredJobTimeouts();

    expect($timeouts)->not->toBeEmpty()
        ->and($timeouts)->toHaveKey(App\Jobs\ProcessTelegramCapture::class);
});

it('deja a retry_after por encima del timeout de todos los jobs de la cola', function () {
    $retryAfter = (int) config('queue.connections.redis.retry_after');
    $timeouts = declaredJobTimeouts();

    foreach ($timeouts as $class => $timeout) {
        expect($retryAfter)->toBeGreaterThan(
            $timeout,
            "retry_after ({$retryAfter}s) no supera el timeout de {$class} ({$timeout}s): "
            .'un segundo worker va a re-reservar el job mientras el primero sigue corriendo.'
        );
    }
});

it('deja margen suficiente para que el job termine de morir antes de re-encolarse', function () {
    $retryAfter = (int) config('queue.connections.redis.retry_after');
    $masLargo = max(declaredJobTimeouts());

    // Un margen de cero significa que el job se re-encola en el mismo instante
    // en que expira: cualquier deriva de reloj entre el worker y Redis reabre
    // exactamente la ventana que este archivo existe para cerrar.
    expect($retryAfter - $masLargo)->toBeGreaterThanOrEqual(
        60,
        "retry_after ({$retryAfter}s) le gana al job mas largo ({$masLargo}s) por menos de 60 segundos."
    );
});
