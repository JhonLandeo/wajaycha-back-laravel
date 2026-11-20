<?php

declare(strict_types=1);

/**
 * `CategoryRuleController` had no tests at all.
 *
 * It is not an incidental gap. These three endpoints read and write the rules that
 * decide how a movement gets categorised, which is the Core Domain — the subdomain the
 * context map calls "what the user is actually buying" — and the one
 * [QA-2 exactitud de la categorización](docs/quality/quality-attributes.md) measures.
 *
 * The suite that exists covers whether a caller can reach another user's rows. Nothing
 * covered whether the endpoints do what they claim.
 */

use App\Models\CategorizationRule;
use App\Models\Category;
use App\Models\Detail;
use App\Models\User;

/**
 * @return array{0: User, 1: array<string, string>}
 */
function ruleOwner(): array
{
    /** @var \Tests\TestCase $t */
    return test()->userWithAuth();
}

function ruleFor(User $user, Category $category, Detail $detail): CategorizationRule
{
    return CategorizationRule::create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'category_id' => $category->id,
    ]);
}

// ------------------------------------------------------------------ getRules

it('lista los details que ya son regla de la categoria', function () {
    [$user, $headers] = ruleOwner();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $detail = Detail::factory()->create(['user_id' => $user->id, 'description' => 'RAPPI']);
    ruleFor($user, $category, $detail);

    $response = $this->getJson("/api/categories/{$category->id}/rules", $headers);

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('description'))->toContain('RAPPI');
});

/**
 * La premisa cambió: una regla de otro usuario SOBRE LA MISMA categoría ya no
 * se puede construir — `fk_categorization_rules_category_id` exige que la
 * categoría sea del dueño de la regla, y `categories` lleva `user_id`. Ese
 * cruce lo garantiza ahora la base.
 *
 * Lo que sigue teniendo filo, y es lo que se prueba acá, es que el endpoint no
 * filtre sólo por categoría: si la consulta uniera `details` sin acotar por
 * usuario, el comercio del extraño aparecería igual.
 */
it('no lista el comercio de otro usuario entre las reglas de la categoria', function () {
    [$user, $headers] = ruleOwner();
    $stranger = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $strangersCategory = Category::factory()->create(['user_id' => $stranger->id]);

    $mine = Detail::factory()->create(['user_id' => $user->id, 'description' => 'PROPIO']);
    $theirs = Detail::factory()->create(['user_id' => $stranger->id, 'description' => 'AJENO']);
    ruleFor($user, $category, $mine);
    ruleFor($stranger, $strangersCategory, $theirs);

    $descriptions = collect($this->getJson("/api/categories/{$category->id}/rules", $headers)->json('data'))
        ->pluck('description');

    expect($descriptions)->toContain('PROPIO');
    expect($descriptions)->not->toContain('AJENO');
});

it('responde 404 al pedir las reglas de una categoria ajena', function () {
    [, $headers] = ruleOwner();
    $stranger = User::factory()->create();
    $foreign = Category::factory()->create(['user_id' => $stranger->id]);

    $this->getJson("/api/categories/{$foreign->id}/rules", $headers)->assertStatus(404);
});

// ------------------------------------------------------------- getSuggestions

