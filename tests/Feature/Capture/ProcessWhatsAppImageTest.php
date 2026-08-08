<?php

declare(strict_types=1);

use App\DTOs\Capture\CapturedMedia;
use App\DTOs\WhatsApp\ParsedReceiptDTO;
use App\Jobs\ProcessWhatsAppImage;
use App\Models\Transaction;
use App\Services\AI\GeminiVisionService;
use App\Services\Capture\CaptureChannelRegistry;
use App\Services\Capture\ChannelIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CaptureFixtures;
use Tests\Support\RecordingCaptureChannel;
use Tests\Support\ThrowingCaptureChannel;

uses(RefreshDatabase::class);

function runImageJob(string $imageId, string $from, RecordingCaptureChannel $channel, ?ParsedReceiptDTO $parsed): void
{
    $gemini = Mockery::mock(GeminiVisionService::class);
    $gemini->shouldReceive('parseReceipt')->andReturn($parsed);

    (new ProcessWhatsAppImage($imageId, $from))->handle(
        $gemini,
        new CaptureChannelRegistry([$channel]),
        app(ChannelIdentityResolver::class),
        app(\App\Actions\Capture\RegisterCapturedTransactionAction::class),
    );
}

it('no descarga el comprobante de un remitente desconocido', function () {
    $channel = new RecordingCaptureChannel(new CapturedMedia('bytes', 'image/jpeg'));

    runImageJob('media-1', '51000000000', $channel, CaptureFixtures::validMovement());

    // Importante: cortar ANTES de bajar el medio evita una llamada a Meta y otra a
    // Gemini por cada mensaje de un numero no vinculado.
    expect($channel->mediaRequested)->toBe([])
        ->and(Transaction::count())->toBe(0)
        ->and($channel->lastReply())->toContain('no está vinculado');
});

it('registra el comprobante de un remitente vinculado y le confirma', function () {
    $user = CaptureFixtures::linkedSender();
    $channel = new RecordingCaptureChannel(new CapturedMedia('bytes-del-comprobante', 'image/png'));

    runImageJob('media-1', '51999888777', $channel, CaptureFixtures::validMovement());

    $transaction = Transaction::sole();

    expect($channel->mediaRequested)->toBe(['media-1'])
        ->and($transaction->user_id)->toBe($user->id)
        ->and((float) $transaction->amount)->toBe(25.50)
        ->and($channel->lastReply())->toContain('Comprobante registrado');
});

it('avisa y no registra cuando el medio no se puede descargar', function () {
    CaptureFixtures::linkedSender();
    $channel = new RecordingCaptureChannel(null);

    runImageJob('media-inexistente', '51999888777', $channel, CaptureFixtures::validMovement());

    expect(Transaction::count())->toBe(0)
        ->and($channel->lastReply())->toContain('Error al descargar');
});

it('avisa y no registra cuando la IA no responde', function () {
    CaptureFixtures::linkedSender();
    $channel = new RecordingCaptureChannel(new CapturedMedia('bytes', 'image/jpeg'));

    runImageJob('media-1', '51999888777', $channel, null);

    expect(Transaction::count())->toBe(0)
        ->and($channel->lastReply())->toContain('Error de conexión');
});

it('avisa y no registra cuando la imagen no es un comprobante', function () {
    CaptureFixtures::linkedSender();
    $channel = new RecordingCaptureChannel(new CapturedMedia('bytes', 'image/jpeg'));

    $invalid = CaptureFixtures::unparseableMovement();

    runImageJob('media-1', '51999888777', $channel, $invalid);

    expect(Transaction::count())->toBe(0)
        ->and($channel->lastReply())->toContain('no parece ser un comprobante');
});

it('avisa al remitente cuando el job falla inesperadamente', function () {
    $channel = new RecordingCaptureChannel;
    app()->instance(CaptureChannelRegistry::class, new CaptureChannelRegistry([$channel]));

    (new ProcessWhatsAppImage('media-1', '51999888777'))
        ->failed(new RuntimeException('la cola exploto'));

    expect($channel->lastReply())->toContain('error inesperado');
});

it('no propaga un fallo del canal desde failed(), para no reencolar el job', function () {
    app()->instance(CaptureChannelRegistry::class, new CaptureChannelRegistry([new ThrowingCaptureChannel]));

    (new ProcessWhatsAppImage('media-1', '51999888777'))
        ->failed(new RuntimeException('la cola exploto'));
})->throwsNoExceptions();
