<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\DTOs\Shopping\PlanningWeek;
use App\Enums\MealEntryKind;
use App\Enums\RecipeCourse;
use App\Models\MealPlanSettings;
use App\Models\Product;
use App\Repositories\Contracts\MealPlanRepositoryContract;
use App\Repositories\Contracts\RecipeRepositoryContract;
use App\Repositories\Contracts\ShoppingRepositoryContract;
use App\Services\Shopping\AiRecipeDraftValidator;
use App\Services\Shopping\MenuCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Escribe una propuesta de menú que el usuario ya revisó (ADR-0027).
 *
 * Las recetas nuevas llegan como borradores; recién acá se validan y se crean.
 * Luego se reemplaza la semana en una transacción. Si algo falla, no queda ni
 * una receta ni una entrada a medias.
 */
final class ApplyMenuSuggestionAction
{
    public function __construct(
        private readonly MealPlanRepositoryContract $mealPlanRepository,
        private readonly RecipeRepositoryContract $recipeRepository,
        private readonly ShoppingRepositoryContract $shoppingRepository,
        private readonly AiRecipeDraftValidator $validator,
        private readonly MenuCalendar $calendar,
    ) {}

    /**
     * @param  array<int, mixed>  $entries
     */
    public function execute(int $userId, string $weekStart, array $entries): void
    {
        $week = PlanningWeek::forWeekStarting(
            CarbonImmutable::parse($weekStart, 'America/Lima'),
            CarbonImmutable::now(),
        );

        $settings = $this->mealPlanRepository->settingsFor($userId);
        $days = $this->calendar->upcomingActiveDays($week, $settings->plan_weekdays);
        $meals = $settings->meal_types;
        $products = $this->shoppingRepository->listProductsFor($userId);

        $productsById = [];
        foreach ($products as $product) {
            $productsById[(int) $product->id] = $product;
        }

        $existingNames = [];
        foreach ($this->recipeRepository->allForUser($userId) as $recipe) {
            $existingNames[$this->fold($recipe->name)] = true;
        }

        $normalized = $this->normalize($entries, $days, $meals);
        $this->assertNoRepeatedRecipes($normalized);

        DB::transaction(function () use ($userId, $week, $settings, $productsById, $normalized, &$existingNames): void {
            $recipeMap = [];
            $drinkMap = [];

            foreach ($normalized as $entry) {
                if ($entry['kind'] !== MealEntryKind::Recipe) {
                    continue;
                }

                $recipeOldId = $entry['recipe_id'];
                if (! isset($recipeMap[$recipeOldId])) {
                    $recipeMap[$recipeOldId] = $this->resolveRecipe(
                        $entry['recipe'],
                        $recipeOldId,
                        $entry['meal_type'],
                        'main',
                        $userId,
                        $settings,
                        $productsById,
                        $existingNames,
                    );
                }

                $drinkOldId = $entry['drink_recipe_id'];
                if ($drinkOldId !== null && ! isset($drinkMap[$drinkOldId])) {
                    $drinkMap[$drinkOldId] = $this->resolveRecipe(
                        $entry['drink_recipe'],
                        $drinkOldId,
                        $entry['meal_type'],
                        'drink',
                        $userId,
                        $settings,
                        $productsById,
                        $existingNames,
                    );
                }
            }

            $this->mealPlanRepository->clearWeek($userId, $week->startsOn, $week->endsOn);

            $newEntryIds = [];

            foreach ($normalized as $entry) {
                if ($entry['kind'] === MealEntryKind::Leftover) {
                    continue;
                }

                $recipeId = $entry['recipe_id'] === null ? null : ($recipeMap[$entry['recipe_id']] ?? null);
                $drinkId = $entry['drink_recipe_id'] === null ? null : ($drinkMap[$entry['drink_recipe_id']] ?? null);

                $created = $this->mealPlanRepository->upsertEntry($userId, [
                    'plan_date' => $entry['plan_date'],
                    'meal_type' => $entry['meal_type'],
                    'kind' => $entry['kind']->value,
                    'status' => 'planned',
                    'recipe_id' => $recipeId,
                    'drink_recipe_id' => $drinkId,
                    'servings' => $entry['servings'],
                    'cook_servings' => $entry['cook_servings'],
                    'leftover_of_entry_id' => null,
                    'notes' => $entry['notes'],
                ]);

                $newEntryIds[$entry['id']] = (int) $created->id;
            }

            foreach ($normalized as $entry) {
                if ($entry['kind'] !== MealEntryKind::Leftover) {
                    continue;
                }

                $sourceOldId = $entry['leftover_of_entry_id'];
                $sourceNewId = $sourceOldId === null ? null : ($newEntryIds[$sourceOldId] ?? null);

                if ($sourceNewId === null) {
                    throw ValidationException::withMessages([
                        'entries' => 'Una sobra apunta a una comida que ya no existe en la propuesta.',
                    ]);
                }

                $source = $this->findEntry($normalized, $sourceOldId);

                if ($source === null || $source['kind'] !== MealEntryKind::Recipe) {
                    throw ValidationException::withMessages([
                        'entries' => 'Una sobra no apunta a una receta de la propuesta.',
                    ]);
                }

                $extra = max(0, $source['cook_servings'] - $source['servings']);

                if ($entry['servings'] > $extra) {
                    throw ValidationException::withMessages([
                        'entries' => 'Una sobra pide más porciones de las que deja su comida de origen.',
                    ]);
                }

                $this->mealPlanRepository->upsertEntry($userId, [
                    'plan_date' => $entry['plan_date'],
                    'meal_type' => $entry['meal_type'],
                    'kind' => MealEntryKind::Leftover->value,
                    'status' => 'planned',
                    'recipe_id' => $source['recipe_id'] === null ? null : ($recipeMap[$source['recipe_id']] ?? null),
                    'drink_recipe_id' => null,
                    'servings' => $entry['servings'],
                    'cook_servings' => 0,
                    'leftover_of_entry_id' => $sourceNewId,
                    'notes' => $entry['notes'],
                ]);
            }
        });
    }

