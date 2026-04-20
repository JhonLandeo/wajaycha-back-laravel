<?php

use Illuminate\Support\Facades\Queue;
use App\Jobs\ProcessWhatsAppMessage;
use App\Jobs\ProcessWhatsAppImage;
use Illuminate\Support\Facades\Config;

it('verifica el webhook con token correcto devuelve el challenge', function () {
    Config::set('services.whatsapp.webhook_verify_token', 'mi_secreto_yape_123');

    $response = $this->get('/api/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=mi_secreto_yape_123&hub_challenge=123456');

    $response->assertStatus(200);
    $this->assertEquals('123456', $response->getContent());
});

it('rechaza la verificación con token incorrecto', function () {
    Config::set('services.whatsapp.webhook_verify_token', 'my-secret-token');

    $response = $this->get('/api/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=wrong-token&hub_challenge=123456');

    $response->assertStatus(403);
});

it('despacha ProcessWhatsAppMessage al recibir un mensaje de texto', function () {
    Queue::fake();

    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [
            [
                'changes' => [
                    [
                        'value' => [
                            'messages' => [
                                [
                                    'from' => '51999999999',
                                    'type' => 'text',
                                    'text' => ['body' => 'Gasto de 10 en almuerzo']
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ];

    $response = $this->postJson('/api/whatsapp/webhook', $payload);

    $response->assertStatus(200);
    Queue::assertPushed(ProcessWhatsAppMessage::class);
});

it('despacha ProcessWhatsAppImage al recibir un mensaje de imagen', function () {
    Queue::fake();

    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [
            [
                'changes' => [
                    [
                        'value' => [
                            'messages' => [
                                [
                                    'from' => '51999999999',
                                    'type' => 'image',
                                    'image' => [
                                        'id' => 'img_12345',
                                        'mime_type' => 'image/jpeg',
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ];

    $response = $this->postJson('/api/whatsapp/webhook', $payload);

    $response->assertStatus(200);
    Queue::assertPushed(ProcessWhatsAppImage::class);
});

it('devuelve 200 inmediatamente aunque el payload sea inválido', function () {
    Queue::fake();

    $response = $this->postJson('/api/whatsapp/webhook', [
        'object' => 'whatsapp_business_account',
        'entry' => []
    ]);

    $response->assertStatus(200);
    Queue::assertNothingPushed();
});
