<?php

use App\Actions\WhatsApp\RegisterWhatsAppTransactionAction;
use App\DTOs\WhatsApp\ParsedReceiptDTO;
use App\Models\Detail;
use App\Models\User;
use App\Services\CategorizationService;
use App\Services\TransactionAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('crea un nuevo detail si no existe coincidencia por trigrama', function () {
    $user = User::factory()->create();
    
    // Al crear el usuario se debió crear la categoría padre "Otros" si aplicará,
    // pero mockeamos los servicios para controlar el resultado sin depender de BD o Lógica AI profunda
    $analyzerMock = Mockery::mock(TransactionAnalyzer::class);
    $analyzerMock->shouldReceive('analyze')
        ->with('Tienda Local')
        ->andReturn(['entity' => 'tienda local', 'type' => 'expense']);

    $catServiceMock = Mockery::mock(CategorizationService::class);
    $catServiceMock->shouldReceive('findCategory')
        ->andReturn(1); // Categoria de prueba

    $action = new RegisterWhatsAppTransactionAction($analyzerMock, $catServiceMock);
    
    $dto = new ParsedReceiptDTO(
        isValid: true,
        amount: 25.50,
        destination: 'Tienda Local',
        origin: null,
        dateOperation: now()->toIso8601String(),
        type: 'expense',
        message: 'Compra rapida'
    );

    $transaction = $action->execute($user, $dto);

    // Assert que se creó el detalle
    expect(Detail::where('user_id', $user->id)->count())->toBe(1);
    
    $detail = Detail::first();
    expect($detail->description)->toBe('Tienda Local')
        ->and($detail->entity_clean)->toBe('tienda local');

    // Assert que se creó la transacción
    expect($transaction->amount)->toEqual(25.50)
        ->and($transaction->detail_id)->toBe($detail->id);
});

it('asigna la categoría correcta usando CategorizationService', function () {
    $user = User::factory()->create();
    
    $analyzerMock = Mockery::mock(TransactionAnalyzer::class);
    $analyzerMock->shouldReceive('analyze')->andReturn(['entity' => 'supermercado', 'type' => 'expense']);

    $catServiceMock = Mockery::mock(CategorizationService::class);
    // Controlamos el fake return categoryId
    $catServiceMock->shouldReceive('findCategory')->andReturn(999); 

    $action = new RegisterWhatsAppTransactionAction($analyzerMock, $catServiceMock);
    
    $dto = new ParsedReceiptDTO(true, 50.00, 'Supermercado', null, now()->toIso8601String(), 'expense', null);

    $transaction = $action->execute($user, $dto);

    expect($transaction->category_id)->toBe(999);
});

it('persiste la transacción con los datos del DTO', function () {
    $user = User::factory()->create();
    
    $analyzerMock = Mockery::mock(TransactionAnalyzer::class);
    $analyzerMock->shouldReceive('analyze')->andReturn(['entity' => 'trabajo', 'type' => 'income']);

    $catServiceMock = Mockery::mock(CategorizationService::class);
    $catServiceMock->shouldReceive('findCategory')->andReturn(1);

    $action = new RegisterWhatsAppTransactionAction($analyzerMock, $catServiceMock);
    
    $date = now()->subDay()->format('Y-m-d H:i:s');

    $dto = new ParsedReceiptDTO(
        isValid: true,
        amount: 1500.00,
        destination: null,
        origin: 'Trabajo',
        dateOperation: $date,
        type: 'income',
        message: 'Pago de nómina'
    );

    $transaction = $action->execute($user, $dto);

    expect($transaction->amount)->toEqual(1500.00)
        ->and($transaction->type_transaction)->toBe('income')
        ->and($transaction->date_operation->format('Y-m-d H:i:s'))->toBe($date)
        ->and($transaction->message)->toBe('Pago de nómina')
        ->and($transaction->is_manual)->toBeTrue();
});
