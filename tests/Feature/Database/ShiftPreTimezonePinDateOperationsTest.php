<?php

declare(strict_types=1);

use App\Enums\SourceType;
use App\Models\Detail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Se ejercita el archivo real de la migracion, igual que
 * `ChannelIdentityMigrationTest`: la correccion vive solo ahi.
 */
function timezonePinMigration(): object
{
    return require database_path('migrations/2026_10_10_100000_shift_date_operation_written_before_timezone_pin.php');
}

/**
 * Escribe una fila como la dejaba el importador antes del pin: la hora de Lima
 * leida como UTC, o sea cinco horas antes. `created_at` es naive y se fija a mano
 * porque es lo unico que distingue una fila afectada de una sana.
 */
function rowWrittenAt(User $user, string $source, string $dateOperationInLima, string $createdAt): int
{
    $detail = Detail::factory()->create(['user_id' => $user->id]);

    $id = DB::table('transactions')->insertGetId([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'amount' => '10.00',
        'type_transaction' => 'expense',
        'date_operation' => $dateOperationInLima.'-05',
        'source_type' => $source,
        'is_manual' => false,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    return (int) $id;
}

function limaTimeOf(int $id): string
{
    return (string) DB::table('transactions')
        ->where('id', $id)
        ->value(DB::raw("to_char(date_operation AT TIME ZONE 'America/Lima', 'YYYY-MM-DD HH24:MI:SS')"));
}

it('suma cinco horas a las filas importadas o tipeadas antes del pin', function (string $source) {
    $user = User::factory()->create();

    // El extracto dice 16/03 sin hora; quedo guardado como 15/03 19:00 en Lima.
    $id = rowWrittenAt($user, $source, '2026-03-15 19:00:00', '2026-03-20 10:00:00');

    timezonePinMigration()->up();

    expect(limaTimeOf($id))->toBe('2026-03-16 00:00:00');
})->with([
    SourceType::IMPORT_STATEMENT->value,
    SourceType::IMPORT_APP->value,
    SourceType::MANUAL->value,
]);

it('no toca las filas escritas despues del pin', function () {
    $user = User::factory()->create();

    $id = rowWrittenAt($user, SourceType::IMPORT_STATEMENT->value, '2026-08-20 00:00:00', '2026-08-06 00:00:00');

    timezonePinMigration()->up();

    expect(limaTimeOf($id))->toBe('2026-08-20 00:00:00');
});

it('no toca las capturas, que no existian antes del pin', function () {
    $user = User::factory()->create();

    $id = rowWrittenAt($user, SourceType::CAPTURE->value, '2026-03-15 13:45:00', '2026-03-20 10:00:00');

    timezonePinMigration()->up();

    expect(limaTimeOf($id))->toBe('2026-03-15 13:45:00');
});

it('no cambia created_at ni updated_at', function () {
    $user = User::factory()->create();

    $id = rowWrittenAt($user, SourceType::IMPORT_APP->value, '2026-03-15 19:00:00', '2026-03-20 10:00:00');

    timezonePinMigration()->up();

    $row = DB::table('transactions')->where('id', $id)->first(['created_at', 'updated_at']);

    expect((string) $row->created_at)->toBe('2026-03-20 10:00:00')
        ->and((string) $row->updated_at)->toBe('2026-03-20 10:00:00');
});

it('el down() devuelve cada fila a donde estaba', function () {
    $user = User::factory()->create();

    $afectada = rowWrittenAt($user, SourceType::IMPORT_APP->value, '2026-03-15 19:00:00', '2026-03-20 10:00:00');
    $sana = rowWrittenAt($user, SourceType::IMPORT_APP->value, '2026-08-20 14:59:00', '2026-08-20 15:00:00');

    $migration = timezonePinMigration();
    $migration->up();
    $migration->down();

    expect(limaTimeOf($afectada))->toBe('2026-03-15 19:00:00')
        ->and(limaTimeOf($sana))->toBe('2026-08-20 14:59:00');
});