    /**
     * Una semana no repite el mismo plato principal. Las sobras quedan fuera:
     * comparten `recipe_id` con la comida que las produjo y eso es intencional.
     *
     * @param  array<int, array<string, mixed>>  $normalized
     */
    private function assertNoRepeatedRecipes(array $normalized): void
    {
        $seen = [];

        foreach ($normalized as $entry) {
            if ($entry['kind'] !== MealEntryKind::Recipe || $entry['recipe_id'] === null) {
                continue;
            }

            $recipeId = (int) $entry['recipe_id'];

            if (isset($seen[$recipeId])) {
                throw ValidationException::withMessages([
                    'entries' => 'La propuesta repite una receta en la semana. Quita una de las dos o pide otra opción.',
                ]);
            }

            $seen[$recipeId] = true;
        }
    }

    /**
     * @param  array<int, mixed>  $entries
     * @param  array<int, string>  $days
     * @param  array<int, string>  $meals
     * @return array<int, array{
     *     id: int,
     *     plan_date: string,
     *     meal_type: string,
     *     kind: MealEntryKind,
     *     recipe_id: int|null,
     *     recipe: array<string, mixed>|null,
     *     drink_recipe_id: int|null,
     *     drink_recipe: array<string, mixed>|null,
     *     servings: int,
     *     cook_servings: int,
     *     leftover_of_entry_id: int|null,
     *     notes: string|null
     * }>
     */
    private function normalize(array $entries, array $days, array $meals): array
    {
        $normalized = [];
        $seenSlots = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw ValidationException::withMessages(['entries' => 'La propuesta tiene una entrada inválida.']);
            }

            $date = (string) ($entry['plan_date'] ?? '');
            $meal = (string) ($entry['meal_type'] ?? '');
            $kind = MealEntryKind::tryFrom((string) ($entry['kind'] ?? ''));

            if (! in_array($date, $days, true) || ! in_array($meal, $meals, true) || $kind === null) {
                throw ValidationException::withMessages(['entries' => 'La propuesta tiene una fecha, comida o tipo inválido.']);
            }

            $slot = $date.'|'.$meal;
            if (isset($seenSlots[$slot])) {
                throw ValidationException::withMessages(['entries' => 'La propuesta repite una comida en el mismo día.']);
            }
            $seenSlots[$slot] = true;

            $servings = (int) ($entry['servings'] ?? 0);
            $cookServings = (int) ($entry['cook_servings'] ?? 0);

            if ($kind === MealEntryKind::Recipe && ($servings < 1 || $cookServings < $servings)) {
                throw ValidationException::withMessages(['entries' => 'Una receta tiene porciones inválidas.']);
            }

            if ($kind === MealEntryKind::Leftover && $servings < 1) {
                throw ValidationException::withMessages(['entries' => 'Una sobra tiene porciones inválidas.']);
            }

            if ($kind === MealEntryKind::Recipe && (int) ($entry['recipe_id'] ?? 0) <= 0) {
                throw ValidationException::withMessages(['entries' => 'Una receta de la propuesta no tiene id.']);
            }

            $recipe = is_array($entry['recipe'] ?? null) ? $entry['recipe'] : null;
            $drink = is_array($entry['drink_recipe'] ?? null) ? $entry['drink_recipe'] : null;

