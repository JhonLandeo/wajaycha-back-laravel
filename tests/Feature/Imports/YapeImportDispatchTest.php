<?php

declare(strict_types=1);

/**
 * The Yape import endpoint had no test at all. These cover it, and one of them
 * covers a live defect rather than existing behaviour.
 *
 * `ProcessExcelImport` was dispatched between `DB::beginTransaction()` and
 * `DB::commit()`. Every queue connection in `config/queue.php` carries
 * `'after_commit' => false`, and production runs Redis, so a worker can pick the job
 * up before the transaction commits. The job's first statement is
 *
 *     Import::where('id', $this->importId)->update(['status' => PROCESSING]);
 *
 * — an update with a where clause. Against a row that has not committed yet it
 * matches nothing and returns quietly. No exception, no log. The import sits at its
 * initial status forever and the user watches a file that never processes.
 *
 * The suite could never have caught it: `phpunit.xml` pins `QUEUE_CONNECTION=sync`,
 * so the job runs inline on the same connection and reads its own transaction's
 * uncommitted row. Green here, broken in production — which is the whole reason the
 * dispatch contract is asserted directly rather than inferred from an outcome.
 */

use App\Enums\ImportStatus;
use App\Jobs\ProcessExcelImport;
use App\Models\Import;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();

    // The controller hardcodes `financial_entity_id = 1` and `payment_service_id = 1`.
    // In production those are whichever rows the seeders insert first; here the ids
    // have to be pinned explicitly, and the reason is worth recording.
    //
    // Running the seeders was tried first and failed from the second test onward:
    // PostgreSQL sequences do not roll back. `RefreshDatabase` wraps each test in a
    // transaction, so the rows disappear but the id counter keeps climbing — test one
    // gets id 1, test two gets id 3, and a constant that says 1 stops pointing at
    // anything. The constants only hold while the sequence happens to agree with them.
    DB::table('financial_entities')->insert([
        'id' => 1, 'name' => 'Banco de Crédito del Perú', 'type' => 'Banco', 'code' => 'BCP',
    ]);
    DB::table('payment_services')->insert([
        'id' => 1, 'name' => 'Yape', 'financial_entity_id' => 1, 'type' => 'Billetera Digital',
    ]);
});

/**
 * @return array{0: \App\Models\User, 1: array<string, string>}
 */
function yapeUploader(): array
{
    /** @var \Tests\TestCase $t */
    return test()->userWithAuth();
}

function yapeFile(): UploadedFile
{
    return UploadedFile::fake()->create('movimientos-yape.xlsx', 12);
}

// ------------------------------------------------------- the defect under test

it('marca el job para despacharse recien despues del commit', function () {
    [, $headers] = yapeUploader();

    $this->postJson('/api/import-yape', ['file' => yapeFile()], $headers)->assertOk();

    Queue::assertPushed(
        ProcessExcelImport::class,
        fn (ProcessExcelImport $job): bool => $job->afterCommit === true
    );
});

/**
 * `Storage::mimeType()` takes a path on the disk. It was being handed the
 * `UploadedFile` itself, so it resolved nothing and returned `false`, which Eloquent
 * wrote into a `string` column as `0`. Every Yape import ever recorded carries a
 * meaningless mime.
 *
 * Asserted loosely on purpose: the point is that a real media type was recorded, not
 * which one a fake xlsx happens to produce.
 */
it('guarda el mime real del archivo, no un false convertido', function () {
    [$user, $headers] = yapeUploader();

    $this->postJson('/api/import-yape', ['file' => yapeFile()], $headers)->assertOk();

    $mime = Import::query()->where('user_id', $user->id)->sole()->mime;

    expect($mime)->toBeString();
    expect($mime)->toContain('/');
});

// ------------------------------------------------------------ behaviour cover

it('registra el import y encola su procesamiento', function () {
    [$user, $headers] = yapeUploader();

    $this->postJson('/api/import-yape', ['file' => yapeFile()], $headers)
        ->assertOk()
        ->assertJson(['status' => 'ok']);

    $import = Import::query()->where('user_id', $user->id)->sole();

    expect($import->name)->toBe('movimientos-yape.xlsx');
    expect($import->extension)->toBe('xlsx');

    Queue::assertPushed(ProcessExcelImport::class);
});

it('atribuye el import al usuario autenticado, no a un id del payload', function () {
    [$user, $headers] = yapeUploader();
    $stranger = \App\Models\User::factory()->create();

    $this->postJson(
        '/api/import-yape',
        ['file' => yapeFile(), 'user_id' => $stranger->id],
        $headers
    )->assertOk();

    expect(Import::query()->where('user_id', $user->id)->count())->toBe(1);
    expect(Import::query()->where('user_id', $stranger->id)->count())->toBe(0);
});

it('deja el import en estado inicial hasta que el worker lo tome', function () {
    [$user, $headers] = yapeUploader();

    $this->postJson('/api/import-yape', ['file' => yapeFile()], $headers)->assertOk();

    expect(Import::query()->where('user_id', $user->id)->sole()->status)
        ->toBe(ImportStatus::PENDING);
});

it('rechaza la importacion sin autenticacion', function () {
    $this->postJson('/api/import-yape', ['file' => yapeFile()])->assertStatus(401);
});

it('rechaza la importacion sin archivo', function () {
    [, $headers] = yapeUploader();

    $this->postJson('/api/import-yape', [], $headers)->assertStatus(422);
});
