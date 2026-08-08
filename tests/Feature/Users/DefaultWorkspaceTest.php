<?php

declare(strict_types=1);

/**
 * What a new user is given on signup.
 *
 * `UserObserver::created()` seeds three Pareto classifications, a two-level category
 * tree and a set of tags — around sixty rows — and nothing covered any of it. Every
 * test in this suite that calls `User::factory()->create()` has been running it, and
 * none of them ever asked whether it worked.
 *
 * It matters more than a convenience: a category with no Pareto assignment is invisible
 * to the report the product is built around, and a user whose seeding failed halfway
 * gets an account that looks fine and reports nothing.
 */

use App\Models\Category;
use App\Models\ParetoClassification;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('le da al usuario nuevo las tres clasificaciones pareto', function () {
    $user = User::factory()->create();

    $classifications = ParetoClassification::query()
        ->where('user_id', $user->id)
        ->pluck('percentage', 'name');

    expect($classifications->keys()->sort()->values()->all())
        ->toBe(['Ahorro', 'Fijos', 'Variables']);
    expect((int) $classifications['Fijos'])->toBe(35);
    expect((int) $classifications['Variables'])->toBe(45);
    expect((int) $classifications['Ahorro'])->toBe(20);
});

it('suma cien por ciento entre las clasificaciones por defecto', function () {
    $user = User::factory()->create();

    $total = ParetoClassification::query()->where('user_id', $user->id)->sum('percentage');

    expect((int) $total)->toBe(100);
});

it('le da al usuario nuevo un arbol de categorias de dos niveles', function () {
    $user = User::factory()->create();

    $parents = Category::query()->where('user_id', $user->id)->whereNull('parent_id')->get();
    $children = Category::query()->where('user_id', $user->id)->whereNotNull('parent_id')->get();

    expect($parents)->not->toBeEmpty();
    expect($children)->not->toBeEmpty();

    // Every child hangs off a parent that belongs to the same user.
    $parentIds = $parents->pluck('id');
    expect($children->pluck('parent_id')->diff($parentIds))->toBeEmpty();
});

it('cubre los tres tipos de categoria', function () {
    $user = User::factory()->create();

    $types = Category::query()->where('user_id', $user->id)->pluck('type')->unique()->sort()->values();

    expect($types->all())->toBe(['expense', 'income', 'transfer']);
});

it('asigna una banda pareto a cada categoria de gasto', function () {
    $user = User::factory()->create();

    $unassigned = Category::query()
        ->where('user_id', $user->id)
        ->where('type', 'expense')
        ->whereNotIn('id', function ($q) {
            $q->select('category_id')->from('category_pareto_assignments');
        })
        ->pluck('name');

    expect($unassigned->all())->toBe([]);
});

/**
 * A domain rule that lived only inside the seed data until this test.
 *
 * A transfer counts toward Pareto when the money actually leaves — paying a credit
 * card or a loan's principal is a fixed obligation however it is typed, and saving
 * competes for the same money as everything else. A transfer does not count when the
 * money merely moves: between your own accounts, out on loan to a friend, or fronted
 * against a reimbursement. Counting those would inflate every reading the product
 * exists to give.
 *
 * Two earlier versions of this test were wrong before this one was right: first
 * asserting that every non-income category has a band, then that the whole hidden
 * transfer tree has none. The seed is more careful than either assumption.
 */
it('da banda pareto a la transferencia que si es gasto, y no a la que solo mueve plata', function () {
    $user = User::factory()->create();

    $banded = fn (string $like): bool => DB::table('category_pareto_assignments')
        ->whereIn('category_id', Category::query()
            ->where('user_id', $user->id)
            ->where('name', 'like', "%{$like}%")
            ->pluck('id'))
        ->exists();

    // The money leaves.
    expect($banded('Ahorro'))->toBeTrue();
    expect($banded('Tarjeta de Crédito'))->toBeTrue();
    expect($banded('Pago de Capital'))->toBeTrue();

    // The money only moves.
    expect($banded('Entre Cuentas Propias'))->toBeFalse();
    expect($banded('Préstamos (a terceros)'))->toBeFalse();
    expect($banded('Favores'))->toBeFalse();
});

it('no asigna banda pareto a las categorias de ingreso', function () {
    $user = User::factory()->create();

    $incomeIds = Category::query()
        ->where('user_id', $user->id)
        ->where('type', 'income')
        ->pluck('id');

    $assigned = DB::table('category_pareto_assignments')
        ->whereIn('category_id', $incomeIds)
        ->count();

    expect($assigned)->toBe(0);
});

it('deja una sola asignacion por categoria', function () {
    $user = User::factory()->create();

    $categoryIds = Category::query()->where('user_id', $user->id)->pluck('id');

    $duplicated = DB::table('category_pareto_assignments')
        ->whereIn('category_id', $categoryIds)
        ->select('category_id')
        ->groupBy('category_id')
        ->havingRaw('COUNT(*) > 1')
        ->get();

    expect($duplicated)->toBeEmpty();
});

it('le da al usuario nuevo sus etiquetas por defecto', function () {
    $user = User::factory()->create();

    $tags = Tag::query()->where('user_id', $user->id)->pluck('name');

    expect($tags)->toContain('Pareja');
    expect($tags)->toContain('Gasto Hormiga');
    expect($tags->count())->toBeGreaterThan(5);
});

it('no mezcla lo sembrado entre dos usuarios', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $firstCategories = Category::query()->where('user_id', $first->id)->count();
    $secondCategories = Category::query()->where('user_id', $second->id)->count();

    expect($firstCategories)->toBe($secondCategories);
    expect($firstCategories)->toBeGreaterThan(0);

    expect(Category::query()->where('user_id', $first->id)->pluck('id')
        ->intersect(Category::query()->where('user_id', $second->id)->pluck('id')))
        ->toBeEmpty();
});
