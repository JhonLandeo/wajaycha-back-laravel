<?php

use App\Services\AI\OpenCodeGateway;
use App\Services\AI\OpenCodeVisionService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.opencode.api_key', 'oc_sk_test');
    config()->set('services.opencode.base_url', 'https://opencode.ai/inference/go');
    config()->set('services.opencode.model', 'gemini-4-argon');
    config()->set('services.opencode.session_id', 'wajaycha-capture');
});

function openCodeVisionService(): OpenCodeVisionService
{
    return new OpenCodeVisionService(app(OpenCodeGateway::class));
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function openCodeVisionResponse(array $payload): array
{
    return [
        'candidates' => [
            [
                'content' => [
                    'parts' => [
                        ['text' => json_encode($payload)],
                    ],
                ],
            ],
        ],
    ];
}

it('retorna nulo si la respuesta no es exitosa', function () {
    Http::fake([
        '*' => Http::response('Error from OpenCode', 500),
    ]);

    $result = openCodeVisionService()->parseReceipt('fake-image-bytes', 'image/jpeg');

    expect($result)->toBeNull();
});

it('llama a OpenCode Go con bearer y sin la clave en la URL', function () {
    Http::fake([
        '*' => Http::response(openCodeVisionResponse(['is_valid_receipt' => false]), 200),
    ]);

    openCodeVisionService()->parseReceipt('fake-image-bytes', 'image/jpeg');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://opencode.ai/inference/go/google/v1beta/models/gemini-4-argon:generateContent'
            && $request->hasHeader('Authorization', 'Bearer oc_sk_test')
            && $request->hasHeader('x-opencode-session', 'wajaycha-capture')
            && $request['contents'][0]['role'] === 'user'
            && ! str_contains($request->url(), 'key=');
    });
});

it('retorna ParsedReceiptDTO cuando la respuesta es válida', function () {
    Http::fake([
        '*' => Http::response(openCodeVisionResponse([
            'is_valid_receipt' => true,
            'amount' => 120.50,
            'destination' => 'Restaurante XYZ',
            'origin' => null,
            'date_operation' => '2026-04-19 20:00:00',
            'type_transaction' => 'expense',
            'message' => 'Cena de negocios',
        ]), 200),
    ]);

    $dto = openCodeVisionService()->parseReceipt('fake-image-bytes', 'image/jpeg');

    expect($dto)->not->toBeNull()
        ->and($dto->isValid)->toBeTrue()
        ->and($dto->amount)->toEqual(120.50)
        ->and($dto->destination)->toBe('Restaurante XYZ')
        ->and($dto->type)->toBe('expense');
});

it('retorna isValid=false si el modelo no detecta un comprobante', function () {
    Http::fake([
        '*' => Http::response(openCodeVisionResponse([
            'is_valid_receipt' => false,
            'amount' => null,
            'destination' => null,
            'origin' => null,
            'date_operation' => null,
            'type_transaction' => null,
            'message' => null,
        ]), 200),
    ]);

    $dto = openCodeVisionService()->parseReceipt('fake-image-bytes', 'image/jpeg');

    expect($dto)->not->toBeNull()
        ->and($dto->isValid)->toBeFalse();
});

it('no llama a la red cuando falta la api key', function () {
    config()->set('services.opencode.api_key', '');

    Http::fake();

    expect(openCodeVisionService()->parseReceipt('fake-image-bytes', 'image/jpeg'))->toBeNull();

    Http::assertNothingSent();
});
