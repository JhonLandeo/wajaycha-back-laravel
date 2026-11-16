<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\DTOs\Shopping\PlanningWeek;
use App\Models\MealPlanEntry;
use App\Repositories\Contracts\MealPlanRepositoryContract;
use App\Services\Shopping\MealPlanPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Un turno de chat que ajusta la propuesta del preview (ADR-0032).
 *
 * El preview no está en la base: para que el chat y el validador existentes
 * trabajen con el mismo estado, la propuesta se escribe dentro de una
 * transacción, se le aplican las operaciones y se lee el resultado. El rollback
 * deja la base exactamente como estaba: ni menú, ni recetas nuevas, ni mensajes.
 * Lo único que sale de aquí es la propuesta actualizada en memoria.
 */
final class ChatAdjustMenuPreviewAction
{
    public function __construct(
        private readonly ApplyMenuSuggestionAction $applySuggestion,
        private readonly ChatAdjustMenuAction $chat,
        private readonly ApplyMenuChatOperationsAction $applyOperations,
        private readonly MealPlanRepositoryContract $mealPlanRepository,
        private readonly MealPlanPresenter $presenter,
    ) {}

    /**
     * @param  array<int, mixed>  $entries
     * @return array{
     *     reply: string,
     *     operations: array<int, array<string, mixed>>,
     *     preview: array<int, string>,
     *     entries: array<int, array<string, mixed>>,
     *     source: string
     * }
     */
    public function execute(int $userId, string $weekStart, string $message, array $entries): array
    {
        $week = PlanningWeek::forWeekStarting(
            CarbonImmutable::parse($weekStart, 'America/Lima'),
            CarbonImmutable::now(),
        );

        DB::beginTransaction();

        try {
            $this->applySuggestion->execute($userId, $weekStart, $entries);
            $result = $this->chat->execute($userId, $weekStart, $message);

            if ($result['operations'] !== []) {
                $this->applyOperations->execute($userId, $weekStart, $result['operations']);
            }

            $current = $this->mealPlanRepository->entriesForWeek(
                $userId,
                $week->startsOn,
                $week->endsOn,
            );

            $result['entries'] = $current
                ->map(fn (MealPlanEntry $entry): array => $this->presenter->entry($entry))
                ->values()
                ->all();

            return $result;
        } finally {
            DB::rollBack();
        }
    }
}
