<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\DTOs\Shopping\PantryStock;
use App\DTOs\Shopping\PlanningWeek;
use App\Enums\MealType;
use App\Enums\MenuStrategy;
use App\Models\MealPlanEntry;
use App\Repositories\Contracts\FoodBudgetRepositoryContract;
use App\Repositories\Contracts\MealPlanRepositoryContract;
use App\Repositories\Contracts\MenuChatRepositoryContract;
use App\Repositories\Contracts\ShoppingRepositoryContract;
use App\Services\AI\OpenCodeGateway;
use App\Services\Shopping\MenuCalendar;
use App\Services\Shopping\MenuCandidateFilter;
use App\Services\Shopping\MenuOperationValidator;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Un turno del chat que ajusta el menú (ADR-0020, decisión 6).
 *
 * Persiste el mensaje del usuario, relee el estado real de la semana y llama a
 * la IA. La IA devuelve texto y operaciones; el backend valida las operaciones
 * y las devuelve como preview. Nada se escribe en el menú: eso ocurre solo en
 * {@see ApplyMenuChatOperationsAction}, cuando el usuario confirma.
 */
final class ChatAdjustMenuAction
{
    public function __construct(
        private readonly MealPlanRepositoryContract $mealPlanRepository,
        private readonly MenuChatRepositoryContract $chatRepository,
        private readonly ShoppingRepositoryContract $shoppingRepository,
        private readonly FoodBudgetRepositoryContract $foodBudgetRepository,
        private readonly MenuCandidateFilter $candidateFilter,
        private readonly MenuCalendar $calendar,
        private readonly MenuOperationValidator $validator,
        private readonly OpenCodeGateway $gateway,
    ) {}

    /**
     * @return array{reply: string, operations: array<int, array<string, mixed>>, preview: array<int, string>, source: string}
     */
    public function execute(int $userId, string $weekStart, string $message): array
    {
        $asOf = CarbonImmutable::now();
        $week = PlanningWeek::forWeekStarting(
            CarbonImmutable::parse($weekStart, 'America/Lima'),
            $asOf,
        );

        $settings = $this->mealPlanRepository->settingsFor($userId);
        $days = $this->calendar->upcomingActiveDays($week, $settings->plan_weekdays);
        $meals = $settings->meal_types;
        $candidates = $this->candidateFilter->forUser($userId, $settings);
        $entries = $this->mealPlanRepository->entriesForWeek($userId, $week->startsOn, $week->endsOn);
        $pantry = $this->shoppingRepository->pantryStockFor($userId);

        $this->chatRepository->append(
            $userId,
            $week->startsOn->toDateString(),
            'user',
            $message,
        );

        $history = $this->chatRepository->historyFor($userId, $week->startsOn->toDateString());
        $contents = $this->contents($week, $settings, $days, $meals, $candidates, $entries, $pantry, $history);

        $reply = null;
        $rawOperations = [];

        try {
            $response = $this->gateway->generateJson($contents, ['temperature' => 0.3]);

            if (is_array($response)) {
                $reply = isset($response['reply']) && is_string($response['reply'])
                    ? trim($response['reply'])
                    : null;
                $rawOperations = isset($response['operations']) && is_array($response['operations'])
                    ? $response['operations']
                    : [];
            }
        } catch (Throwable $e) {
            report($e);
        }

        $source = $reply !== null && $reply !== '' ? 'ai' : 'fallback';

        if ($source === 'fallback') {
            $reply = 'No pude interpretar el pedido. Dime qué comida quieres cambiar y por cuál.';
            $rawOperations = [];
        }

        $operations = $this->validator->validate(
            $rawOperations,
            $days,
            $meals,
            $candidates,
            $entries,
            $settings,
        );

        $preview = $this->preview($operations, $entries, $candidates);

        $this->chatRepository->append(
            $userId,
            $week->startsOn->toDateString(),
            'assistant',
            (string) $reply,
            ['operations' => $operations, 'source' => $source],
        );

        return [
            'reply' => (string) $reply,
            'operations' => $operations,
            'preview' => $preview,
            'source' => $source,
        ];
    }

