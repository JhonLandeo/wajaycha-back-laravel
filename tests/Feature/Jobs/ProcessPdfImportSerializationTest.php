<?php

declare(strict_types=1);

/**
 * Queued jobs are restored with `unserialize()`, which never calls the
 * constructor, and `SerializesModels::__unserialize()` skips any property
 * absent from the payload. A job serialized before `$month` existed therefore
 * restores with that property untouched — and reading a non-nullable typed
 * property that was never initialised raises an `Error` that the job's own
 * `catch (Throwable)` turns into a FAILED import with an incomprehensible
 * message and no retry.
 *
 * The property now carries a default, so the hazard is a regression rather than
 * a hypothetical. This pins it.
 */

use App\Jobs\ProcessPdfImport;

it('sobrevive a un payload encolado antes de que existiera el mes', function () {
    $job = new ProcessPdfImport(1, 2, 'files/x.pdf', 3, 2024, 1, 'secreto');

    // Rebuild the payload an older release would have produced: drop the
    // `month` entry and decrement the property count in the object header.
    // Protected properties are serialized under a NUL-delimited name, hence
    // the \x00*\x00 prefix.
    $payload = serialize($job);

    $stripped = preg_replace('/s:8:"\x00\*\x00month";i:\d+;/', '', $payload, 1, $removed);
    expect($removed)->toBe(1);

    $stripped = preg_replace_callback(
        '/^(O:\d+:"[^"]+":)(\d+):/',
        fn (array $header): string => $header[1].($header[2] - 1).':',
        $stripped
    );

    /** @var ProcessPdfImport $restored */
    $restored = unserialize($stripped);

    // The property is what matters: reading it must not raise
    // "Typed property must not be accessed before initialization".
    $month = (new ReflectionProperty($restored, 'month'))->getValue($restored);

    expect($month)->toBe(0);
});

it('conserva el mes cuando el payload si lo trae', function () {
    $job = new ProcessPdfImport(1, 2, 'files/x.pdf', 3, 2024, 7, 'secreto');

    /** @var ProcessPdfImport $restored */
    $restored = unserialize(serialize($job));

    expect((new ReflectionProperty($restored, 'month'))->getValue($restored))->toBe(7);
});

it('acepta construirse sin contraseña', function () {
    // The property was declared `string` while the Action already passed
    // `?string`; building the job without one raised a TypeError inside the
    // Action's transaction. Unreachable today only because PdfRequest requires
    // the field.
    $job = new ProcessPdfImport(1, 2, 'files/x.pdf', 3, 2024, 7, null);

    expect((new ReflectionProperty($job, 'password'))->getValue($job))->toBeNull();
});
