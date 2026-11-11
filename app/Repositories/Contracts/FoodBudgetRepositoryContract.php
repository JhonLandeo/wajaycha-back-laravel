<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DTOs\Shopping\FoodBudgetSnapshot;
use App\Models\FoodBudget;
use Carbon\CarbonImmutable;

interface FoodBudgetRepositoryContract
{
    public function findForUser(int $userId): ?FoodBudget;

    /**
     * Guarda el monto explícito, el día de compra y el alcance de categorías.
     * El alcance se reemplaza completo: lo que no venga en `$categoryIds` deja
     * de contar.
     *
     * @param  array<int, int>  $categoryIds
     */
    public function save(int $userId, array $categoryIds, float $monthlyAmount, int $shoppingDay): FoodBudget;

    /**
     * Lectura completa para la pantalla y para el plan semanal. Devuelve null
     * cuando no hay presupuesto o no tiene categorías de alcance.
     */
    public function snapshotFor(int $userId, CarbonImmutable $asOf): ?FoodBudgetSnapshot;

    /**
     * Gasto real del alcance de comida entre dos fechas, o null si no hay
     * presupuesto con categorías. Se usa para comparar el menú con la semana.
     */
    public function spentBetween(int $userId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): ?float;
}
