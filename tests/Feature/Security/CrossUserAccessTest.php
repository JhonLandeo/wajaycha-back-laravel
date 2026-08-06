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
