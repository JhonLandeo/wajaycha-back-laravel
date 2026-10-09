<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DTOs\Prices\CatalogueProduct;
use App\DTOs\Prices\ObservationDraft;
use App\DTOs\Prices\Quote;
use App\Enums\PriceRunStatus;
use App\Models\PriceIngestionRun;
use App\Models\PriceObservation;
use Carbon\CarbonImmutable;

interface PriceRepositoryContract
{
    /**
     * Idempotent write: the tuple (product, source, period_start) is the key, so
     * re-ingesting it updates the row — including clearing optional fields the
     * rerun no longer carries — instead of adding a second one.
     */
    public function upsertObservation(ObservationDraft $draft): PriceObservation;

    /**
     * Quotes for the given products from the given sources only. `$cutoffs` maps
     * each source to the oldest `period_end` still worth reading; a source that
     * is not a key is not read at all (that is how a disabled source disappears
     * from every read path while its rows stay stored). Quarantined rows are
     * never returned. Newest first within each product and source.
     *
     * @param  int[]  $productIds
     * @param  array<string, CarbonImmutable>  $cutoffs
     * @return Quote[]
     */
    public function quotesFor(array $productIds, array $cutoffs): array;

    /**
     * Removes every observation of one source, in id chunks, and returns how many
     * rows went. Run-log rows are left alone: they hold no third-party prices.
     */
    public function deleteBySource(string $source, int $chunkSize = 500): int;

    /**
     * Writes one already-finished run-log row (a skipped or purged unit of work).
     * `$details` must never contain prices.
     *
     * @param  array<string, mixed>|null  $details
     */
    public function recordRun(
        string $source,
        string $unitKey,
        PriceRunStatus $status,
        int $rowsWritten = 0,
        int $rowsRejected = 0,
        ?string $error = null,
        ?array $details = null,
    ): PriceIngestionRun;

    /**
     * Opens a run-log row in `running` state for one unit of work; pair it with
     * {@see self::finishRun()}.
     */
    public function startRun(string $source, string $unitKey): PriceIngestionRun;

    /**
     * Closes a run opened by {@see self::startRun()}. `$details` must never
     * contain prices.
     *
     * @param  array<string, mixed>|null  $details
     */
    public function finishRun(
        PriceIngestionRun $run,
        PriceRunStatus $status,
        int $rowsWritten = 0,
        int $rowsRejected = 0,
        ?string $error = null,
        ?array $details = null,
    ): PriceIngestionRun;

    /**
     * The seeded (global, active) catalogue products with these slugs, keyed by
     * slug. A slug the catalogue does not have is simply absent.
     *
     * @param  string[]  $slugs
     * @return array<string, CatalogueProduct>
     */
    public function productsBySlug(array $slugs): array;

    /** Whether the source already stored a row carrying this `source_ref`. */
    public function hasObservationWithRef(string $source, string $sourceRef): bool;

    /**
     * Whether the source has a `success` or `partial` run whose `details.day` is
     * this date (Y-m-d): the day is covered, whatever the run wrote.
     */
    public function hasCompletedRunForDay(string $source, string $day): bool;

    /** Whether the source already stored an observation of this product for this period start. */
    public function hasObservationOn(int $productId, string $source, CarbonImmutable $periodStart): bool;
}
