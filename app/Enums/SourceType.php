<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a Transaction came from.
 *
 * `docs/domain/ubiquitous-language.md` flagged the absence of this enum as a
 * modelling risk: `source_type` is a plain string column carrying five known
 * values. This does not rewrite the literals already spread through the
 * migrations and the PostgreSQL functions — that is a migration of data and of
 * SQL, not a refactor — but new code has somewhere to point at.
 *
 * CAPTURE is the value that did not exist. A photo sent through Telegram was
 * written with the column default, `manual`, which is what the interface writes
 * when a person types a movement in by hand. Two very different provenances
 * shared one label, so nothing downstream could tell them apart — including the
 * duplicate check in `TransactionYapeImport`, which filters on `source_type` and
 * was therefore blind to every captured movement.
 */
enum SourceType: string
{
    case MANUAL = 'manual';
    case CAPTURE = 'capture';
    case IMPORT_APP = 'import_app';
    case IMPORT_STATEMENT = 'import_statement';
    case YAPE_MATCHED = 'yape_matched';

    /** Legacy value predating the unification migration. Never written by new code. */
    case LEGACY_TRANSACTION = 'transaction';

    /**
     * How much this source is trusted when two rows turn out to be the same real
     * movement and one of them has to become the satellite.
     *
     * The bank statement outranks everything because it is the ledger the money
     * actually moved through. The Yape export outranks a capture because Yape
     * wrote it and Gemini only read it off a photo. A capture outranks a typed
     * row for the same reason: it has a receipt behind it.
     *
     * Higher wins. The loser keeps its row and its evidence — it only stops
     * being counted, via `matched_transaction_id`.
     */
    public function authority(): int
    {
        return match ($this) {
            self::IMPORT_STATEMENT => 40,
            self::YAPE_MATCHED => 35,
            self::IMPORT_APP => 30,
            self::CAPTURE => 20,
            self::MANUAL, self::LEGACY_TRANSACTION => 10,
        };
    }

    /** Unknown strings survive as MANUAL: the column has no constraint behind it. */
    public static function fromColumn(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::MANUAL;
    }
}
