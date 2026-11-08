<?php

declare(strict_types=1);

namespace App\DTOs\Pareto;

/**
 * What moved in the selected window, across every category.
 *
 * Repeated on every band in the response because the SPA reads it from whichever
 * card it happens to have. That redundancy is inherited from the shape the
 * PostgreSQL function returned and is kept so the client does not change.
 */
final class ParetoWindowTotals
{
    public function __construct(
        public readonly float $income,
        public readonly float $expense,
        /**
         * Ingreso planificado de la ventana. NULL cuando no hay fuentes o no se
         * eligio un año: no hay denominador honesto para comparar metas.
         */
        public readonly ?float $plannedIncome = null,
    ) {}
}
