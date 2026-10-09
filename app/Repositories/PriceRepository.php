<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTOs\Prices\CatalogueProduct;
use App\DTOs\Prices\ObservationDraft;
use App\DTOs\Prices\Quote;
use App\Enums\PriceRunStatus;
use App\Models\PriceIngestionRun;
use App\Models\PriceObservation;
use App\Models\Product;
use App\Repositories\Contracts\PriceRepositoryContract;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class PriceRepository implements PriceRepositoryContract
{
    public function upsertObservation(ObservationDraft $draft): PriceObservation
    {
        /** @var PriceObservation */
        return PriceObservation::query()->updateOrCreate(
            [
                'product_id' => $draft->productId,
                'source' => $draft->source,
                'period_start' => $draft->periodStart->toDateString(),
            ],
            [
                'price_kind' => $draft->kind,
                'unit' => $draft->unit,
                'unit_price' => $draft->unitPrice,
                'reference_price' => $draft->referencePrice,
                'price_min' => $draft->priceMin,
                'price_max' => $draft->priceMax,
                'sample_size' => $draft->sampleSize,
                'basis' => $draft->basis,
                'period_end' => $draft->periodEnd->toDateString(),
                'observed_at' => $draft->observedAt,
                'source_ref' => $draft->sourceRef,
                'is_quarantined' => $draft->isQuarantined,
            ],
        );
    }

    public function quotesFor(array $productIds, array $cutoffs): array
    {
        if ($productIds === [] || $cutoffs === []) {
            return [];
        }

        return PriceObservation::query()
            ->select([
                'id', 'product_id', 'source', 'price_kind', 'unit', 'unit_price', 'basis', 'period_start', 'period_end',
            ])
            ->whereIn('product_id', $productIds)
            ->where('is_quarantined', false)
            // Sargable per-source range on period_end: one OR branch per source.
            ->where(function (Builder $query) use ($cutoffs): void {
                foreach ($cutoffs as $source => $since) {
                    $query->orWhere(fn (Builder $branch) => $branch
                        ->where('source', $source)
                        ->where('period_end', '>=', $since->toDateString()));
                }
            })
            ->orderBy('product_id')
            ->orderBy('source')
            ->orderByDesc('period_end')
            ->get()
            ->map(fn (PriceObservation $row): Quote => new Quote(
                productId: $row->product_id,
                source: $row->source,
                kind: $row->price_kind,
                unit: $row->unit,
                unitPrice: $row->unit_price,
                basis: $row->basis,
                periodStart: $row->period_start,
                periodEnd: $row->period_end,
            ))
            ->all();
    }

    public function deleteBySource(string $source, int $chunkSize = 500): int
    {
        $deleted = 0;

        while (true) {
            $ids = PriceObservation::query()
                ->where('source', $source)
                ->orderBy('id')
                ->limit($chunkSize)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                return $deleted;
            }

            $deleted += PriceObservation::query()->whereIn('id', $ids)->delete();
        }
    }

    public function recordRun(
        string $source,
        string $unitKey,
        PriceRunStatus $status,
        int $rowsWritten = 0,
        int $rowsRejected = 0,
        ?string $error = null,
        ?array $details = null,
    ): PriceIngestionRun {
        $now = CarbonImmutable::now();

        /** @var PriceIngestionRun */
        return PriceIngestionRun::query()->create([
            'source' => $source,
            'unit_key' => $unitKey,
            'status' => $status,
            'started_at' => $now,
            'finished_at' => $now,
            'rows_written' => $rowsWritten,
            'rows_rejected' => $rowsRejected,
            'error' => $error,
            'details' => $details,
        ]);
    }

    public function startRun(string $source, string $unitKey): PriceIngestionRun
    {
        /** @var PriceIngestionRun */
        return PriceIngestionRun::query()->create([
            'source' => $source,
            'unit_key' => $unitKey,
            'status' => PriceRunStatus::Running,
            'started_at' => CarbonImmutable::now(),
            'rows_written' => 0,
            'rows_rejected' => 0,
        ]);
    }

    public function finishRun(
        PriceIngestionRun $run,
        PriceRunStatus $status,
        int $rowsWritten = 0,
        int $rowsRejected = 0,
        ?string $error = null,
        ?array $details = null,
    ): PriceIngestionRun {
        $run->update([
            'status' => $status,
            'finished_at' => CarbonImmutable::now(),
            'rows_written' => $rowsWritten,
            'rows_rejected' => $rowsRejected,
            'error' => $error,
            'details' => $details,
        ]);

        return $run;
    }

    public function productsBySlug(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $products = [];

        foreach (Product::query()
            ->select(['id', 'slug', 'unit'])
            ->whereNull('user_id')
            ->where('is_active', true)
            ->whereIn('slug', $slugs)
            ->get() as $product) {
            $products[$product->slug] = new CatalogueProduct($product->id, $product->slug, $product->unit);
        }

        return $products;
    }

    public function hasObservationWithRef(string $source, string $sourceRef): bool
    {
        return PriceObservation::query()
            ->where('source', $source)
            ->where('source_ref', $sourceRef)
            ->exists();
    }
}
