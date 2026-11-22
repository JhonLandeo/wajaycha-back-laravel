<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\Enums\MealEntryKind;
use App\Enums\MealEntryStatus;
use App\Models\MealPlanEntry;
use App\Repositories\Contracts\MealPlanRepositoryContract;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Programa otra vez una comida que no se cocinó, sin falsear su estado.
 *
 * La entrada original queda como historia: si decía “no cociné” o “comí
 * fuera”, sigue diciéndolo. La comida nueva se crea en el primer hueco
 * compatible de la misma comida (desayuno/almuerzo/cena), en el día activo más
 * cercano, y apunta a la original con `rescheduled_from_entry_id`. “Pendientes”
 * es una vista derivada de ese hecho, no un estado nuevo (ADR-0044).
 */
final class ReprogramMenuEntryAction
{
    private const SEARCH_DAYS = 14;

    public function __construct(
        private readonly MealPlanRepositoryContract $mealPlanRepository,
    ) {}

    public function execute(MealPlanEntry $entry): MealPlanEntry
    {
        if ($entry->kind !== MealEntryKind::Recipe || $entry->recipe_id === null) {
            throw ValidationException::withMessages([
                'entry' => 'Solo se puede reprogramar una receta planificada.',
            ]);
        }

        if ($entry->status === MealEntryStatus::Cooked) {
            throw ValidationException::withMessages([
                'entry' => 'Esa comida ya se cocinó.',
            ]);
        }

        if ($entry->rescheduledTo()->exists()) {
            throw ValidationException::withMessages([
                'entry' => 'Esa comida ya se reprogramó.',
            ]);
        }

        $settings = $this->mealPlanRepository->settingsFor((int) $entry->user_id);
        $target = $this->nearestSlot($entry, $settings->plan_weekdays, $settings->meal_types);

        if ($target === null) {
            throw ValidationException::withMessages([
                'entry' => 'No hay un hueco libre para reprogramarla en los próximos días.',
            ]);
        }

        $servings = max(1, (int) $settings->household_size);
        $cook = max($servings, min((int) $entry->cook_servings, $servings * 2));

        return $this->mealPlanRepository->upsertEntry((int) $entry->user_id, [
            'plan_date' => $target->toDateString(),
            'meal_type' => $entry->meal_type->value,
            'kind' => MealEntryKind::Recipe->value,
            'status' => MealEntryStatus::Planned->value,
            'recipe_id' => $entry->recipe_id,
            'drink_recipe_id' => $entry->drink_recipe_id,
            'servings' => $servings,
            'cook_servings' => $cook,
            'leftover_of_entry_id' => null,
            'rescheduled_from_entry_id' => $entry->id,
            'notes' => $entry->notes,
        ]);
    }

    /**
     * @param  array<int, int>  $planWeekdays
     * @param  array<int, string>  $mealTypes
     */
    private function nearestSlot(MealPlanEntry $entry, array $planWeekdays, array $mealTypes): ?CarbonImmutable
    {
        $today = CarbonImmutable::now('America/Lima')->startOfDay();
        $sourceDate = CarbonImmutable::parse($entry->plan_date->toDateString(), 'America/Lima');
        $start = $sourceDate->addDay()->greaterThan($today) ? $sourceDate->addDay() : $today;
        $limit = $start->addDays(self::SEARCH_DAYS);
        $meal = $entry->meal_type->value;

        if (! in_array($meal, $mealTypes, true)) {
            return null;
        }

        for ($date = $start; $date->lessThanOrEqualTo($limit); $date = $date->addDay()) {
            if (! in_array($date->isoWeekday(), $planWeekdays, true)) {
                continue;
            }

            if ($this->mealPlanRepository->findSlot(
                (int) $entry->user_id,
                $date->toDateString(),
                $entry->meal_type,
            ) === null) {
                return $date;
            }
        }

        return null;
    }
}
