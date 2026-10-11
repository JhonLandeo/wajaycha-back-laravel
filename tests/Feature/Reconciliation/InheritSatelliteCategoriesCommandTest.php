<?php

declare(strict_types=1);

use App\Enums\SourceType;
use App\Models\Category;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

/**
 * Una fila tal como quedo antes del arreglo del linker: el enlace se escribe
 * directo, sin pasar por `link()`, asi que la categoria nunca viajo.
 */
function inheritRow(User $user, SourceType $source, ?Category $category = null, ?Transaction $master = null): Transaction
{
    return Transaction::create([
        'user_id' => $user->id,
        'detail_id' => Detail::factory()->create(['user_id' => $user->id])->id,
        'amount' => '8.00',
        'type_transaction' => 'expense',
        'date_operation' => '2026-09-27 13:09:59',
        'source_type' => $source->value,
        'category_id' => $category?->id,
        'matched_transaction_id' => $master?->id,
    ]);
}

const INHERIT_COMMAND = 'transactions:inherit-satellite-categories';

it('informa la categoria que heredaria el maestro sin escribir nada mientras no se pase --apply', function () {
    $user = User::factory()->create();
    $comida = Category::factory()->create(['user_id' => $user->id, 'name' => 'Comida']);

    $extracto = inheritRow($user, SourceType::IMPORT_STATEMENT);
    inheritRow($user, SourceType::IMPORT_APP, $comida, $extracto);

    Artisan::call(INHERIT_COMMAND);
    $output = Artisan::output();

    expect($output)->toContain('Simulación')
        ->and($output)->toContain((string) $extracto->id)
        ->and($output)->toContain('Comida')
        ->and($extracto->fresh()->category_id)->toBeNull();
});

it('pasa con --apply la categoria del satelite al maestro sin categoria', function () {
    $user = User::factory()->create();
    $comida = Category::factory()->create(['user_id' => $user->id]);
    $transporte = Category::factory()->create(['user_id' => $user->id]);

    $extracto = inheritRow($user, SourceType::IMPORT_STATEMENT);
    inheritRow($user, SourceType::IMPORT_APP, $comida, $extracto);

    // Un maestro que ya tiene categoria no se toca.
    $otroExtracto = inheritRow($user, SourceType::IMPORT_STATEMENT, $transporte);
    inheritRow($user, SourceType::IMPORT_APP, $comida, $otroExtracto);

    $this->artisan(INHERIT_COMMAND.' --apply')->assertSuccessful();

    expect($extracto->fresh()->category_id)->toBe($comida->id)
        ->and($otroExtracto->fresh()->category_id)->toBe($transporte->id);
});

it('no adivina cuando los satelites de un maestro traen categorias distintas', function () {
    $user = User::factory()->create();
    $comida = Category::factory()->create(['user_id' => $user->id]);
    $transporte = Category::factory()->create(['user_id' => $user->id]);

    $extracto = inheritRow($user, SourceType::IMPORT_STATEMENT);
    inheritRow($user, SourceType::IMPORT_APP, $comida, $extracto);
    inheritRow($user, SourceType::CAPTURE, $transporte, $extracto);

    Artisan::call(INHERIT_COMMAND.' --apply');
    $output = Artisan::output();

    expect($extracto->fresh()->category_id)->toBeNull()
        ->and($output)->toContain('conflicto')
        ->and($output)->toContain((string) $extracto->id);
});

it('llega hasta la fila visible cuando la categoria esta al fondo de una cadena', function () {
    $user = User::factory()->create();
    $comida = Category::factory()->create(['user_id' => $user->id]);

    // captura -> Excel -> extracto: solo la captura tiene categoria.
    $extracto = inheritRow($user, SourceType::IMPORT_STATEMENT);
    $excel = inheritRow($user, SourceType::IMPORT_APP, master: $extracto);
    inheritRow($user, SourceType::CAPTURE, $comida, $excel);

    $this->artisan(INHERIT_COMMAND.' --apply')->assertSuccessful();

    expect($extracto->fresh()->category_id)->toBe($comida->id)
        ->and($excel->fresh()->category_id)->toBe($comida->id);
});

it('limita la corrida a un usuario con --user', function () {
    $ana = User::factory()->create();
    $beto = User::factory()->create();
    $deAna = Category::factory()->create(['user_id' => $ana->id]);
    $deBeto = Category::factory()->create(['user_id' => $beto->id]);

    $extractoAna = inheritRow($ana, SourceType::IMPORT_STATEMENT);
    inheritRow($ana, SourceType::IMPORT_APP, $deAna, $extractoAna);
    $extractoBeto = inheritRow($beto, SourceType::IMPORT_STATEMENT);
    inheritRow($beto, SourceType::IMPORT_APP, $deBeto, $extractoBeto);

    $this->artisan(INHERIT_COMMAND." --apply --user={$ana->id}")->assertSuccessful();

    expect($extractoAna->fresh()->category_id)->toBe($deAna->id)
        ->and($extractoBeto->fresh()->category_id)->toBeNull();
});
