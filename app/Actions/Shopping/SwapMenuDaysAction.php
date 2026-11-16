<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\DTOs\Shopping\PlanningWeek;
use App\Enums\MealEntryKind;
use App\Enums\MealEntryStatus;
use App\Repositories\Contracts\MealPlanRepositoryContract;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Intercambia dos días completos del menú guardado (ADR-0038).
 *
 * Solo con entradas pendientes: un día cocinado tiene consumo de despensa y un
 * estado que no tiene sentido mover de fecha. Las sobras tampoco se mueven
 * porque apuntan a una comida de otro día por id; si una sobra cruza el par, el
 * intercambio se rechaza.
 */
final class SwapMenuDaysAction
{
    public function __construct(
        private readonly MealPlanRepositoryContract $mealPlanRepository,
    ) {}

    public function execute(int $userId, string $weekStart, string $dateA, string $dateB): void
    {
        $week = PlanningWeek::forWeekStarting(
            CarbonImmutable::parse($weekStart, 'America/Lima'),
            CarbonImmutable::now(),
        );

        $a = CarbonImmutable::parse($dateA, 'America/Lima');
        $b = CarbonImmutable::parse($dateB, 'America/Lima');

        if ($a->isSameDay($b)) {
            return;
        }

        $settings = $this->mealPlanRepository->settingsFor($userId);

        foreach ([$a, $b] as $date) {
            if ($date->toDateString() < $week->startsOn->toDateString()
                || $date->toDateString() > $week->endsOn->toDateString()
                || ! in_array($date->dayOfWeekIso, $settings->plan_weekdays, true)) {
                throw ValidationException::withMessages([
                    'dates' => 'Elige dos días activos de la misma semana.',
                ]);
            }
        }

        $entries = $this->mealPlanRepository
            ->entriesForWeek($userId, $week->startsOn, $week->endsOn)
            ->filter(static fn ($entry): bool => in_array($entry->plan_date->toDateString(), [$a->toDateString(), $b->toDateString()], true));

        foreach ($entries as $entry) {
            if ($entry->status !== MealEntryStatus::Planned) {
                throw ValidationException::withMessages([
                    'dates' => 'No puedes intercambiar días con comidas cocinadas, saltadas o con estado marcado.',
                ]);
            }

            if ($entry->kind === MealEntryKind::Leftover) {
                throw ValidationException::withMessages([
                    'dates' => 'No puedes intercambiar días con sobras. Ajusta esas comidas primero.',
                ]);
            }
        }

        $ids = $entries->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        if ($this->mealPlanRepository->leftoversReferencing($userId, $ids)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'dates' => 'Una sobra de otro día apunta a una comida de estos días. Ajusta esas sobras primero.',
            ]);
        }

        $this->mealPlanRepository->swapDays($userId, $a, $b);
    }
}
