<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Recipe;
use Carbon\CarbonImmutable;
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
     * Recetario de gestión: catálogo global + recetas propias, incluidas las
     * inactivas y las desactivadas por el usuario. No es la lista que ve la IA.
     *
     * @return Collection<int, Recipe>
     */
    public function allForUserForManagement(int $userId): Collection;

    /**
     * Estado por usuario de las recetas globales, keyed by recipe id.
     *
     * @return array<int, bool>
     */
    public function userStatesFor(int $userId): array;

    public function findGlobal(int $id): ?Recipe;

    public function findAnyForUser(int $id, int $userId): ?Recipe;

    /** Activa o desactiva una receta propia; oculta una global para el usuario. */
    public function setUserEnabled(int $userId, Recipe $recipe, bool $enabled): void;

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

    /** Cuántas recetas generó la IA para el usuario desde una fecha. */
    public function countAiGeneratedSince(int $userId, CarbonImmutable $since): int;
}
