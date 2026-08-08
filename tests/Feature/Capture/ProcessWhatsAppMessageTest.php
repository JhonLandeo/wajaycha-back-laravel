<?php

declare(strict_types=1);

use App\DTOs\WhatsApp\ParsedReceiptDTO;
use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AI\GeminiTextService;
use App\Services\Capture\CaptureChannelRegistry;
use App\Services\Capture\ChannelIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CaptureFixtures;
use Tests\Support\RecordingCaptureChannel;
use Tests\Support\ThrowingCaptureChannel;

uses(RefreshDatabase::class);

/**
 * The job body itself, not its dispatch. WhatsAppWebhookTest already proves the
 * controller enqueues; nothing proved the work the queue then does.
 */
function runMessageJob(string $text, string $from, RecordingCaptureChannel $channel, ?ParsedReceiptDTO $parsed): void
{
    $gemini = Mockery::mock(GeminiTextService::class);
    $gemini->shouldReceive('parseText')->andReturn($parsed);

    (new ProcessWhatsAppMessage($text, $from))->handle(
        $gemini,
        new CaptureChannelRegistry([$channel]),
        app(ChannelIdentityResolver::class),
        app(\App\Actions\Capture\RegisterCapturedTransactionAction::class),
    );
}

it('no registra nada y explica como vincular cuando el remitente es desconocido', function () {
    $channel = new RecordingCaptureChannel;

    runMessageJob('gaste 20 en el taxi', '51000000000', $channel, CaptureFixtures::validMovement());

    expect(Transaction::count())->toBe(0)
        ->and($channel->lastReply())->toContain('no está vinculado');
});

it('registra la transaccion de un remitente vinculado y le confirma', function () {
    $user = CaptureFixtures::linkedSender();
    $channel = new RecordingCaptureChannel;

    runMessageJob('gaste 25.50 en la bodega', '51999888777', $channel, CaptureFixtures::validMovement());

    $transaction = Transaction::sole();

    expect($transaction->user_id)->toBe($user->id)
        ->and((float) $transaction->amount)->toBe(25.50)
        ->and($channel->lastReply())->toContain('Registro exitoso')
        ->and($channel->lastReply())->toContain('25.50');
});

it('atribuye la transaccion al dueño de la identidad, no a cualquier usuario', function () {
    $owner = CaptureFixtures::linkedSender();
    $otro = User::factory()->create();

    $channel = new RecordingCaptureChannel;
    runMessageJob('gaste 25.50', '51999888777', $channel, CaptureFixtures::validMovement());

    expect(Transaction::sole()->user_id)->toBe($owner->id)
        ->and(Transaction::where('user_id', $otro->id)->count())->toBe(0);
});

it('avisa y no registra cuando la IA no responde', function () {
    CaptureFixtures::linkedSender();
    $channel = new RecordingCaptureChannel;

    runMessageJob('gaste algo', '51999888777', $channel, null);

    expect(Transaction::count())->toBe(0)
        ->and($channel->lastReply())->toContain('Error de conexión');
});

it('avisa y no registra cuando el mensaje no describe un movimiento', function () {
    CaptureFixtures::linkedSender();
    $channel = new RecordingCaptureChannel;

    $invalid = CaptureFixtures::unparseableMovement();

    runMessageJob('hola que tal', '51999888777', $channel, $invalid);

    expect(Transaction::count())->toBe(0)
        ->and($channel->lastReply())->toContain('no parece indicar un registro financiero');
});

it('avisa al remitente cuando el job falla inesperadamente', function () {
    $channel = new RecordingCaptureChannel;
    app()->instance(CaptureChannelRegistry::class, new CaptureChannelRegistry([$channel]));

    (new ProcessWhatsAppMessage('gaste algo', '51999888777'))
        ->failed(new RuntimeException('la cola exploto'));

    expect($channel->lastReply())->toContain('error inesperado');
});

it('no propaga un fallo del canal desde failed(), para no reencolar el job', function () {
    app()->instance(CaptureChannelRegistry::class, new CaptureChannelRegistry([new ThrowingCaptureChannel]));

    (new ProcessWhatsAppMessage('gaste algo', '51999888777'))
        ->failed(new RuntimeException('la cola exploto'));
})->throwsNoExceptions();
