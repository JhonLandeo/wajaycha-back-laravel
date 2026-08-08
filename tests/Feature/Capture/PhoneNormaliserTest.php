<?php

declare(strict_types=1);

use App\Services\Capture\PhoneNormaliser;

beforeEach(function () {
    $this->normaliser = app(PhoneNormaliser::class);
});

it('reduce un telefono a digitos E164 sin el mas', function () {
    expect($this->normaliser->normalise('+51 999 888 777'))->toBe('51999888777')
        ->and($this->normaliser->normalise('51-999-888-777'))->toBe('51999888777');
});

it('rechaza lo que no puede normalizar con certeza', function () {
    expect($this->normaliser->normalise('no-es-un-telefono'))->toBeNull()
        ->and($this->normaliser->normalise(''))->toBeNull();
});

/**
 * Estos limites viven duplicados: aca y dentro de la migracion que crea
 * channel_identities, que es autocontenida a proposito. Si uno de los dos se corre,
 * un usuario queda con una identidad que nadie va a resolver. ChannelIdentityMigrationTest
 * ejercita exactamente los mismos casos del otro lado.
 */
it('respeta exactamente los mismos limites que la migracion', function (string $phone, ?string $expected) {
    expect($this->normaliser->normalise($phone))->toBe($expected);
})->with([
    'siete digitos, uno menos que el minimo' => ['1234567', null],
    'ocho digitos, el minimo exacto' => ['12345678', '12345678'],
    'quince digitos, el maximo E164' => ['123456789012345', '123456789012345'],
    'dieciseis digitos, uno mas que el maximo' => ['1234567890123456', null],
]);