            $normalized[] = [
                'id' => (int) ($entry['id'] ?? 0),
                'plan_date' => $date,
                'meal_type' => $meal,
                'kind' => $kind,
                'recipe_id' => $kind === MealEntryKind::Recipe ? (int) ($entry['recipe_id'] ?? 0) : null,
                'recipe' => $recipe,
                'drink_recipe_id' => $kind === MealEntryKind::Recipe && isset($entry['drink_recipe_id'])
                    ? (int) $entry['drink_recipe_id']
                    : null,
                'drink_recipe' => $drink,
                'servings' => $servings,
                'cook_servings' => $cookServings,
                'leftover_of_entry_id' => $kind === MealEntryKind::Leftover
                    ? (int) ($entry['leftover_of_entry_id'] ?? 0)
                    : null,
                'notes' => isset($entry['notes']) && is_string($entry['notes']) ? $entry['notes'] : null,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>|null  $summary
     * @param  array<int, Product>  $productsById
     * @param  array<string, true>  $existingNames
     */
    private function resolveRecipe(
        ?array $summary,
        int $oldId,
        string $meal,
        string $role,
        int $userId,
        MealPlanSettings $settings,
        array $productsById,
        array &$existingNames,
    ): int {
        $isAi = $summary !== null && ($summary['origin'] ?? null) === 'ai';

        if ($isAi) {
            // Una receta IA que ya vive en el recetario no es un borrador:
            // reutilízala por id. Un borrador de una sugerencia tiene un id
            // temporal que ya no existe o apunta a otro plato.
            $lookupId = $oldId > 0 ? $oldId : (int) ($summary['id'] ?? 0);
            $existing = $lookupId > 0 ? $this->recipeRepository->findForUser($lookupId, $userId) : null;

            if ($existing !== null && $existing->name === (string) ($summary['name'] ?? '')) {
                if (! in_array($meal, $existing->meal_types, true)) {
                    throw ValidationException::withMessages(['entries' => 'Una receta de la propuesta ya no está disponible.']);
                }

                if ($role === 'main' && $existing->course === RecipeCourse::Drink) {
                    throw ValidationException::withMessages(['entries' => 'Una bebida no puede ser el plato principal.']);
                }

                if ($role === 'drink' && $existing->course !== RecipeCourse::Drink) {
                    throw ValidationException::withMessages(['entries' => 'La bebida elegida no es una bebida.']);
                }

                return (int) $existing->id;
            }
        }

        if (! $isAi) {
            // Una alternativa para un hueco vacío puede traer un id temporal
            // negativo; la receta real viaja en el resumen.
            $recipeId = $oldId > 0 ? $oldId : (int) ($summary['id'] ?? 0);
            $recipe = $recipeId > 0 ? $this->recipeRepository->findForUser($recipeId, $userId) : null;

            if ($recipe === null || ! in_array($meal, $recipe->meal_types, true)) {
                throw ValidationException::withMessages(['entries' => 'Una receta de la propuesta ya no está disponible.']);
            }

            if ($role === 'main' && $recipe->course === RecipeCourse::Drink) {
                throw ValidationException::withMessages(['entries' => 'Una bebida no puede ser el plato principal.']);
            }

            if ($role === 'drink' && $recipe->course !== RecipeCourse::Drink) {
                throw ValidationException::withMessages(['entries' => 'La bebida elegida no es una bebida.']);
            }

            return (int) $recipe->id;
        }

        $raw = [
            'name' => (string) ($summary['name'] ?? ''),
            'meal_types' => (array) ($summary['meal_types'] ?? [$meal]),
            'course' => (string) ($summary['course'] ?? 'main'),
            'prep_minutes' => $summary['prep_minutes'] ?? null,
            'equipment' => (array) ($summary['required_equipment'] ?? []),
            'diet_tags' => (array) ($summary['diet_tags'] ?? []),
            'instructions' => (string) ($summary['instructions'] ?? ''),
            'ingredients' => (array) ($summary['ingredients'] ?? []),
        ];

        $draft = $this->validator->validate($raw, $settings, $productsById, $existingNames);

        if ($draft === null || ! in_array($meal, $draft['data']['meal_types'], true)) {
            throw ValidationException::withMessages(['entries' => 'Una receta propuesta por la IA ya no es válida. Vuelve a sugerir.']);
        }

        if ($role === 'drink' && $draft['data']['course'] !== RecipeCourse::Drink->value) {
            throw ValidationException::withMessages(['entries' => 'La bebida propuesta no es una bebida.']);
        }

        if ($role === 'main' && $draft['data']['course'] === RecipeCourse::Drink->value) {
            throw ValidationException::withMessages(['entries' => 'La receta propuesta es una bebida y no puede ser el plato principal.']);
        }

        try {
            $recipe = $this->recipeRepository->create($userId, $draft['data'], $draft['ingredients']);
        } catch (Throwable $e) {
            report($e);

            throw ValidationException::withMessages(['entries' => 'No pudimos guardar una receta propuesta. Revisa el nombre o vuelve a sugerir.']);
        }

        $existingNames[$this->fold($recipe->name)] = true;

        return (int) $recipe->id;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<string, mixed>|null
     */
    private function findEntry(array $entries, ?int $id): ?array
    {
        foreach ($entries as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }

        return null;
    }

    private function fold(string $value): string
    {
        return \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii($value));
    }
}
