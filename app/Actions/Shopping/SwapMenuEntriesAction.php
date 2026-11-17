<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\DTOs\Shopping\PlanningWeek;
use App\Enums\MealEntryKind;
use App\Enums\MealEntryStatus;
use App\Enums\MealType;
use App\Repositories\Contracts\MealPlanRepositoryContract;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Intercambia o mueve una comida entre dos días (ADR-0045).
 *
 * Solo aplica a la misma comida (desayuno con desayuno, etc.), a entradas
 * pendientes y sin sobras. El estado y el consumo de despensa no se mueven.
 */
final class SwapMenuEntriesAction
{
    public function __construct(
        private readonly MealPlanRepositoryContract $mealPlanRepository,
    ) {}

    public function execute(int $userId, string $weekStart, string $dateA, string $dateB, MealType $meal): void
    {
        $week = PlanningWeek::forWeekStarting(
            CarbonImmutable::parse($weekStart, 'America/Lima'),
            CarbonImmutable::now(),
        );
        $settings = $this->mealPlanRepository->settingsFor($userId);

        if (! in_array($meal->value, $settings->meal_types, true)) {
            throw ValidationException::withMessages([
                'meal_type' => 'Esa comida no está activa en tu menú.',
            ]);
        }

        $a = CarbonImmutable::parse($dateA, 'America/Lima');
        $b = CarbonImmutable::parse($dateB, 'America/Lima');

        if ($a->isSameDay($b)) {
            return;
        }

        foreach ([$a, $b] as $date) {
            if ($date->toDateString() < $week->startsOn->toDateString()
                || $date->toDateString() > $week->endsOn->toDateString()
                || ! in_array($date->dayOfWeekIso, $settings->plan_weekdays, true)) {
                throw ValidationException::withMessages([
                    'dates' => 'Elige dos días activos de la misma semana.',
                ]);
            }
        }

        $first = $this->mealPlanRepository->findSlot($userId, $a->toDateString(), $meal);
        $second = $this->mealPlanRepository->findSlot($userId, $b->toDateString(), $meal);

        if ($first === null && $second === null) {
            throw ValidationException::withMessages([
                'dates' => 'No hay ninguna comida para mover en esos días.',
            ]);
        }

        $entries = array_values(array_filter([$first, $second]));

        foreach ($entries as $entry) {
            if ($entry->status !== MealEntryStatus::Planned) {
                throw ValidationException::withMessages([
                    'dates' => 'No puedes mover comidas cocinadas, saltadas o con estado marcado.',
                ]);
            }

            if ($entry->kind === MealEntryKind::Leftover) {
                throw ValidationException::withMessages([
                    'dates' => 'No puedes mover sobras. Ajusta esas comidas primero.',
                ]);
            }
        }

        $ids = array_map(static fn ($entry): int => (int) $entry->id, $entries);

        if ($this->mealPlanRepository->leftoversReferencing($userId, $ids)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'dates' => 'Una sobra de otro día apunta a una comida de estos días. Ajusta esas sobras primero.',
            ]);
        }

        $this->mealPlanRepository->swapEntries($userId, $a, $b, $meal);
    }
}
