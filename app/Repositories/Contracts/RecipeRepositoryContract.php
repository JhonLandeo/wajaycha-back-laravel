<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Recipe;
use Illuminate\Database\Eloquent\Collection;

interface RecipeRepositoryContract
{
    /**
     * Recetas visibles para el usuario: catálogo global activo + recetas propias.
     *
     * @return Collection<int, Recipe>
     */
    public function allForUser(int $userId): Collection;

    /**
     * Una receta visible para lectura (global o propia), o null.
     */
    public function findForUser(int $id, int $userId): ?Recipe;

    /**
     * Una receta que el usuario puede editar o borrar. Las globales no lo son.
     */
    public function findEditableForUser(int $id, int $userId): ?Recipe;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array{product_id: int, quantity: float|int|string, unit: string, is_optional?: bool}>  $ingredients
     */
    public function create(int $userId, array $data, array $ingredients): Recipe;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array{product_id: int, quantity: float|int|string, unit: string, is_optional?: bool}>  $ingredients
     */
    public function update(Recipe $recipe, array $data, array $ingredients): Recipe;

    public function delete(Recipe $recipe): bool;
}
