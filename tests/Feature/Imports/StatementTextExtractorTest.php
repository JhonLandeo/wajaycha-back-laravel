<?php

declare(strict_types=1);

/**
 * `StatementTextExtractor` tenia cero tests, y eso no es casual: todo lo que hace
 * sale a `qpdf` o a Tesseract, asi que ejercitarlo parecia necesitar un extracto
 * real del banco y las dos herramientas instaladas.
 *
 * No las necesita para lo unico que hay que fijar aca, que es lo que el usuario
 * lee cuando algo falla. `isEncrypted()` devuelve true ante cualquier archivo que
 * Fpdi no pueda abrir — un PDF con contraseña, pero tambien basura — asi que
 * alcanza un archivo cualquiera para llegar a `decrypt()` y ver como traduce un
 * fallo de qpdf.
 */

use App\Services\Imports\StatementTextExtractor;
use Illuminate\Support\Facades\Storage;

function statementFileThatNeedsQpdf(): string
{
    Storage::disk('local')->put('private/no-es-un-pdf.pdf', 'esto no abre con Fpdi');

    return Storage::disk('local')->path('private/no-es-un-pdf.pdf');
}

it('nombra el binario que falta en vez de culpar a la contraseña', function () {
    $path = statementFileThatNeedsQpdf();
    $pathOriginal = getenv('PATH');

    // Sacar qpdf del PATH reproduce exactamente el entorno que fallo: la imagen
    // del contenedor no lo traia, el shell devolvio 127, y al usuario le llego
    // "¿Contraseña incorrecta?" sobre una contraseña que estaba bien. Una pregunta
    // que no puede contestar y un arreglo que no puede encontrar.
    putenv('PATH=/directorio/que/no/existe');

    try {
        expect(fn () => (new StatementTextExtractor)->extract($path, 'la-clave'))
            ->toThrow(RuntimeException::class, 'falta el binario qpdf');
    } finally {
        putenv('PATH='.$pathOriginal);
    }
});

it('nunca deja la contraseña dentro del mensaje de error', function () {
    $path = statementFileThatNeedsQpdf();
    $clave = 'clave-secreta-del-extracto';

    // El mensaje termina en `imports.error_message` y en el log, los dos a la
    // vista. La contraseña viaja como argumento de linea de comandos, asi que
    // cualquier mensaje armado a partir del comando la filtraria.
    try {
        (new StatementTextExtractor)->extract($path, $clave);

        // Si qpdf esta instalado y abre este archivo, no hay nada que verificar.
        expect(true)->toBeTrue();
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain($clave);
    }
});

it('borra la copia desencriptada aunque la extraccion reviente', function () {
    $path = statementFileThatNeedsQpdf();

    $antes = glob(storage_path('app/private/decrypted_*.pdf')) ?: [];

    try {
        (new StatementTextExtractor)->extract($path, 'la-clave');
    } catch (RuntimeException) {
        // El fallo es el caso: lo que se mira es lo que quedo en disco.
    }

    // Una copia sin contraseña de un extracto bancario no puede sobrevivir al
    // fallo que la dejo ahi.
    expect(glob(storage_path('app/private/decrypted_*.pdf')) ?: [])->toBe($antes);
});
