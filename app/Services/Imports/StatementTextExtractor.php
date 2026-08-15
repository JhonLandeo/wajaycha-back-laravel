<?php

declare(strict_types=1);

namespace App\Services\Imports;

use RuntimeException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\PdfParserException;
use Smalot\PdfParser\Parser;
use thiagoalessio\TesseractOCR\TesseractOCR;

/**
 * Gets the text out of a bank statement PDF: decrypt if needed, read the text
 * layer, fall back to OCR when there is none.
 *
 * Extracted from `ProcessPdfImport::handle()` for the same reason
 * {@see StatementLineParser} was, one layer further out. Everything here shells
 * out to `qpdf` or drives Tesseract, so it is slow, it is I/O, and it has no
 * business happening inside a database transaction — which is exactly where it
 * used to happen. `handle()` opened one before this work started and committed
 * after it finished, so a scanned statement could hold a PostgreSQL transaction
 * open for as long as the job's ten-minute timeout allowed: a connection
 * pinned, `idle in transaction`, and autovacuum blocked from cleaning anything
 * newer for the duration.
 *
 * Pulling it into its own class does two things at once. The transaction can
 * now start after the last slow call rather than before the first, and the job
 * becomes reachable by a test without qpdf or Tesseract installed — which is
 * why the bug survived this long.
 *
 * The algorithm is unchanged except for the empty-text rule, noted below.
 */
class StatementTextExtractor
{
    /**
     * @param  string  $absolutePath  A real path on disk, already resolved by the caller.
     * @param  string|null  $password  Only consulted when the file turns out to be encrypted.
     *
     * @throws RuntimeException When the file is encrypted and the password does not open it.
     */
    public function extract(string $absolutePath, ?string $password): string
    {
        $decryptedPath = null;

        try {
            if ($this->isEncrypted($absolutePath)) {
                $absolutePath = $decryptedPath = $this->decrypt($absolutePath, (string) $password);
            }

            $text = $this->textLayerOf($absolutePath);

            // `empty($text)` before this change, which is not the same test: a
            // scanned statement often carries a text layer of nothing but
            // newlines and spaces. `empty()` reads that as present, skips OCR,
            // and hands the parser a page with no movement lines on it — an
            // import that completes with zero transactions and no error to
            // explain it.
            return trim($text) !== '' ? $text : $this->ocr($absolutePath);
        } finally {
            // `decrypt()` writes a copy of the statement with no password on it.
            // Before this was cleaned up, every encrypted import left a bank
            // statement in clear text accumulating under storage/app/private.
            // The `finally` covers the OCR path throwing just as much as the
            // happy one.
            if ($decryptedPath !== null && is_file($decryptedPath)) {
                @unlink($decryptedPath);
            }
        }
    }

    private function isEncrypted(string $absolutePath): bool
    {
        $pdf = new Fpdi;

        try {
            $pdf->setSourceFile($absolutePath);

            return false;
        } catch (PdfParserException $e) {
            return true;
        }
    }

    /**
     * @throws RuntimeException
     */
    private function decrypt(string $absolutePath, string $password): string
    {
        $decryptedPath = storage_path('app/private/'.uniqid('decrypted_').'.pdf');

        // KNOWN GAP, recorded rather than silently carried: the password reaches
        // qpdf as a command-line argument, so it is visible in `ps` to every
        // other process on the box for as long as the call runs. `escapeshellarg`
        // protects against injection, not against being read. Closing it means
        // `--password-file=-` and feeding stdin through `proc_open`, which is its
        // own change with its own tests — see docs/architecture/technical-debt.md.
        $command = sprintf(
            'qpdf --decrypt --password=%s %s %s',
            escapeshellarg($password),
            escapeshellarg($absolutePath),
            escapeshellarg($decryptedPath)
        );

        // `2>&1` porque `exec()` captura stdout y nada mas. qpdf explica sus fallos
        // por stderr, asi que su unica explicacion se perdia y lo que quedaba era
        // el codigo de salida, que este metodo traducia siempre a lo mismo.
        exec($command.' 2>&1', $output, $returnVar);

        if ($returnVar !== 0) {
            throw new RuntimeException($this->decryptionFailure($returnVar, $output));
        }

        return $decryptedPath;
    }

    /**
     * Says what went wrong instead of guessing.
     *
     * Every non-zero exit used to become "¿Contraseña incorrecta?". That is one
     * cause among several, and it was the wrong one the first time it mattered:
     * the container image shipped no `qpdf`, the shell returned 127, and the user
     * was told their password was wrong — a question they cannot answer and a fix
     * they cannot find. An unreadable file and an unwritable destination fell into
     * the same hole.
     *
     * 127 is separated by name because it is the only cause that is not about this
     * PDF at all, and the only one whose fix is an installation.
     *
     * `$command` is deliberately absent from the message. It carries the statement
     * password as an argument, and this string is written to
     * `imports.error_message` and to the log, both of which the user can read and
     * neither of which should hold a password.
     *
     * @param  list<string>  $output
     */
    private function decryptionFailure(int $exitCode, array $output): string
    {
        if ($exitCode === 127) {
            return 'No se pudo procesar el PDF: falta el binario qpdf en el entorno.';
        }

        $detail = trim(implode(' ', $output));

        return $detail !== ''
            ? "No se pudo desencriptar el PDF (qpdf salió con {$exitCode}): {$detail}"
            : "No se pudo desencriptar el PDF: qpdf salió con {$exitCode}. ¿Contraseña incorrecta?";
    }

    /** Returns '' rather than throwing, because an absent text layer is the OCR trigger. */
    private function textLayerOf(string $absolutePath): string
    {
        try {
            return (new Parser)->parseFile($absolutePath)->getText();
        } catch (\Exception $e) {
            return '';
        }
    }

    private function ocr(string $absolutePath): string
    {
        return (new TesseractOCR($absolutePath))->run();
    }
}
