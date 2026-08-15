<?php

declare(strict_types=1);

use App\DTOs\Capture\CapturedMedia;
use App\Services\Capture\CaptureChannel;
use App\Services\Capture\CaptureChannelRegistry;

/**
 * The contract every capture channel must satisfy, driven by a fake so it stays
 * independent of any real transport.
 */
final class FakeCaptureChannel implements CaptureChannel
{
    /** @var array<int, array{externalId: string, text: string}> */
    public array $sent = [];

    public function __construct(private readonly ?CapturedMedia $media = null) {}

    public function key(): string
    {
        return 'fake';
    }

    public function fetchMedia(string $mediaReference): ?CapturedMedia
    {
        return $this->media;
    }

    public function reply(string $externalId, string $text): void
    {
        $this->sent[] = ['externalId' => $externalId, 'text' => $text];
    }
}

it('expone las tres responsabilidades por canal', function () {
    $channel = new FakeCaptureChannel(new CapturedMedia('bytes', 'image/jpeg'));

    expect($channel)->toBeInstanceOf(CaptureChannel::class)
        ->and($channel->key())->toBe('fake')
        ->and($channel->fetchMedia('cualquier-referencia'))->toBeInstanceOf(CapturedMedia::class);

    $channel->reply('999', 'hola');

    expect($channel->sent)->toBe([['externalId' => '999', 'text' => 'hola']]);
});

it('permite que un canal no encuentre el medio y devuelva null', function () {
    $channel = new FakeCaptureChannel(null);

    expect($channel->fetchMedia('inexistente'))->toBeNull();
});

it('transporta los bytes y el mime type sin interpretarlos', function () {
    $media = new CapturedMedia('bytes-crudos', 'image/png');

    expect($media->bytes)->toBe('bytes-crudos')
        ->and($media->mimeType)->toBe('image/png');
});

it('resuelve un adaptador por su clave sin que el llamador haga match', function () {
    $registry = new CaptureChannelRegistry([new FakeCaptureChannel]);

    expect($registry->for('fake'))->toBeInstanceOf(FakeCaptureChannel::class);
});

it('rechaza una clave de canal desconocida en lugar de devolver null', function () {
    $registry = new CaptureChannelRegistry([new FakeCaptureChannel]);

    expect(fn () => $registry->for('telegram'))
        ->toThrow(InvalidArgumentException::class);
});

it('registra el adaptador de WhatsApp en el contenedor', function () {
    expect(app(CaptureChannelRegistry::class)->for('whatsapp')->key())->toBe('whatsapp');
});
