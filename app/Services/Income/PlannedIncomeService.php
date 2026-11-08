<?php

declare(strict_types=1);

namespace App\Services\Income;

use App\Repositories\Contracts\IncomeSourceRepositoryContract;
use Carbon\CarbonImmutable;

/**
 * Suma el ingreso planificado de una ventana.
 *
 * Una version rige meses completos. Para un mes se cuenta si su rango toca ese
 * mes; para un año se cuenta una vez por cada mes de la ventana, porque un
 * sueldo de 3,416 en doce meses son 40,992 de ingreso planificado, no 3,416.
 *
 * Devuelve null cuando no hay año: "todo el historico" no tiene un denominador
 * honesto para comparar metas.
 */
final class PlannedIncomeService
{
    public function __construct(
        private readonly IncomeSourceRepositoryContract $sources,
    ) {}

    public function forWindow(int $userId, ?int $month, ?int $year, int $budgetMonths): ?float
    {
        if ($year === null) {
            return null;
        }

        $versions = $this->sources->versionsForUser($userId);

        if ($versions->isEmpty()) {
            return null;
        }

        $months = $month !== null
            ? [$month]
            : range(1, max(1, min(12, $budgetMonths)));

        $total = 0.0;

        foreach ($versions as $version) {
            $from = CarbonImmutable::parse((string) $version->effective_from)->startOfMonth();
            $to = $version->effective_to !== null
                ? CarbonImmutable::parse((string) $version->effective_to)->endOfMonth()
                : null;

            foreach ($months as $m) {
                $monthStart = CarbonImmutable::create($year, $m, 1)->startOfMonth();
                $monthEnd = $monthStart->endOfMonth();

                if ($from->lessThanOrEqualTo($monthEnd) && ($to === null || $to->greaterThanOrEqualTo($monthStart))) {
                    $total += (float) $version->amount;
                }
            }
        }

        return round($total, 2);
    }
}
