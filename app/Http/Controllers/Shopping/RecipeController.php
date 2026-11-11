<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shopping;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shopping\StoreRecipeRequest;
use App\Http\Requests\Shopping\UpdateRecipeRequest;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Repositories\Contracts\RecipeRepositoryContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class RecipeController extends Controller
{
    public function __construct(
        private readonly RecipeRepositoryContract $repository,
    ) {}

    public function index(): JsonResponse
    {
        $recipes = $this->repository->allForUser((int) Auth::id());

        return response()->json([
            'data' => $recipes->map(fn (Recipe $recipe): array => $this->present($recipe))->values()->all(),
        ]);
    }

    public function store(StoreRecipeRequest $request): JsonResponse
    {
        $recipe = $this->repository->create(
            (int) Auth::id(),
            $this->recipeData($request),
            $this->ingredientsData($request),
        );

        return response()->json(['data' => $this->present($recipe)], 201);
    }

    public function update(UpdateRecipeRequest $request, int $recipe): JsonResponse
    {
        $model = $this->repository->findEditableForUser($recipe, (int) Auth::id());

        if ($model === null) {
            abort(404);
        }

        $updated = $this->repository->update(
            $model,
            $this->recipeData($request),
            $this->ingredientsData($request),
        );

        return response()->json(['data' => $this->present($updated)]);
    }

    public function destroy(int $recipe): JsonResponse
    {
        $model = $this->repository->findEditableForUser($recipe, (int) Auth::id());

        if ($model === null) {
            abort(404);
        }

        $this->repository->delete($model);

        return response()->json(['status' => 'deleted']);
    }

    /**
     * @return array<string, mixed>
     */
    private function recipeData(StoreRecipeRequest $request): array
    {
        $validated = $request->validated();

        return [
            'name' => trim((string) $validated['name']),
            'base_servings' => (int) $validated['base_servings'],
            'meal_types' => array_values(array_unique($validated['meal_types'])),
            'prep_minutes' => $validated['prep_minutes'] ?? null,
            'instructions' => $validated['instructions'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ];
    }

    /**
     * @return array<int, array{product_id: int, quantity: float|int|string, unit: string, is_optional: bool}>
     */
    private function ingredientsData(StoreRecipeRequest $request): array
    {
        return array_map(static fn (array $ingredient): array => [
            'product_id' => (int) $ingredient['product_id'],
            'quantity' => $ingredient['quantity'],
            'unit' => (string) $ingredient['unit'],
            'is_optional' => (bool) ($ingredient['is_optional'] ?? false),
        ], $request->validated('ingredients'));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Recipe $recipe): array
    {
        return [
            'id' => (int) $recipe->id,
            'name' => $recipe->name,
            'base_servings' => (int) $recipe->base_servings,
            'meal_types' => $recipe->meal_types,
            'prep_minutes' => $recipe->prep_minutes,
            'instructions' => $recipe->instructions,
            'is_active' => (bool) $recipe->is_active,
            'is_global' => $recipe->user_id === null,
            'ingredients' => $recipe->ingredients
                ->map(static fn (RecipeIngredient $ingredient): array => [
                    'id' => (int) $ingredient->id,
                    'product_id' => (int) $ingredient->product_id,
                    'product_name' => $ingredient->product->name,
                    'quantity' => (float) $ingredient->quantity,
                    'unit' => $ingredient->unit,
                    'is_optional' => (bool) $ingredient->is_optional,
                ])
                ->values()
                ->all(),
        ];
    }
}
