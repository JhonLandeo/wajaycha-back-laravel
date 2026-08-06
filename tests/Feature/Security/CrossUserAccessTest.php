<?php

/**
 * Every authenticated endpoint that takes a resource id must scope that lookup to the
 * caller. Before this suite existed, only TransactionsController::update() did, so any
 * authenticated user could read, modify or delete another user's records — including
 * downloading their uploaded bank statement — by iterating sequential ids.
 *
 * All of these assert 404 rather than 403 on purpose: a 403 confirms the id exists, which
 * hands an enumerator exactly the signal they are looking for. A caller must not be able
 * to tell "not yours" apart from "does not exist".
 */

use App\Models\Category;
use App\Models\Detail;
use App\Models\Import;
use App\Models\ParetoClassification;
use App\Models\Transaction;

/**
 * @return array{0: \App\Models\User, 1: array<string, string>}
 */
function ownerAndIntruder(): array
{
    /** @var \Tests\TestCase $t */
    $t = test();
    $owner = $t->createUserWithCategories();
    $intruder = $t->createUserWithCategories();

    return [$owner, $t->actingAsJwtUser($intruder)];
}

// ---------------------------------------------------------------- transactions

it('no permite ver la transacción de otro usuario', function () {
    [$owner, $headers] = ownerAndIntruder();
    $detail = Detail::factory()->create(['user_id' => $owner->id]);
    $transaction = Transaction::factory()->create([
        'user_id' => $owner->id,
        'detail_id' => $detail->id,
    ]);

    $this->getJson("/api/transactions/{$transaction->id}", $headers)->assertStatus(404);
});

it('no permite eliminar la transacción de otro usuario', function () {
    [$owner, $headers] = ownerAndIntruder();
    $detail = Detail::factory()->create(['user_id' => $owner->id]);
    $transaction = Transaction::factory()->create([
        'user_id' => $owner->id,
        'detail_id' => $detail->id,
        'is_manual' => true,
    ]);

    $this->deleteJson("/api/transactions/{$transaction->id}", [], $headers)->assertStatus(404);

    $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
});

// ------------------------------------------------------------------ categories

it('no permite ver la categoría de otro usuario', function () {
    [$owner, $headers] = ownerAndIntruder();
    $category = Category::factory()->create(['user_id' => $owner->id]);

    $this->getJson("/api/categories/{$category->id}", $headers)->assertStatus(404);
});

it('no permite actualizar la categoría de otro usuario', function () {
    [$owner, $headers] = ownerAndIntruder();
    $category = Category::factory()->create(['user_id' => $owner->id, 'name' => 'Original']);

    $this->putJson("/api/categories/{$category->id}", [
        'name' => 'Secuestrada',
        'monthly_budget' => 500,
        'type' => 'income',
    ], $headers)->assertStatus(404);

    $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Original']);
});

it('no permite eliminar la categoría de otro usuario', function () {
    [$owner, $headers] = ownerAndIntruder();
    $category = Category::factory()->create(['user_id' => $owner->id]);

    $this->deleteJson("/api/categories/{$category->id}", [], $headers)->assertStatus(404);

    $this->assertDatabaseHas('categories', ['id' => $category->id]);
});

// ------------------------------------------------------- pareto classifications

it('no permite ver la clasificación pareto de otro usuario', function () {
    [$owner, $headers] = ownerAndIntruder();
    $pareto = ParetoClassification::factory()->create(['user_id' => $owner->id]);

    $this->getJson("/api/pareto-classification/{$pareto->id}", $headers)->assertStatus(404);
});

it('no permite eliminar la clasificación pareto de otro usuario', function () {
    [$owner, $headers] = ownerAndIntruder();
    $pareto = ParetoClassification::factory()->create(['user_id' => $owner->id]);

    $this->deleteJson("/api/pareto-classification/{$pareto->id}", [], $headers)->assertStatus(404);

    $this->assertDatabaseHas('pareto_classifications', ['id' => $pareto->id]);
});

// --------------------------------------------------------------------- imports

it('no permite eliminar el import de otro usuario', function () {
    [$owner, $headers] = ownerAndIntruder();
    $import = Import::factory()->create(['user_id' => $owner->id]);

    $this->deleteJson("/api/imports/{$import->id}", [], $headers)->assertStatus(404);

    $this->assertDatabaseHas('imports', ['id' => $import->id]);
});

it('no permite actualizar el import de otro usuario', function () {
    [$owner, $headers] = ownerAndIntruder();
    $import = Import::factory()->create(['user_id' => $owner->id, 'name' => 'original.pdf']);

    $this->putJson("/api/imports/{$import->id}", ['name' => 'secuestrado.pdf'], $headers)
        ->assertStatus(404);

    $this->assertDatabaseHas('imports', ['id' => $import->id, 'name' => 'original.pdf']);
});

/**
 * The most severe of the set: this streams the raw file the owner uploaded — their bank
 * statement or Yape export — not a derived summary.
 */
it('no permite descargar el archivo importado de otro usuario', function () {
    [$owner, $headers] = ownerAndIntruder();
    $import = Import::factory()->create(['user_id' => $owner->id]);

    $this->getJson("/api/imports/{$import->id}/download", $headers)->assertStatus(404);
});

