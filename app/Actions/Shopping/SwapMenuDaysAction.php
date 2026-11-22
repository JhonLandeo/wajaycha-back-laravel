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
 * estado que no tiene sentido mover de fecha. Las sobras del mismo día viajan
 * con su comida de origen y conservan el orden; una sobra que cruza el par de
 * días, o que apunta a una comida fuera de ellos, rechaza el intercambio.
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
        }

        $ids = $entries->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $movingDates = [$a->toDateString(), $b->toDateString()];

        foreach ($this->mealPlanRepository->leftoversReferencing($userId, $ids) as $referencing) {
            if (! in_array($referencing->plan_date->toDateString(), $movingDates, true)) {
                throw ValidationException::withMessages([
                    'dates' => 'Una sobra de otro día apunta a una comida de estos días. Ajusta esas sobras primero.',
                ]);
            }
        }

        // Una sobra solo se puede mover con su comida de origen en el mismo
        // intercambio. Las del mismo día viajan juntas y mantienen el orden; las
        // que cruzan de día quedarían antes o después de su origen.
        $entriesById = $entries->keyBy('id');

        foreach ($entries as $entry) {
            if ($entry->kind !== MealEntryKind::Leftover) {
                continue;
            }

            $source = $entriesById->get((int) $entry->leftover_of_entry_id);

            if ($source === null || $source->plan_date->toDateString() !== $entry->plan_date->toDateString()) {
                throw ValidationException::withMessages([
                    'dates' => 'Solo puedes intercambiar días cuando sus sobras son del mismo día. Ajusta esas comidas primero.',
                ]);
            }
        }

        $this->mealPlanRepository->swapDays($userId, $a, $b);
    }
}
