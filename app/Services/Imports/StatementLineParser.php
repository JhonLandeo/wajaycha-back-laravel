<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\DTOs\TransactionDataDTO;
use Carbon\Carbon;

/**
 * Turns the text of a BCP statement into transaction data.
 *
 * Extracted verbatim from `ProcessPdfImport::handle()`, where it sat between a
 * qpdf shell-out, Fpdi and Tesseract and could not be reached by a test. The
 * algorithm is unchanged except for the statement-period year rule documented
 * on `resolveYear()`.
 *
 * The statement is fixed-width text. A movement line looks like this, and the
 * trailing space is not incidental — the parsing depends on it:
 *
 *     "01MAY 01MAY Pago YAPE a 191953                   15.40                    "
 *      ^date ^date ^description                         ^charge        ^deposit
 */
final class StatementLineParser
{
    /**
     * Matches the two `ddMMM` date columns every movement line opens with.
     * Header, footer and summary lines carry no such pair and are skipped.
     */
    private const MOVEMENT_LINE = '/(\d{2}[A-Z]{3})\s+(\d{2}[A-Z]{3})/';

    /**
     * @param  int  $statementYear  Year the statement was issued, from its filename.
     * @param  int  $statementMonth  Month the statement was issued, from its filename.
     * @return list<TransactionDataDTO>
     */
    public function parse(string $text, int $statementYear, int $statementMonth): array
    {
        $parsed = [];

        foreach (explode("\n", $text) as $line) {
            if (! preg_match(self::MOVEMENT_LINE, $line)) {
                continue;
            }

            // Every line ends with one trailing space. Dropping it here is what
            // puts a deposit amount at the last position of the exploded array,
            // which is the only thing distinguishing a deposit from a charge.
            $columns = explode(' ', substr($line, 0, -1));

            $description = $this->readDescription($columns);
            [$charge, $deposit] = $this->readAmounts($columns);

            $isDeposit = $deposit != 0;

            $parsed[] = new TransactionDataDTO(
                amount: floatval(str_replace(',', '', (string) ($isDeposit ? $deposit : $charge))),
                date_operation: $this->readDate($columns[1], $statementYear, $statementMonth),
                type_transaction: $isDeposit ? 'income' : 'expense',
                description: $description,
            );
        }

        return $parsed;
    }

    /**
     * The description runs from the third column until the padding starts.
     * Anything after the first blank column — the `*` marker, the operation
     * number, the amounts — is not part of it.
     *
     * @param  list<string>  $columns
     */
    private function readDescription(array $columns): string
    {
        $words = [];

        for ($i = 2; $i < count($columns); $i++) {
            if (trim($columns[$i]) === '') {
                break;
            }

            $words[] = $columns[$i];
        }

        return trim(implode(' ', $words));
    }

    /**
     * Charge and deposit sit in two fixed columns and only one is ever filled.
     * They are told apart by position: the deposit column is the rightmost, so
     * after the trailing space is dropped a deposit is the final element.
     *
     * A dot is what marks a value column, which also matches dotted words in the
     * description such as `TRAN.CTAS.PROP.BM` or `MANT.`. Those land in `$charge`
     * and are then overwritten by the real amount, because the amount columns
     * always come after the description. The order is load-bearing.
     *
     * @param  list<string>  $columns
     * @return array{0: string|int, 1: string|int}
     */
    private function readAmounts(array $columns): array
    {
        $charge = 0;
        $deposit = 0;
        $last = count($columns) - 1;

        foreach ($columns as $index => $value) {
            if (! str_contains($value, '.')) {
                continue;
            }

            if ($index === $last) {
                $deposit = $value;
            } else {
                $charge = $value;
            }
        }

        return [$charge, $deposit];
    }

    /**
     * The statement carries no year — a line says `15DIC`, never 2023. The year
     * has to come from the statement period, and taking it directly was wrong.
     *
     * A statement covers the month *before* the one it is issued in: this
     * repository's own `EECC062023` file holds 73 movements and every one of
     * them is May. Applied to January that breaks — `EECC012024` carries
     * December movements, and stamping them with 2024 moves the whole month a
     * year into the future, silently.
     *
     * So a movement whose month is after the issue month belongs to the
     * previous year.
     *
     * A `$statementMonth` below 1 means the issue month is unknown — the case a
     * job queued before that argument existed restores into. There is nothing to
     * compare against, so the year is taken as-is, which is exactly how the
     * parser behaved before the rule was added.
     */
    private function readDate(string $dateColumn, int $statementYear, int $statementMonth): string
    {
        $day = substr($dateColumn, 0, 2);
        $month = substr($dateColumn, 2);

        // BCP prints September as SET; Carbon's Spanish locale expects SEP.
        $date = Carbon::createFromLocaleFormat('dM', 'es', $day.($month === 'SET' ? 'SEP' : $month));

        $rollBack = $statementMonth >= 1 && $date->month > $statementMonth;

        return $date->setYear($rollBack ? $statementYear - 1 : $statementYear)->format('Y-m-d');
    }
}
