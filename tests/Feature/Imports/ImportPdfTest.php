<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Jobs\ProcessPdfImport;
use App\Models\FinancialEntity;
use App\Models\Import;

it('sube un PDF y crea un registro de Import con status pending', function () {
    Queue::fake();
    $user = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($user);

    $financial = \App\Models\FinancialEntity::factory()->create();
    // dd($financial->id);

    $file = UploadedFile::fake()->create('estado_2026_cuenta.pdf', 100, 'application/pdf');

    $response = $this->postJson('/api/imports', [
        'file' => $file,
        'financial' => $financial->id,
        'password' => 'secret123'
    ], $headers);

    $response->assertStatus(200); // the controller returns 200 with status => ok, not 201
    $this->assertDatabaseHas('imports', [
        'user_id' => $user->id,
        'status' => 'pending'
    ]);
    Queue::assertPushed(ProcessPdfImport::class);
});

it('despacha ProcessPdfImport al subir un archivo', function () {
    Queue::fake();
    Storage::fake('local');

    $user = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($user);

    $financial = \App\Models\FinancialEntity::factory()->create();

    $file = UploadedFile::fake()->create('estado_2026_cuenta.pdf', 100, 'application/pdf');

    $this->postJson('/api/imports', [
        'file' => $file,
        'financial' => $financial->id,
        'password' => 'secret123'
    ], $headers);

    Queue::assertPushed(ProcessPdfImport::class);
});

it('puede listar los imports del usuario autenticado', function () {
    $user = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($user);

    // 1. Crear la entidad financiera necesaria
    $entity = \App\Models\FinancialEntity::factory()->create();

    // 2. Pasar el ID al factory del Import
    Import::factory()->create([
        'user_id' => $user->id,
        'financial_entity_id' => $entity->id // <--- Aquí está el arreglo
    ]);

    $response = $this->getJson('/api/imports', $headers);

    $response->assertStatus(200);
});

it('puede descargar el archivo de un import', function () {
    $user = $this->createUserWithCategories();
    $headers = $this->actingAsJwtUser($user);
    $entity = FinancialEntity::factory()->create();
    // dd($entity);

    $import = Import::factory()->create([
        'user_id' => $user->id,
        'financial_entity_id' => $entity->id,
        'path' => 'imports/test.pdf'
    ]);

    // This may need to be adjusted depending on how download route is structured
    $response = $this->getJson("/api/imports/{$import->id}/download", $headers);

    // Depending on if the file actually needs to exist via Storage::fake 
    // We just check if it routes and returns a response, or stub the storage.
    // Assuming 500 if file doesn't exist, so let's mock the file.
    Storage::fake('local');
    Storage::disk('local')->put('imports/test.pdf', 'dummy content');

    $response = $this->getJson("/api/imports/{$import->id}/download", $headers);
    $response->assertStatus(200);
});
