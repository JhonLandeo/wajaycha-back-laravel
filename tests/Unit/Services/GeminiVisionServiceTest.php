<?php

use App\Services\AI\GeminiVisionService;
use Illuminate\Support\Facades\Http;

it('retorna nulo si la respuesta no es exitosa', function () {
    Http::fake([
        '*' => Http::response('Error from Gemini', 500)
    ]);

    $service = new GeminiVisionService();
    $result = $service->parseReceipt('fake-image-bytes', 'image/jpeg');

    expect($result)->toBeNull();
});

it('retorna ParsedReceiptDTO cuando la respuesta es válida', function () {
    $fakeResponse = [
        'candidates' => [
            [
                'content' => [
                    'parts' => [
                        [
                            'text' => json_encode([
                                'is_valid_receipt' => true,
                                'amount' => 120.50,
                                'destination' => 'Restaurante XYZ',
                                'origin' => null,
                                'date_operation' => '2026-04-19 20:00:00',
                                'type_transaction' => 'expense',
                                'message' => 'Cena de negocios'
                            ])
                        ]
                    ]
                ]
            ]
        ]
    ];

    Http::fake([
        '*' => Http::response($fakeResponse, 200)
    ]);

    $service = new GeminiVisionService();
    $dto = $service->parseReceipt('fake-image-bytes', 'image/jpeg');

    expect($dto)->not->toBeNull()
        ->and($dto->isValid)->toBeTrue()
        ->and($dto->amount)->toEqual(120.50)
        ->and($dto->destination)->toBe('Restaurante XYZ')
        ->and($dto->type)->toBe('expense');
});

it('retorna isValid=false si Gemini no detecta un comprobante', function () {
    $fakeResponse = [
        'candidates' => [
            [
                'content' => [
                    'parts' => [
                        [
                            'text' => json_encode([
                                'is_valid_receipt' => false,
                                'amount' => null,
                                'destination' => null,
                                'origin' => null,
                                'date_operation' => null,
                                'type_transaction' => null,
                                'message' => null
                            ])
                        ]
                    ]
                ]
            ]
        ]
    ];

    Http::fake([
        '*' => Http::response($fakeResponse, 200)
    ]);

    $service = new GeminiVisionService();
    $dto = $service->parseReceipt('fake-image-bytes', 'image/jpeg');

    expect($dto)->not->toBeNull()
        ->and($dto->isValid)->toBeFalse();
});
