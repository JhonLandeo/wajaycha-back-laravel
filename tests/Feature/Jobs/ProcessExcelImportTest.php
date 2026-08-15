<?php

declare(strict_types=1);

/**
 * `ProcessExcelImport` is thin — it moves an `Import` through its states around
 * a call to `Excel::import`. What it owns is exactly that state machine, and the
 * failure path is the half that matters: the job swallows the exception, so the
 * only trace a user ever sees is `status` and `error_message` on the row.
 *
 * `TransactionYapeImport`, the mapper it hands to Excel, is covered separately
 * in tests/Feature/Imports/YapeRowMappingTest.php.
 */

use App\Enums\ImportStatus;
use App\Jobs\ProcessExcelImport;
use App\Models\FinancialEntity;
use App\Models\Import;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

function pendingImport(User $user): Import
{
    return Import::factory()->create([
        'user_id' => $user->id,
        'financial_entity_id' => FinancialEntity::factory()->create()->id,
        'status' => ImportStatus::PENDING,
    ]);
}

/**
 * Asserted from inside the `Excel::import` call, because after `handle()` the
 * row already says COMPLETED and the intermediate state is invisible. Without
 * this the whole PROCESSING transition could be deleted and every other test
 * here would stay green — mutation testing removed the line and nothing failed.
 */
it('marca el import como procesando antes de leer el archivo', function () {
    $user = User::factory()->create();
    $import = pendingImport($user);
    $statusWhileImporting = null;

    Excel::shouldReceive('import')->once()->andReturnUsing(
        function () use ($import, &$statusWhileImporting) {
            $statusWhileImporting = $import->fresh()->status;

            return null;
        }
    );

    (new ProcessExcelImport($import->id, $user->id, 'files/movimientos.xlsx'))->handle();

    expect($statusWhileImporting)->toBe(ImportStatus::PROCESSING);
});

/**
 * The job swallows the exception, so this log line is the only diagnostic that
 * survives a failed import. Both halves are asserted, and in order: mutation
 * happily removed either side of the concatenation, or swapped them, while the
 * suite stayed green.
 */
it('registra el error con el usuario y el motivo, en ese orden', function () {
    Log::spy();
    Excel::shouldReceive('import')->once()->andThrow(new RuntimeException('archivo corrupto'));

    $user = User::factory()->create();
    $import = pendingImport($user);

    (new ProcessExcelImport($import->id, $user->id, 'files/movimientos.xlsx'))->handle();

    Log::shouldHaveReceived('error')->withArgs(function (string $message) use ($user): bool {
        $userPosition = strpos($message, (string) $user->id);
        $reasonPosition = strpos($message, 'archivo corrupto');

        return $userPosition !== false
            && $reasonPosition !== false
            && $userPosition < $reasonPosition;
    });
});

it('deja el import en completado cuando la importacion no falla', function () {
    Excel::fake();
    $user = User::factory()->create();
    $import = pendingImport($user);

    (new ProcessExcelImport($import->id, $user->id, 'files/movimientos.xlsx'))->handle();

    expect($import->fresh()->status)->toBe(ImportStatus::COMPLETED);
});

it('no deja rastro de error cuando la importacion no falla', function () {
    Excel::fake();
    $user = User::factory()->create();
    $import = pendingImport($user);

    (new ProcessExcelImport($import->id, $user->id, 'files/movimientos.xlsx'))->handle();

    expect($import->fresh()->error_message)->toBeNull();
});

it('marca el import como fallido cuando la importacion revienta', function () {
    Excel::shouldReceive('import')->once()->andThrow(new RuntimeException('archivo corrupto'));

    $user = User::factory()->create();
    $import = pendingImport($user);

    (new ProcessExcelImport($import->id, $user->id, 'files/movimientos.xlsx'))->handle();

    expect($import->fresh()->status)->toBe(ImportStatus::FAILED);
});

it('guarda el mensaje del error para que el usuario vea algo', function () {
    Excel::shouldReceive('import')->once()->andThrow(new RuntimeException('archivo corrupto'));

    $user = User::factory()->create();
    $import = pendingImport($user);

    (new ProcessExcelImport($import->id, $user->id, 'files/movimientos.xlsx'))->handle();

    expect($import->fresh()->error_message)->toBe('archivo corrupto');
});

/**
 * The job catches everything, so the queue never retries and Horizon reports a
 * clean run. If the status were left at PROCESSING the import would look alive
 * forever — the same silent stall the dispatch test in
 * tests/Feature/Imports/YapeImportDispatchTest.php documents.
 */
it('nunca deja el import colgado en procesando', function () {
    Excel::shouldReceive('import')->once()->andThrow(new RuntimeException('boom'));

    $user = User::factory()->create();
    $import = pendingImport($user);

    (new ProcessExcelImport($import->id, $user->id, 'files/movimientos.xlsx'))->handle();

    expect($import->fresh()->status)->not->toBe(ImportStatus::PROCESSING);
});

it('no propaga la excepcion, la absorbe', function () {
    Excel::shouldReceive('import')->once()->andThrow(new RuntimeException('boom'));

    $user = User::factory()->create();
    $import = pendingImport($user);

    expect(fn () => (new ProcessExcelImport($import->id, $user->id, 'files/x.xlsx'))->handle())
        ->not->toThrow(RuntimeException::class);
});

it('solo toca el import que le corresponde', function () {
    Excel::fake();
    $user = User::factory()->create();
    $mine = pendingImport($user);
    $other = pendingImport($user);

    (new ProcessExcelImport($mine->id, $user->id, 'files/movimientos.xlsx'))->handle();

    expect($other->fresh()->status)->toBe(ImportStatus::PENDING);
});
