<?php

use App\Actions\WhatsApp\RegisterWhatsAppTransactionAction;
use App\DTOs\WhatsApp\ParsedReceiptDTO;
use App\Models\Category;
use App\Models\Detail;
use App\Models\User;
use App\Services\CategorizationService;
use App\Services\TransactionAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('crea un nuevo detail si no existe coincidencia por trigrama', function () {
    $user = User::factory()->create();
    // Crear una categoría real para el usuario
    $category = Category::factory()->create(['user_id' => $user->id]);

    $analyzerMock = Mockery::mock(TransactionAnalyzer::class);
    $analyzerMock->shouldReceive('analyze')
        ->with('Tienda Local')
        ->andReturn(['entity' => 'tienda local', 'type' => 'expense']);

    $catServiceMock = Mockery::mock(CategorizationService::class);
    $catServiceMock->shouldReceive('findCategory')
        ->andReturn($category->id);

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

    Category::factory()->create(['id' => 999, 'user_id' => $user->id]);

    $analyzerMock = Mockery::mock(TransactionAnalyzer::class);
    $analyzerMock->shouldReceive('analyze')->andReturn(['entity' => 'supermercado', 'type' => 'expense']);

    $catServiceMock = Mockery::mock(CategorizationService::class);
    $catServiceMock->shouldReceive('findCategory')->andReturn(999);

    $action = new RegisterWhatsAppTransactionAction($analyzerMock, $catServiceMock);
    $dto = new ParsedReceiptDTO(true, 50.00, 'Supermercado', null, now()->toIso8601String(), 'expense', null);

    $transaction = $action->execute($user, $dto);

    expect($transaction->category_id)->toBe(999);
});

it('persiste la transacción con los datos del DTO', function () {
    // 1. Preparamos el entorno: Usuario y Categoría real
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    // 2. Configuramos Mocks
    $analyzerMock = Mockery::mock(TransactionAnalyzer::class);
    $analyzerMock->shouldReceive('analyze')->andReturn(['entity' => 'trabajo', 'type' => 'income']);

    $catServiceMock = Mockery::mock(CategorizationService::class);
    // IMPORTANTE: Devolvemos el ID de la categoría real creada
    $catServiceMock->shouldReceive('findCategory')->andReturn($category->id);

    $action = new RegisterWhatsAppTransactionAction($analyzerMock, $catServiceMock);

    // 3. Definimos la fecha de prueba
    $dateStr = now()->subDay()->format('Y-m-d H:i:s');

    $dto = new ParsedReceiptDTO(
        isValid: true,
        amount: 1500.00,
        destination: null,
        origin: 'Trabajo',
        dateOperation: $dateStr,
        type: 'income',
        message: 'Pago de nómina'
    );

    // 4. Ejecutamos la acción
    $transaction = $action->execute($user, $dto);

    // 5. Aserciones
    expect($transaction->amount)->toEqual(1500.00)
        ->and($transaction->type_transaction)->toBe('income')
        ->and($transaction->message)->toBe('Pago de nómina')
        ->and($transaction->is_manual)->toBeTrue();

    /** * MANEJO DE LA FECHA:
     * Si en tu modelo Transaction NO tienes: protected $casts = ['date_operation' => 'datetime'],
     * entonces $transaction->date_operation es un STRING. 
     * Lo parseamos a Carbon en el test para poder comparar con seguridad.
     */
    $fechaResultado = \Carbon\Carbon::parse($transaction->date_operation)->format('Y-m-d H:i:s');
    expect($fechaResultado)->toBe($dateStr);
});
