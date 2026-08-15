<?php

declare(strict_types=1);

/**
 * `StatementLineParser` was extracted from `ProcessPdfImport::handle()`, where it
 * sat behind qpdf, Fpdi and Tesseract at 6.6% coverage. It is pure text in,
 * DTOs out, so it belongs here rather than in Feature — no database, no queue.
 *
 * `tests/Fixtures/bcp-statement.txt` is a real BCP statement with every
 * description and amount replaced. What was preserved is the only thing the
 * parser actually depends on: fixed 74-character lines, the charge column
 * ending at 54, the deposit column ending at 73, and the trailing space.
 */

use App\Services\Imports\StatementLineParser;

/**
 * Builds one statement line at the real column offsets, for cases the fixture
 * does not happen to contain.
 */
function bcpLine(string $date, string $description, ?string $charge = null, ?string $deposit = null): string
{
    $line = str_pad("{$date} {$date} {$description}", 54);

    if ($charge !== null) {
        $line = substr($line, 0, 54 - strlen($charge)).$charge;
    }

    $line = str_pad($line, 73);

    if ($deposit !== null) {
        $line = substr($line, 0, 73 - strlen($deposit)).$deposit;
    }

    // The trailing space is load-bearing: dropping it is what puts a deposit at
    // the last position of the exploded line.
    return $line.' ';
}

function bcpFixture(): string
{
    return file_get_contents(__DIR__.'/../../Fixtures/bcp-statement.txt');
}

/** Statement EECC062023 — issued June 2023, covering May. */
function parseFixture(): array
{
    return (new StatementLineParser)->parse(bcpFixture(), 2023, 6);
}

// ------------------------------------------------------ the real statement

it('parsea todos los movimientos de un extracto real', function () {
    expect(parseFixture())->toHaveCount(73);
});

it('ignora encabezados y cualquier linea sin el par de fechas', function () {
    // The fixture opens with three header lines. None carries a ddMMM pair.
    expect(substr_count(bcpFixture(), "\n"))->toBe(76)
        ->and(parseFixture())->toHaveCount(73);
});

it('lee un cargo como gasto', function () {
    $first = parseFixture()[0];

    expect($first->description)->toBe('PANA BODE a 060684');
    expect($first->amount)->toBe(8.13);
    expect($first->type_transaction)->toBe('expense');
    expect($first->date_operation)->toBe('2023-05-01');
});

it('lee un abono como ingreso', function () {
    $deposits = array_values(array_filter(
        parseFixture(),
        fn ($t) => $t->type_transaction === 'income'
    ));

    expect($deposits)->toHaveCount(8);
    expect($deposits[0]->amount)->toBe(83.33);
    expect($deposits[0]->description)->toBe('GRIFO');
});

it('excluye el asterisco de la descripcion', function () {
    // "01MAY 01MAY GRIFO                 *               2.33"
    $marked = array_values(array_filter(
        parseFixture(),
        fn ($t) => $t->description === 'GRIFO'
    ));

    expect($marked)->not->toBeEmpty();
});

it('quita el separador de miles del monto', function () {
    $big = array_values(array_filter(parseFixture(), fn ($t) => $t->amount > 1000.0));

    expect($big)->not->toBeEmpty();
    expect($big[0]->amount)->toBe(7333.33);
});

/**
 * The amount columns are found by looking for a dot, and `POLL.LIBR.MERC.BM` is
 * full of them. It parses correctly only because the description comes before
 * the amounts and the later match overwrites the earlier one. That ordering is
 * an invariant, not a coincidence, so it gets a test.
 */
it('no confunde una descripcion con puntos con el monto', function () {
    $dotted = array_values(array_filter(
        parseFixture(),
        fn ($t) => str_contains($t->description, '.')
    ));

    expect($dotted)->not->toBeEmpty();

    foreach ($dotted as $t) {
        expect($t->amount)->toBeGreaterThan(0.0);
    }

    $charged = array_values(array_filter($dotted, fn ($t) => $t->description === 'POLL.LIBR.MERC.BM'));

    expect($charged[0]->amount)->toBe(933.33);
    expect($charged[0]->type_transaction)->toBe('expense');
});

// ------------------------------------------------------------- line shapes

it('lee un monto en cero sin descartar la fila', function () {
    $parsed = (new StatementLineParser)->parse(
        bcpLine('30MAY', 'MANT. CUENTA MAY25', charge: '0.00'),
        2023,
        6
    );

    expect($parsed)->toHaveCount(1);
    expect($parsed[0]->amount)->toBe(0.0);
    expect($parsed[0]->type_transaction)->toBe('expense');
});

it('mapea SET a septiembre, que es como lo imprime el banco', function () {
    $parsed = (new StatementLineParser)->parse(
        bcpLine('15SET', 'COMPRA', charge: '10.00'),
        2023,
        10
    );

    expect($parsed[0]->date_operation)->toBe('2023-09-15');
});

// ------------------------------------------------------- the defect under test

/**
 * A statement covers the month before the one it is issued in. Taking the year
 * straight from the filename stamped every December movement of a January
 * statement a full year into the future — 73 movements landing in the wrong
 * year, with nothing failing and nothing logged.
 */
it('estampa diciembre de un extracto de enero en el año anterior', function () {
    // EECC012024 — issued January 2024, covering December 2023.
    $parsed = (new StatementLineParser)->parse(
        bcpLine('15DIC', 'COMPRA NAVIDAD', charge: '99.90'),
        2024,
        1
    );

    expect($parsed[0]->date_operation)->toBe('2023-12-15');
});

it('deja el mes del propio extracto en el año del extracto', function () {
    // Same January statement, a movement that really is from January.
    $parsed = (new StatementLineParser)->parse(
        bcpLine('05ENE', 'COMPRA', charge: '10.00'),
        2024,
        1
    );

    expect($parsed[0]->date_operation)->toBe('2024-01-05');
});

it('no retrocede el año cuando el movimiento precede al mes del extracto', function () {
    $parsed = (new StatementLineParser)->parse(
        bcpLine('20MAY', 'COMPRA', charge: '10.00'),
        2023,
        6
    );

    expect($parsed[0]->date_operation)->toBe('2023-05-20');
});

/**
 * A statement month below 1 means the issue month is unknown. That is the state
 * a `ProcessPdfImport` job queued before the month argument existed restores
 * into: `unserialize()` skips properties absent from the payload, so the job
 * reaches the parser with the property's default rather than a real month.
 *
 * With nothing to compare against, the year must be taken as given — the
 * behaviour the parser had before the rule existed. Rolling back here would
 * silently move an old job's whole statement a year into the past.
 */
it('deja el año intacto cuando no se conoce el mes del extracto', function (int $unknownMonth) {
    $parsed = (new StatementLineParser)->parse(
        bcpLine('15DIC', 'COMPRA NAVIDAD', charge: '99.90'),
        2024,
        $unknownMonth
    );

    expect($parsed[0]->date_operation)->toBe('2024-12-15');
})->with([
    'cero' => 0,
    'negativo' => -1,
]);
