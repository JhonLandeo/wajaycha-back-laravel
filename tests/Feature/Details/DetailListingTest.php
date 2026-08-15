<?php

declare(strict_types=1);

/**
 * Characterisation tests for `GET /api/details`, written before the query moves out
 * of the controller.
 *
 * `CrossUserAccessTest` already covers the update path's ownership scope. The listing
 * had nothing, which is the half that reads through the `get_details` PostgreSQL
 * function and the half about to change.
 *
 * As with the dashboard, these assert only what a caller observes: status, the
 * paginator's shape, which rows come back, and that another user's details never
 * appear. Nothing here names `DB::select`, a repository or a contract — a test that
 * knew how the rows were fetched would have to change when the fetching changes, and
 * would prove nothing about the move.
 */

use App\Models\Category;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\User;

/**
 * @return array{0: User, 1: array<string, string>}
 */
function detailOwner(): array
{
    /** @var \Tests\TestCase $t */
    return test()->userWithAuth();
}

/**
 * `get_details` only returns a Detail that has at least one transaction — see the
 * `EXISTS (SELECT 1 FROM transactions ...)` guard in the function. A Detail created
 * on its own is invisible to this endpoint, so every fixture needs a movement.
 *
 * The column is `description`; the function returns it aliased as `name`. The table
 * has no `name` column at all, which is worth knowing before reading the response.
 */
function detailWithMovement(User $user, string $name, ?int $categoryId = null): Detail
{
    $detail = Detail::factory()->create(['user_id' => $user->id, 'description' => $name]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'category_id' => $categoryId,
    ]);

    return $detail;
}

it('lista los details del usuario con la forma de paginador que el cliente espera', function () {
    [$user, $headers] = detailOwner();
    detailWithMovement($user, 'RAPPI');

    $response = $this->getJson('/api/details', $headers);

    $response->assertOk()->assertJsonStructure([
        'current_page',
        'data',
        'per_page',
        'total',
    ]);

    expect(collect($response->json('data'))->pluck('name'))->toContain('RAPPI');
});

it('no devuelve los details de otro usuario', function () {
    [$user, $headers] = detailOwner();
    $stranger = User::factory()->create();

    detailWithMovement($user, 'PROPIO');
    detailWithMovement($stranger, 'AJENO');

    $names = collect($this->getJson('/api/details', $headers)->json('data'))->pluck('name');

    expect($names)->toContain('PROPIO');
    expect($names)->not->toContain('AJENO');
});

it('cuenta el total sobre los details del usuario, no sobre la pagina', function () {
    [$user, $headers] = detailOwner();
    foreach (['UNO', 'DOS', 'TRES'] as $name) {
        detailWithMovement($user, $name);
    }

    $response = $this->getJson('/api/details?per_page=2&page=1', $headers);

    expect($response->json('total'))->toBe(3);
    expect($response->json('data'))->toHaveCount(2);
});

it('respeta la pagina pedida', function () {
    [$user, $headers] = detailOwner();
    foreach (['UNO', 'DOS', 'TRES'] as $name) {
        detailWithMovement($user, $name);
    }

    $first = collect($this->getJson('/api/details?per_page=2&page=1', $headers)->json('data'))->pluck('name');
    $second = collect($this->getJson('/api/details?per_page=2&page=2', $headers)->json('data'))->pluck('name');

    expect($second)->toHaveCount(1);
    expect($first->intersect($second))->toBeEmpty();
});

it('devuelve un total de cero cuando el usuario no tiene details', function () {
    [, $headers] = detailOwner();

    $response = $this->getJson('/api/details', $headers);

    $response->assertOk();
    expect($response->json('total'))->toBe(0);
    expect($response->json('data'))->toBe([]);
});

it('trae el nombre de la categoria mas usada del detail', function () {
    [$user, $headers] = detailOwner();
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Delivery']);
    detailWithMovement($user, 'RAPPI', $category->id);

    $row = collect($this->getJson('/api/details', $headers)->json('data'))
        ->firstWhere('name', 'RAPPI');

    expect($row['category_name'])->toBe('Delivery');
});

it('rechaza el listado sin autenticacion', function () {
    $this->getJson('/api/details')->assertStatus(401);
});
