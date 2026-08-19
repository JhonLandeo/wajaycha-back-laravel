<?php

declare(strict_types=1);

/**
 * The Pareto assignment written when a category is created or updated.
 *
 * `StoreCategoryAction` and `UpdateCategoryAction` write `category_pareto_assignments`
 * with a raw `DB::table()` call, and nothing covered that they do it correctly — only
 * `CrossUserAccessTest` touched `/api/categories` at all, and it asks a different
 * question.
 *
 * The assignment is not decoration: `ParetoRepository` joins that table to answer which
 * categories fall in which Pareto band, which is the reading the whole product is built
 * around. A create that silently skips the row, or an update that leaves a stale one
 * behind, moves a category into the wrong band without any error.
 */

use App\Models\Category;
use App\Models\ParetoClassification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * @return array{0: User, 1: array<string, string>}
 */
function categoryOwner(): array
{
    /** @var \Tests\TestCase $t */
    return test()->userWithAuth();
}

function assignmentFor(int $categoryId): ?object
{
    return DB::table('category_pareto_assignments')
        ->where('category_id', $categoryId)
        ->first();
}

// -------------------------------------------------------------------- create

it('asigna la clasificacion pareto al crear una categoria de gasto', function () {
    [$user, $headers] = categoryOwner();
    $pareto = ParetoClassification::factory()->create(['user_id' => $user->id]);

    $response = $this->postJson('/api/categories', [
        'name' => 'Delivery',
        'type' => 'expense',
        'monthly_budget' => 300,
        'pareto_classification_id' => $pareto->id,
    ], $headers);

    $response->assertStatus(201);

    $assignment = assignmentFor((int) $response->json('id'));
    expect($assignment)->not->toBeNull();
    expect((int) $assignment->pareto_classification_id)->toBe($pareto->id);
});

it('no crea asignacion cuando la categoria no lleva clasificacion', function () {
    [, $headers] = categoryOwner();

    $response = $this->postJson('/api/categories', [
        'name' => 'Sueldo',
        'type' => 'income',
        'monthly_budget' => 0,
    ], $headers);

    $response->assertStatus(201);
    expect(assignmentFor((int) $response->json('id')))->toBeNull();
});

// -------------------------------------------------------------------- update

it('reapunta la asignacion existente en lugar de acumular filas', function () {
    [$user, $headers] = categoryOwner();
    $first = ParetoClassification::factory()->create(['user_id' => $user->id]);
    $second = ParetoClassification::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);

    DB::table('category_pareto_assignments')->insert([
        'category_id' => $category->id,
        'pareto_classification_id' => $first->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->putJson("/api/categories/{$category->id}", [
        'name' => 'Delivery',
        'type' => 'expense',
        'monthly_budget' => 300,
        'pareto_classification_id' => $second->id,
    ], $headers)->assertOk();

    $rows = DB::table('category_pareto_assignments')->where('category_id', $category->id)->get();

    expect($rows)->toHaveCount(1);
    expect((int) $rows->first()->pareto_classification_id)->toBe($second->id);
});

it('borra la asignacion cuando la actualizacion la deja vacia', function () {
    [$user, $headers] = categoryOwner();
    $pareto = ParetoClassification::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'income']);

    DB::table('category_pareto_assignments')->insert([
        'category_id' => $category->id,
        'pareto_classification_id' => $pareto->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->putJson("/api/categories/{$category->id}", [
        'name' => 'Sueldo',
        'type' => 'income',
        'monthly_budget' => 0,
    ], $headers)->assertOk();

    expect(assignmentFor($category->id))->toBeNull();
});

it('responde 404 al actualizar la categoria de otro usuario', function () {
    [, $headers] = categoryOwner();
    $stranger = User::factory()->create();
    $foreign = Category::factory()->create(['user_id' => $stranger->id, 'type' => 'income']);

    $this->putJson("/api/categories/{$foreign->id}", [
        'name' => 'Secuestrada',
        'type' => 'income',
        'monthly_budget' => 0,
    ], $headers)->assertStatus(404);
});

// ---------------------------------------------------------------------- read

it('devuelve la clasificacion pareto vigente al consultar la categoria', function () {
    [$user, $headers] = categoryOwner();
    $pareto = ParetoClassification::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);

    DB::table('category_pareto_assignments')->insert([
        'category_id' => $category->id,
        'pareto_classification_id' => $pareto->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->getJson("/api/categories/{$category->id}", $headers)
        ->assertOk()
        ->assertJsonPath('pareto_classification_id', $pareto->id);
});

it('devuelve null cuando la categoria no esta en ninguna banda', function () {
    [$user, $headers] = categoryOwner();
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'income']);

    $this->getJson("/api/categories/{$category->id}", $headers)
        ->assertOk()
        ->assertJsonPath('pareto_classification_id', null);
});

// ----------------------------------------------------------------- parent_id

it('conserva el padre cuando la actualizacion no menciona parent_id', function () {
    [$user, $headers] = categoryOwner();
    $parent = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);
    $child = Category::factory()->asChild($parent->id)->create([
        'user_id' => $user->id,
        'type' => 'income',
    ]);

    $this->putJson("/api/categories/{$child->id}", [
        'name' => 'Renombrada',
        'type' => 'income',
        'monthly_budget' => 0,
    ], $headers)->assertOk();

    expect($child->fresh()->parent_id)->toBe($parent->id);
});

it('mueve la categoria a raiz cuando la actualizacion manda parent_id null', function () {
    [$user, $headers] = categoryOwner();
    $parent = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);
    $child = Category::factory()->asChild($parent->id)->create([
        'user_id' => $user->id,
        'type' => 'income',
    ]);

    $this->putJson("/api/categories/{$child->id}", [
        'name' => 'Promovida',
        'type' => 'income',
        'monthly_budget' => 0,
        'parent_id' => null,
    ], $headers)->assertOk();

    expect($child->fresh()->parent_id)->toBeNull();
});

it('rechaza un parent_id que pertenece a otro usuario', function () {
    [$user, $headers] = categoryOwner();
    $stranger = User::factory()->create();
    $foreignParent = Category::factory()->create(['user_id' => $stranger->id, 'type' => 'expense']);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'income']);

    $this->putJson("/api/categories/{$category->id}", [
        'name' => 'Adoptada',
        'type' => 'income',
        'monthly_budget' => 0,
        'parent_id' => $foreignParent->id,
    ], $headers)->assertStatus(422);
});