it('devuelve 404 al descargar un import inexistente en lugar de fallar', function () {
    [, $headers] = ownerAndIntruder();

    $this->getJson('/api/imports/999999/download', $headers)->assertStatus(404);
});

// ------------------------------------------------------------- owner still works

it('el dueño sigue accediendo a sus propios recursos', function () {
    $owner = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($owner);

    $category = Category::factory()->create(['user_id' => $owner->id]);
    $detail = Detail::factory()->create(['user_id' => $owner->id]);
    $transaction = Transaction::factory()->create([
        'user_id' => $owner->id,
        'detail_id' => $detail->id,
        'is_manual' => true,
    ]);

    $this->getJson("/api/categories/{$category->id}", $headers)->assertStatus(200);
    $this->getJson("/api/transactions/{$transaction->id}", $headers)->assertStatus(200);
});

// --------------------------------------- pareto categories listing

it('no permite listar las categorías de la clasificación pareto de otro usuario', function () {
    [$owner, $headers] = ownerAndIntruder();
    $pareto = ParetoClassification::factory()->create(['user_id' => $owner->id]);

    $this->getJson("/api/pareto-classification/{$pareto->id}/categories", $headers)
        ->assertStatus(404);
});

// ---------------------------------------------------------- details

it('no permite actualizar el detalle de otro usuario', function () {
    [$owner, $headers] = ownerAndIntruder();
    $detail = Detail::factory()->create(['user_id' => $owner->id, 'description' => 'Original']);

    $this->putJson("/api/details/{$detail->id}", ['description' => 'Secuestrado'], $headers)
        ->assertStatus(404);

    $this->assertDatabaseHas('details', ['id' => $detail->id, 'description' => 'Original']);
});

it('no permite apuntar un detalle propio a la categoría de otro usuario', function () {
    $owner = $this->createUserWithCategories();
    $intruder = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($intruder);

    $foreignCategory = Category::factory()->create(['user_id' => $owner->id]);
    $ownDetail = Detail::factory()->create(['user_id' => $intruder->id]);

    $this->putJson("/api/details/{$ownDetail->id}", [
        'last_used_category_id' => $foreignCategory->id,
    ], $headers)->assertStatus(422);
});

// ---------------------------------------------------- category rules

it('no permite ver las reglas de la categoría de otro usuario', function () {
    [$owner, $headers] = ownerAndIntruder();
    $category = Category::factory()->create(['user_id' => $owner->id]);

    $this->getJson("/api/categories/{$category->id}/rules", $headers)->assertStatus(404);
});

it('no permite sincronizar una regla sobre la categoría de otro usuario', function () {
    $owner = $this->createUserWithCategories();
    $intruder = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($intruder);

    $foreignCategory = Category::factory()->create(['user_id' => $owner->id]);
    $ownDetail = Detail::factory()->create(['user_id' => $intruder->id]);

    $this->postJson("/api/categories/{$foreignCategory->id}/sync", [
        'detail_id' => $ownDetail->id,
    ], $headers)->assertStatus(404);

    $this->assertDatabaseMissing('categorization_rules', [
        'category_id' => $foreignCategory->id,
    ]);
});

it('no permite sincronizar el detalle de otro usuario en una regla propia', function () {
    $owner = $this->createUserWithCategories();
    $intruder = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($intruder);

    $ownCategory = Category::factory()->create(['user_id' => $intruder->id]);
    $foreignDetail = Detail::factory()->create(['user_id' => $owner->id]);

    $this->postJson("/api/categories/{$ownCategory->id}/sync", [
        'detail_id' => $foreignDetail->id,
    ], $headers)->assertStatus(422);
});

// ------------------------------------------------ owner happy paths
// The cross-user cases above prove access is refused. These prove the scoping
// did not also refuse the legitimate owner, which is the failure mode a
// security fix most easily introduces and least easily notices.

it('el dueño puede actualizar su propia clasificación pareto', function () {
    $owner = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($owner);
    $pareto = ParetoClassification::factory()->create(['user_id' => $owner->id]);

    $this->putJson("/api/pareto-classification/{$pareto->id}", [
        'name' => 'Renombrada',
        'percentage' => 30,
    ], $headers)->assertSuccessful();
});

it('el dueño puede actualizar su propia categoría', function () {
    $owner = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($owner);
    $category = Category::factory()->create(['user_id' => $owner->id]);

    $this->putJson("/api/categories/{$category->id}", [
        'name' => 'Renombrada',
        'monthly_budget' => 300,
        'type' => 'income',
    ], $headers)->assertSuccessful();

    $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Renombrada']);
});

it('el dueño puede actualizar y eliminar su propio import', function () {
    $owner = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($owner);
    $import = Import::factory()->create(['user_id' => $owner->id]);

    $this->putJson("/api/imports/{$import->id}", ['name' => 'renombrado.pdf'], $headers)
        ->assertSuccessful();

    $this->deleteJson("/api/imports/{$import->id}", [], $headers)->assertSuccessful();

    $this->assertDatabaseMissing('imports', ['id' => $import->id]);
});

it('el dueño puede listar las categorías de su clasificación pareto', function () {
    $owner = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($owner);
    $pareto = ParetoClassification::factory()->create(['user_id' => $owner->id]);

    $this->getJson("/api/pareto-classification/{$pareto->id}/categories", $headers)
        ->assertStatus(200);
});