    /**
     * @param  array<int, string>  $days
     * @param  array<int, string>  $meals
     * @param  array<string, array<int, \App\Models\Recipe>>  $candidates
     * @param  \Illuminate\Database\Eloquent\Collection<int, MealPlanEntry>  $entries
     * @param  array<int, PantryStock>  $pantry
     * @param  array<int, \App\Models\MenuChatMessage>  $history
     * @return array<int, array{role: string, parts: array<int, array{text: string}>}>
     */
    private function contents(
        PlanningWeek $week,
        \App\Models\MealPlanSettings $settings,
        array $days,
        array $meals,
        array $candidates,
        $entries,
        array $pantry,
        array $history,
    ): array {
        $budget = $this->foodBudgetRepository->snapshotFor($settings->user_id, CarbonImmutable::now());

        $recipes = [];
        foreach ($candidates as $items) {
            foreach ($items as $recipe) {
                $recipes[] = [
                    'id' => (int) $recipe->id,
                    'nombre' => $recipe->name,
                    'comidas' => $recipe->meal_types,
                    'minutos' => $recipe->prep_minutes,
                    'ingredientes' => $recipe->ingredients
                        ->map(static fn ($ingredient): string => (string) $ingredient->product->name)
                        ->filter()
                        ->values()
                        ->all(),
                ];
            }
        }

        $context = [
            'semana' => ['inicio' => $week->startsOn->toDateString(), 'fin' => $week->endsOn->toDateString()],
            'dias_activos' => $days,
            'comidas_activas' => $meals,
            'personas' => (int) $settings->household_size,
            'criterio' => ($settings->menu_strategy ?? MenuStrategy::Balanced)->value,
            'presupuesto_semanal' => $budget?->weeklyTarget,
            'despensa' => array_map(static fn (PantryStock $item): array => [
                'producto' => $item->productName,
                'cantidad' => $item->quantity,
                'unidad' => $item->unit?->value,
                'vence' => $item->expiresOn?->toDateString(),
            ], $pantry),
            'menu_actual' => $entries->map(static fn (MealPlanEntry $entry): array => [
                'fecha' => $entry->plan_date->toDateString(),
                'comida' => $entry->meal_type->value,
                'kind' => $entry->kind->value,
                'status' => $entry->status->value,
                'recipe_id' => $entry->recipe_id,
                'receta' => $entry->recipe?->name,
                'servings' => (int) $entry->servings,
                'cook_servings' => (int) $entry->cook_servings,
            ])->values()->all(),
            'recetas' => $recipes,
        ];

        $instruction = <<<PROMPT
Eres un asistente que ajusta el menú semanal de una familia peruana. Devuelve solo JSON válido con la forma:
{"reply":"texto breve para el usuario","operations":[...]}

Reglas:
- Usa únicamente recipe_id que aparezcan en el catálogo `recetas`. No inventes recetas ni ids.
- Las fechas deben estar en `dias_activos` y las comidas en `comidas_activas`.
- No cambies nada sin una operación; si no entiendes el pedido, deja `operations` vacío y explica en `reply`.
- Respeta el presupuesto, las porciones y los filtros ya aplicados al catálogo.

Operaciones permitidas (una por cambio):
- {"op":"swap_recipe","date":"YYYY-MM-DD","meal":"lunch","recipe_id":1}
- {"op":"set_cook_servings","date":"YYYY-MM-DD","meal":"lunch","cook_servings":4}
- {"op":"mark_eat_out","date":"YYYY-MM-DD","meal":"dinner"}
- {"op":"clear_day","date":"YYYY-MM-DD"}
- {"op":"regenerate_day","date":"YYYY-MM-DD"}

Contexto actual:
```json
{$this->json($context)}
```
PROMPT;

        $contents = [
            ['role' => 'user', 'parts' => [['text' => $instruction]]],
        ];

        foreach ($history as $message) {
            $contents[] = [
                'role' => $message->role === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string) $message->content]],
            ];
        }

        return $contents;
    }

    /**
     * @param  array<int, array<string, mixed>>  $operations
     * @param  \Illuminate\Database\Eloquent\Collection<int, MealPlanEntry>  $entries
     * @param  array<string, array<int, \App\Models\Recipe>>  $candidates
     * @return array<int, string>
     */
    private function preview(array $operations, $entries, array $candidates): array
    {
        $names = [];
        foreach ($candidates as $recipes) {
            foreach ($recipes as $recipe) {
                $names[(int) $recipe->id] = $recipe->name;
            }
        }

        $bySlot = [];
        foreach ($entries as $entry) {
            $bySlot[$entry->plan_date->toDateString().'|'.$entry->meal_type->value] = $entry;
        }

        $preview = [];

        foreach ($operations as $operation) {
            $date = $this->dayLabel((string) $operation['date']);
            $meal = isset($operation['meal']) && is_string($operation['meal'])
                ? MealType::from($operation['meal'])->label()
                : null;

            $slot = isset($operation['meal'])
                ? ($bySlot[(string) $operation['date'].'|'.(string) $operation['meal']] ?? null)
                : null;

            $preview[] = match ($operation['op']) {
                'swap_recipe' => $date.($meal !== null ? ' · '.$meal : '').': '
                    .($slot?->recipe->name ?? 'sin receta')
                    .' → '.($names[(int) $operation['recipe_id']] ?? 'receta'),
                'set_cook_servings' => $date.($meal !== null ? ' · '.$meal : '').': cocinar '
                    .(int) $operation['cook_servings'].' porciones',
                'mark_eat_out' => $date.($meal !== null ? ' · '.$meal : '').': comer fuera',
                'clear_day' => $date.': vaciar el día',
                'regenerate_day' => $date.': regenerar todas las comidas',
                default => $date.': ajuste',
            };
        }

        return $preview;
    }

    private function dayLabel(string $date): string
    {
        return CarbonImmutable::parse($date, 'America/Lima')->locale('es')->isoFormat('ddd D MMM');
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
