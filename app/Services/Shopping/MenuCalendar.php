<?php

declare(strict_types=1);

namespace App\Services\Shopping;

use App\DTOs\Shopping\PlanningWeek;
use Carbon\CarbonImmutable;

/**
 * Traduce la configuración de días ISO del usuario a las fechas concretas de
 * una semana. Compartido por la sugerencia y el chat para que ambos hablen del
 * mismo conjunto de días activos.
 */
final class MenuCalendar
{
    /**
     * @param  array<int, int>  $planWeekdays
     * @return array<int, string>
     */
    public function activeDays(PlanningWeek $week, array $planWeekdays): array
    {
        $days = [];

        for ($offset = 0; $offset < 7; $offset++) {
            $date = $week->startsOn->addDays($offset);
            if (in_array($date->isoWeekday(), $planWeekdays, true)) {
                $days[] = $date->toDateString();
            }
        }

        return $days;
    }

    /**
     * Días activos que todavía se pueden planificar: excluye los que ya
     * pasaron en la zona de Lima. La vista puede mostrar la historia, pero una
     * sugerencia o un ajuste por chat no deben tocar días cerrados.
     *
     * @param  array<int, int>  $planWeekdays
     * @return array<int, string>
     */
    public function upcomingActiveDays(PlanningWeek $week, array $planWeekdays): array
    {
        $today = $week->asOf->setTimezone('America/Lima')->toDateString();

        return array_values(array_filter(
            $this->activeDays($week, $planWeekdays),
            static fn (string $date): bool => $date >= $today,
        ));
    }

    /**
     * @param  array<int, string>  $days
     */
    public function isActive(string $date, array $days): bool
    {
        return in_array($date, $days, true);
    }

    public function parse(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'America/Lima');
    }
}
