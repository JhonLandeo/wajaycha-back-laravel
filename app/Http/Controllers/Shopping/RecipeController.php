<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shopping;

use App\Actions\Shopping\DraftAiRecipeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shopping\DraftRecipeRequest;
use App\Http\Requests\Shopping\SetRecipeEnabledRequest;
use App\Http\Requests\Shopping\StoreRecipeRequest;
use App\Http\Requests\Shopping\UpdateRecipeRequest;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Repositories\Contracts\RecipeRepositoryContract;
use App\Services\Shopping\NutritionCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class RecipeController extends Controller
{
    public function __construct(
        private readonly RecipeRepositoryContract $repository,
        private readonly NutritionCalculator $nutrition,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $userId = (int) Auth::id();
        $manage = $request->boolean('manage');
        $recipes = $manage
            ? $this->repository->allForUserForManagement($userId)
            : $this->repository->allForUser($userId);
        $states = $manage ? $this->repository->userStatesFor($userId) : [];

        return response()->json([
            'data' => $recipes
                ->map(fn (Recipe $recipe): array => $this->present(
                    $recipe,
                    $manage
                        ? ($recipe->user_id === null ? ($states[(int) $recipe->id] ?? true) : (bool) $recipe->is_active)
                        : null,
                ))
                ->values()
                ->all(),
        ]);
    }

    public function draft(DraftRecipeRequest $request, DraftAiRecipeAction $action): JsonResponse
    {
        $draft = $action->execute(
            (int) Auth::id(),
            $request->validated('idea'),
            (bool) $request->validated('use_pantry', false),
        );

        if ($draft === null) {
            return response()->json([
                'message' => 'No pudimos generar una receta. Intenta con otra idea.',
            ], 422);
        }

        return response()->json(['data' => $draft]);
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
        $userId = (int) Auth::id();
        $model = $this->repository->findEditableForUser($recipe, $userId);

        if ($model === null) {
            $global = $this->repository->findGlobal($recipe);

            if ($global === null) {
                abort(404);
            }

            $copy = $this->repository->create(
                $userId,
                $this->recipeData($request),
                $this->ingredientsData($request),
            );

            return response()->json(['data' => $this->present($copy), 'copied' => true]);
        }

        $updated = $this->repository->update(
            $model,
            $this->recipeData($request),
            $this->ingredientsData($request),
        );

        return response()->json(['data' => $this->present($updated)]);
    }

    public function setEnabled(SetRecipeEnabledRequest $request, int $recipe): JsonResponse
    {
        $userId = (int) Auth::id();
        $model = $this->repository->findAnyForUser($recipe, $userId);

        if ($model === null) {
            abort(404);
        }

        $enabled = (bool) $request->validated('enabled');
        $this->repository->setUserEnabled($userId, $model, $enabled);
        $model->refresh();

        return response()->json([
            'data' => $this->present($model, $model->user_id === null ? $enabled : (bool) $model->is_active),
        ]);
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

        $data = [
            'name' => trim((string) $validated['name']),
            'base_servings' => (int) $validated['base_servings'],
            'meal_types' => array_values(array_unique($validated['meal_types'])),
            'prep_minutes' => $validated['prep_minutes'] ?? null,
            'instructions' => $validated['instructions'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ];

        if (array_key_exists('course', $validated)) {
            $data['course'] = (string) $validated['course'];
        }

        if (array_key_exists('cuisine', $validated)) {
            $data['cuisine'] = (string) $validated['cuisine'];
        }

        if (array_key_exists('required_equipment', $validated)) {
            $data['required_equipment'] = array_values(array_unique($validated['required_equipment']));
        }

        if (array_key_exists('diet_tags', $validated)) {
            $data['diet_tags'] = array_values(array_unique($validated['diet_tags']));
        }

        if (array_key_exists('origin', $validated)) {
            $data['origin'] = (string) $validated['origin'];
        }

        if (array_key_exists('image_url', $validated)) {
            $data['image_url'] = $validated['image_url'] ?: null;
        }

        return $data;
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
    private function present(Recipe $recipe, ?bool $isEnabled = null): array
    {
        return [
            'id' => (int) $recipe->id,
            'name' => $recipe->name,
            'base_servings' => (int) $recipe->base_servings,
            'meal_types' => $recipe->meal_types,
            'course' => $recipe->course->value,
            'course_label' => $recipe->course->label(),
            'origin' => $recipe->origin->value,
            'origin_label' => $recipe->origin->label(),
            'cuisine' => $recipe->cuisine->value,
            'cuisine_label' => $recipe->cuisine->label(),
            'required_equipment' => $recipe->required_equipment ?? [],
            'diet_tags' => $recipe->diet_tags ?? [],
            'prep_minutes' => $recipe->prep_minutes,
            'instructions' => $recipe->instructions,
            'image_url' => $recipe->image_url,
            'is_active' => (bool) $recipe->is_active,
            'is_enabled' => $isEnabled ?? ($recipe->user_id === null ? true : (bool) $recipe->is_active),
            'is_global' => $recipe->user_id === null,
            'nutrition' => $this->nutrition->perServing($recipe)->toArray(),
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
