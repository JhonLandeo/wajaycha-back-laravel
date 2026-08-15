<?php

declare(strict_types=1);

/**
 * `Transaction::category()` was declared
 * `hasOneThrough(Category::class, Category::class)` — Category named as the
 * final model and as the intermediate one at the same time. Eloquent built that
 * literally: `categories` joined to `categories` under one name, filtered on a
 * `categories.transaction_id` column that has never existed.
 *
 * Reading the relation therefore raised
 * `SQLSTATE[42712] Duplicate alias: table name "categories" specified more than
 * once`. Not null, not an empty result — a hard error on every single access.
 *
 * Which is exactly why nothing was broken by it: a relation that throws on
 * every access cannot be in use anywhere, and the reports read category data
 * through the PostgreSQL functions instead. The defect was survivable because
 * it was total, and it stayed invisible for the same reason. These cases exist
 * so the relation is now something the suite would notice.
 */

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('trae la categoria de la transaccion', function () {
    $user = User::factory()->create();
    $categoria = Category::factory()->create(['user_id' => $user->id, 'name' => 'Transporte']);

    $transaccion = Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $categoria->id,
    ]);

    expect($transaccion->category)->not->toBeNull()
        ->and($transaccion->category->id)->toBe($categoria->id)
        ->and($transaccion->category->name)->toBe('Transporte');
});

it('devuelve null cuando la transaccion no tiene categoria', function () {
    $user = User::factory()->create();

    $transaccion = Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => null,
    ]);

    // `category_id` es anulable: una transaccion sin categorizar es un estado
    // normal del sistema, no un error. Tiene que dar null y no explotar.
    expect($transaccion->category)->toBeNull();
});

it('es un belongsTo sobre category_id, no una relacion a traves de nada', function () {
    $relacion = (new Transaction)->category();

    expect($relacion)->toBeInstanceOf(BelongsTo::class)
        ->and($relacion->getForeignKeyName())->toBe('category_id');
});

it('se puede cargar por anticipado sin una consulta por fila', function () {
    $user = User::factory()->create();
    $categoria = Category::factory()->create(['user_id' => $user->id]);

    Transaction::factory()->count(3)->create([
        'user_id' => $user->id,
        'category_id' => $categoria->id,
    ]);

    DB::enableQueryLog();

    $transacciones = Transaction::where('user_id', $user->id)->with('category')->get();

    $consultas = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Este es el motivo por el que la relacion existe. Dos consultas: las
    // transacciones y sus categorias. Sin `with()` serian cuatro, y con la
    // declaracion vieja no era ninguna — era una excepcion.
    expect($consultas)->toBe(2)
        ->and($transacciones)->toHaveCount(3)
        ->and($transacciones->first()->relationLoaded('category'))->toBeTrue()
        ->and($transacciones->first()->category->id)->toBe($categoria->id);
});

it('no se cruza con la categoria de otro usuario', function () {
    $mia = User::factory()->create();
    $ajena = User::factory()->create();

    $categoriaAjena = Category::factory()->create(['user_id' => $ajena->id]);
    $categoriaMia = Category::factory()->create(['user_id' => $mia->id]);

    $transaccion = Transaction::factory()->create([
        'user_id' => $mia->id,
        'category_id' => $categoriaMia->id,
    ]);

    expect($transaccion->category->id)->toBe($categoriaMia->id)
        ->and($transaccion->category->id)->not->toBe($categoriaAjena->id);
});

it('sigue funcionando la relacion inversa desde la categoria', function () {
    $user = User::factory()->create();
    $categoria = Category::factory()->create(['user_id' => $user->id]);

    Transaction::factory()->count(2)->create([
        'user_id' => $user->id,
        'category_id' => $categoria->id,
    ]);

    // `Category::transactions()` ya existia y estaba bien. Lo que faltaba era su
    // contraparte, que es lo que se arreglo.
    expect($categoria->transactions)->toHaveCount(2);
});