it('devuelve vacio cuando la categoria no tiene ningun detail asignado', function () {
    [$user, $headers] = ruleOwner();
    $category = Category::factory()->create(['user_id' => $user->id]);

    $response = $this->getJson("/api/categories/{$category->id}/suggestions", $headers);

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

it('sugiere details parecidos por trigramas y excluye los que ya son regla', function () {
    [$user, $headers] = ruleOwner();
    $category = Category::factory()->create(['user_id' => $user->id]);

    // El ancla define la forma de la categoria: un comercio ya asignado.
    Detail::factory()->create([
        'user_id' => $user->id,
        'description' => 'ANCLA',
        'entity_clean' => 'bodega el chino',
        'last_used_category_id' => $category->id,
    ]);

    Detail::factory()->create([
        'user_id' => $user->id,
        'description' => 'CANDIDATO',
        'entity_clean' => 'bodega el chino sac',
        'last_used_category_id' => null,
    ]);

    Detail::factory()->create([
        'user_id' => $user->id,
        'description' => 'MUY_DISTINTO',
        'entity_clean' => 'zapateria central',
        'last_used_category_id' => null,
    ]);

    $alreadyRule = Detail::factory()->create([
        'user_id' => $user->id,
        'description' => 'YA_ES_REGLA',
        'entity_clean' => 'bodega el chino express',
        'last_used_category_id' => null,
    ]);
    ruleFor($user, $category, $alreadyRule);

    $descriptions = collect(
        $this->getJson("/api/categories/{$category->id}/suggestions", $headers)->json('data')
    )->pluck('description');

    expect($descriptions)->toContain('CANDIDATO');
    expect($descriptions)->not->toContain('YA_ES_REGLA');
    expect($descriptions)->not->toContain('ANCLA');
    expect($descriptions)->not->toContain('MUY_DISTINTO');
});

it('responde 404 al pedir sugerencias de una categoria ajena', function () {
    [, $headers] = ruleOwner();
    $stranger = User::factory()->create();
    $foreign = Category::factory()->create(['user_id' => $stranger->id]);

    $this->getJson("/api/categories/{$foreign->id}/suggestions", $headers)->assertStatus(404);
});

// ------------------------------------------------------------------ syncRule

it('crea la regla que asocia un detail con la categoria', function () {
    [$user, $headers] = ruleOwner();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $detail = Detail::factory()->create(['user_id' => $user->id]);

    $this->postJson("/api/categories/{$category->id}/sync", ['detail_id' => $detail->id], $headers)
        ->assertOk()
        ->assertJson(['status' => 'ok']);

    expect(CategorizationRule::query()
        ->where('user_id', $user->id)
        ->where('detail_id', $detail->id)
        ->where('category_id', $category->id)
        ->exists())->toBeTrue();
});

it('reapunta la regla existente en lugar de duplicarla', function () {
    [$user, $headers] = ruleOwner();
    $first = Category::factory()->create(['user_id' => $user->id]);
    $second = Category::factory()->create(['user_id' => $user->id]);
    $detail = Detail::factory()->create(['user_id' => $user->id]);

    ruleFor($user, $first, $detail);

    $this->postJson("/api/categories/{$second->id}/sync", ['detail_id' => $detail->id], $headers)
        ->assertOk();

    $rules = CategorizationRule::query()
        ->where('user_id', $user->id)
        ->where('detail_id', $detail->id)
        ->get();

    expect($rules)->toHaveCount(1);
    expect((int) $rules->first()->category_id)->toBe($second->id);
});

it('recuerda la categoria del detail sincronizado', function () {
    [$user, $headers] = ruleOwner();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $detail = Detail::factory()->create(['user_id' => $user->id]);

    $this->postJson("/api/categories/{$category->id}/sync", ['detail_id' => $detail->id], $headers)
        ->assertOk();

    // La regla y la memoria del comercio se escriben juntas: la cascada por
    // trigramas y la pestaña de sugerencias leen `last_used_category_id`.
    expect($detail->fresh()->last_used_category_id)->toBe($category->id);
});

/**
 * The category is the caller's own, deliberately: pointing at someone else's category
 * would 404 in the controller before validation ever spoke, and the test would pass
 * while proving nothing about `detail_id`.
 *
 * `SyncRuleRequest` scopes it with `Rule::exists('details','id')->where('user_id', ...)`.
 * Without that scope a caller could attach another user's counterparty to their own
 * rule — and the controller's `Detail::find()` on the next line is unscoped, so the
 * FormRequest is the only thing standing there.
 */
it('rechaza sincronizar el detail de otro usuario', function () {
    [$user, $headers] = ruleOwner();
    $stranger = User::factory()->create();
    $ownCategory = Category::factory()->create(['user_id' => $user->id]);
    $foreignDetail = Detail::factory()->create(['user_id' => $stranger->id]);

    $this->postJson(
        "/api/categories/{$ownCategory->id}/sync",
        ['detail_id' => $foreignDetail->id],
        $headers
    )->assertStatus(422);

    expect(CategorizationRule::query()->where('detail_id', $foreignDetail->id)->exists())
        ->toBeFalse();
});

it('responde 404 al sincronizar contra una categoria ajena', function () {
    [$user, $headers] = ruleOwner();
    $stranger = User::factory()->create();
    $foreign = Category::factory()->create(['user_id' => $stranger->id]);
    $detail = Detail::factory()->create(['user_id' => $user->id]);

    $this->postJson("/api/categories/{$foreign->id}/sync", ['detail_id' => $detail->id], $headers)
        ->assertStatus(404);
});

// ------------------------------------------------------------------- guarding

it('rechaza cada endpoint de reglas sin autenticacion', function () {
    $category = Category::factory()->create();

    $this->getJson("/api/categories/{$category->id}/rules")->assertStatus(401);
    $this->getJson("/api/categories/{$category->id}/suggestions")->assertStatus(401);
    $this->postJson("/api/categories/{$category->id}/sync", ['detail_id' => 1])->assertStatus(401);
});
