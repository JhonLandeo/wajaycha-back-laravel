<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Repositories\Contracts\RecipeRepositoryContract;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class RecipeRepository implements RecipeRepositoryContract
{
    public function allForUser(int $userId): Collection
    {
        return Recipe::query()
            ->where(static function ($query) use ($userId): void {
                $query->whereNull('user_id')->orWhere('user_id', $userId);
            })
            ->where('is_active', true)
            ->with(['ingredients.product'])
            ->orderBy('name')
            ->get();
    }

    public function findForUser(int $id, int $userId): ?Recipe
    {
        /** @var Recipe|null $recipe */
        $recipe = Recipe::query()
            ->whereKey($id)
            ->where(static function ($query) use ($userId): void {
                $query->whereNull('user_id')->orWhere('user_id', $userId);
            })
            ->with(['ingredients.product'])
            ->first();

        return $recipe;
    }

    public function findEditableForUser(int $id, int $userId): ?Recipe
    {
        /** @var Recipe|null $recipe */
        $recipe = Recipe::query()
            ->whereKey($id)
            ->where('user_id', $userId)
            ->with(['ingredients.product'])
            ->first();

        return $recipe;
    }

    public function create(int $userId, array $data, array $ingredients): Recipe
    {
        return DB::transaction(function () use ($userId, $data, $ingredients): Recipe {
            /** @var Recipe $recipe */
            $recipe = Recipe::query()->create([
                ...$data,
                'user_id' => $userId,
                'slug' => null,
            ]);

            $this->replaceIngredients($recipe, $ingredients);

            return $recipe->load('ingredients.product');
        });
    }

    public function update(Recipe $recipe, array $data, array $ingredients): Recipe
    {
        return DB::transaction(function () use ($recipe, $data, $ingredients): Recipe {
            $recipe->update($data);
            $this->replaceIngredients($recipe, $ingredients);

            return $recipe->load('ingredients.product');
        });
    }

    public function delete(Recipe $recipe): bool
    {
        return (bool) $recipe->delete();
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float|int|string, unit: string, is_optional?: bool}>  $ingredients
     */
    private function replaceIngredients(Recipe $recipe, array $ingredients): void
    {
        $recipe->ingredients()->delete();

        foreach ($ingredients as $ingredient) {
            RecipeIngredient::query()->create([
                'recipe_id' => $recipe->id,
                'product_id' => (int) $ingredient['product_id'],
                'quantity' => $ingredient['quantity'],
                'unit' => $ingredient['unit'],
                'is_optional' => (bool) ($ingredient['is_optional'] ?? false),
            ]);
        }
    }
}
