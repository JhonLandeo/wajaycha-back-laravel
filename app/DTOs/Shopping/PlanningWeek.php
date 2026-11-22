<?php

declare(strict_types=1);

namespace App\DTOs\Shopping;

use Carbon\CarbonImmutable;

/**
 * The Monday-to-Sunday window the weekly plan is computed over, in
 * `America/Lima` (design.md "Inputs"). A value object with a named
 * constructor, the `ParetoWindow::forFilter()` shape — the Action supplies
 * the clock via `$asOf`, never `now()` read from inside the DTO.
 */
final class PlanningWeek
{
    private function __construct(
        public readonly CarbonImmutable $asOf,
        public readonly CarbonImmutable $startsOn,
        public readonly CarbonImmutable $endsOn,
    ) {}

    public static function forDate(CarbonImmutable $asOf): self
    {
        $local = $asOf->setTimezone('America/Lima');

        return new self(
            asOf: $asOf,
            startsOn: $local->startOfWeek(CarbonImmutable::MONDAY),
            endsOn: $local->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay(),
        );
    }

    /**
     * Una semana pedida explícitamente, con el reloj separado: `$asOf` sigue
     * siendo la fecha de referencia para precios y presupuesto, mientras
     * `$startsOn` puede ser una semana futura.
     */
    public static function forWeekStarting(CarbonImmutable $startsOn, CarbonImmutable $asOf): self
    {
        $start = $startsOn->setTimezone('America/Lima')->startOfDay();

        return new self(
            asOf: $asOf,
            startsOn: $start->startOfWeek(CarbonImmutable::MONDAY),
            endsOn: $start->startOfWeek(CarbonImmutable::MONDAY)->addDays(6),
        );
    }

    /**
     * Primera fecha cuyas comidas planificadas todavía importan para comprar.
     *
     * Una semana pasada es historia y conserva todos sus días; la semana en
     * curso descarta los días que ya pasaron; una semana futura empieza en su
     * lunes. Así la compra no pide ingredientes de comidas que quedaron atrás.
     */
    public function shoppingFrom(): ?CarbonImmutable
    {
        $today = $this->asOf->setTimezone('America/Lima')->startOfDay();

        if ($this->endsOn->lessThan($today)) {
            return null;
        }

        return $this->startsOn->greaterThan($today) ? $this->startsOn : $today;
    }
}
