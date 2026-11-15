<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\Enums\MealEntryStatus;
use App\Models\MealPlanEntry;
use App\Repositories\Contracts\MealPlanRepositoryContract;
use Illuminate\Support\Facades\DB;

/**
 * Cambia el estado de una comida y sincroniza la despensa en la misma
 * transacción (ADR-0020): un estado `cooked` sin su consumo, o un consumo sin
 * su estado, serían dos medias verdades distintas.
 */
final class UpdateMealEntryStatusAction
{
    public function __construct(
        private readonly MealPlanRepositoryContract $mealPlanRepository,
        private readonly SyncPantryConsumptionAction $syncPantryConsumption,
    ) {}

    public function execute(MealPlanEntry $entry, MealEntryStatus $status): MealPlanEntry
    {
        return DB::transaction(function () use ($entry, $status): MealPlanEntry {
            $updated = $this->mealPlanRepository->updateStatus($entry, $status);
            $this->syncPantryConsumption->execute($updated);

            return $updated;
        });
    }
}
