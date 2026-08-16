<?php

declare(strict_types=1);

/**
 * The two windows a month-over-month comparison subtracts.
 *
 * Pinned here rather than inside the comparator because getting them wrong is the
 * one failure that produces true figures and a false conclusion: comparing 16 days
 * of this month against 31 of the last says "gastaste menos" every day of every
 * month, and nothing downstream can tell that from a real drop.
 */

use App\DTOs\Coaching\MonthCursor;
use Carbon\CarbonImmutable;

function windowCursor(string $date): MonthCursor
{
    return MonthCursor::forInstant(CarbonImmutable::parse($date, 'America/Lima'));
}

it('cierra la ventana del mes en el arranque de mañana', function () {
    $cursor = windowCursor('2026-06-16 22:30:00');

    // Media abierta: incluye todo el dia 16 y nada del 17.
    expect($cursor->startsAt->toDateString())->toBe('2026-06-01')
        ->and($cursor->monthToDateEndsAt()->toDateString())->toBe('2026-06-17');
});

it('compara contra el mismo tramo del mes anterior, no contra el mes entero', function () {
    $cursor = windowCursor('2026-06-16');

    expect($cursor->previousMonthStartsAt()->toDateString())->toBe('2026-05-01')
        ->and($cursor->previousMonthToDateEndsAt()->toDateString())->toBe('2026-05-17')
        ->and($cursor->previousDaysCompared())->toBe(16);
});

/**
 * On 30 March there is no 30th of February to stop at. The window clamps, the two
 * spans stop being equal, and `previousDaysCompared()` is what lets the message
 * say so instead of reporting a difference that is partly just two extra days.
 */
it('recorta la ventana cuando el mes anterior fue mas corto', function () {
    $cursor = windowCursor('2026-03-30');

    expect($cursor->previousMonthStartsAt()->toDateString())->toBe('2026-02-01')
        ->and($cursor->previousDaysCompared())->toBe(28)
        ->and($cursor->previousMonthToDateEndsAt()->toDateString())->toBe('2026-03-01')
        ->and($cursor->day)->toBe(30);
});

it('no recorta cuando el mes anterior alcanza', function () {
    $cursor = windowCursor('2026-03-15');

    expect($cursor->previousDaysCompared())->toBe(15)
        ->and($cursor->previousDaysCompared())->toBe($cursor->day);
});

it('cruza el año hacia atras en enero', function () {
    $cursor = windowCursor('2026-01-10');

    expect($cursor->previousMonthStartsAt()->toDateString())->toBe('2025-12-01')
        ->and($cursor->previousMonthToDateEndsAt()->toDateString())->toBe('2025-12-11');
});

it('el dia uno compara contra un solo dia', function () {
    $cursor = windowCursor('2026-06-01');

    expect($cursor->previousDaysCompared())->toBe(1)
        ->and($cursor->monthToDateEndsAt()->toDateString())->toBe('2026-06-02')
        ->and($cursor->previousMonthToDateEndsAt()->toDateString())->toBe('2026-05-02');
});

/**
 * Febrero de un año bisiesto: 29 dias reales, no 28. `daysInMonth` lo resuelve,
 * y este caso existe para que nadie lo reemplace por una constante.
 */
it('cuenta los 29 dias de un febrero bisiesto', function () {
    $cursor = windowCursor('2028-03-31');

    expect($cursor->previousDaysCompared())->toBe(29);
});
