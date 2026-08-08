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

use App\Jobs\GenerateEmbeddingForDetail;
use App\Models\CategorizationRule;
use App\Models\Category;
use App\Models\Detail;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

/**
 * @return array{0: User, 1: array<string, string>}
 */
function ruleOwner(): array
{
    /** @var \Tests\TestCase $t */
    return test()->userWithAuth();
}

/**
 * pgvector accepts its text form, which is the only way to seed one here: the factory
 * leaves `embedding` null and nothing else in the suite has ever written one.
 */
function embeddingOf(float $value): string
{
    return '['.implode(',', array_fill(0, 768, $value)).']';
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

it('no lista como regla propia la regla de otro usuario sobre la misma categoria', function () {
    [$user, $headers] = ruleOwner();
    $stranger = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    $mine = Detail::factory()->create(['user_id' => $user->id, 'description' => 'PROPIO']);
    $theirs = Detail::factory()->create(['user_id' => $stranger->id, 'description' => 'AJENO']);
    ruleFor($user, $category, $mine);
    ruleFor($stranger, $category, $theirs);

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

it('devuelve vacio cuando la categoria no tiene ningun detail con embedding', function () {
    [$user, $headers] = ruleOwner();
    $category = Category::factory()->create(['user_id' => $user->id]);

    $response = $this->getJson("/api/categories/{$category->id}/suggestions", $headers);

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

it('sugiere details sin categorizar y excluye los que ya son regla', function () {
    [$user, $headers] = ruleOwner();
    $category = Category::factory()->create(['user_id' => $user->id]);

    // Gives the category a centroid: a Detail already assigned to it, with a vector.
    Detail::factory()->create([
        'user_id' => $user->id,
        'description' => 'ANCLA',
        'last_used_category_id' => $category->id,
        'embedding' => embeddingOf(0.10),
    ]);

    $candidate = Detail::factory()->create([
        'user_id' => $user->id,
        'description' => 'CANDIDATO',
        'last_used_category_id' => null,
        'embedding' => embeddingOf(0.11),
    ]);

    $alreadyRule = Detail::factory()->create([
        'user_id' => $user->id,
        'description' => 'YA_ES_REGLA',
        'last_used_category_id' => null,
        'embedding' => embeddingOf(0.12),
    ]);
    ruleFor($user, $category, $alreadyRule);

    $descriptions = collect(
        $this->getJson("/api/categories/{$category->id}/suggestions", $headers)->json('data')
    )->pluck('description');

    expect($descriptions)->toContain('CANDIDATO');
    expect($descriptions)->not->toContain('YA_ES_REGLA');
    expect($descriptions)->not->toContain('ANCLA');
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

it('encola el recalculo del embedding del detail sincronizado', function () {
    [$user, $headers] = ruleOwner();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $detail = Detail::factory()->create(['user_id' => $user->id]);

    $this->postJson("/api/categories/{$category->id}/sync", ['detail_id' => $detail->id], $headers)
        ->assertOk();

    Queue::assertPushed(GenerateEmbeddingForDetail::class);
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
