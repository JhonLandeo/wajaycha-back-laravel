<?php

declare(strict_types=1);

namespace App\Services\Prices\Parsers;

use App\DTOs\Prices\VtexCandidate;
use App\DTOs\Prices\VtexSelection;
use App\Enums\PriceBasis;
use App\Services\Prices\Normalization\Dimension;
use App\Services\Prices\Normalization\Measure;
use App\Services\Prices\Normalization\PackSizeParser;
use App\Services\Prices\Normalization\PriceMedian;
use App\Services\Prices\Normalization\PriceNormalizer;

/**
 * Decides which of a product's Plaza Vea offers are "the price" (spec "Layer 3 -
 * Plaza Vea weekly retail") and what that price is per catalogue unit.
 *
 * Filters run in a fixed order so the rejection counts are stable: a price above
 * zero, stock, the curated must-match patterns (ALL), the curated must-not-match
 * patterns (NONE). What survives is priced per catalogue unit by the normalizer
 * and reduced to the median, so a handful of gourmet outliers cannot drag it.
 * Nothing accepted is a valid outcome, not an error: the selection simply has no
 * price.
 *
 * Pure: candidates and the curated entry in, a selection out.
 */
final class VtexOfferSelector
{
    private const COUNT_UNITS = ['unidad', 'paquete', 'atado'];

    public function __construct(
        private readonly PackSizeParser $packs,
        private readonly PriceNormalizer $normalizer,
    ) {}

    /**
     * @param  list<VtexCandidate>  $candidates
     * @param  array{must_match?: list<string>, must_not_match?: list<string>, assume_single?: bool}  $entry  the product's `plazavea` mapping
     * @param  string|null  $kgPerUnit  the curated weight of one unit, for count products priced by weight
     */
    public function select(array $candidates, array $entry, string $targetUnit, ?string $kgPerUnit): VtexSelection
    {
        $assumeSingle = (bool) ($entry['assume_single'] ?? false);
        $rejections = [];
        $prices = [];
        $references = [];
        $skuIds = [];
        $basis = PriceBasis::Measured;

        foreach ($candidates as $candidate) {
            $measure = null;
            $normalized = null;
            $reason = $this->filterReason($candidate, $entry);

            if ($reason === null) {
                $found = $this->measureFor($candidate, $targetUnit, $assumeSingle);

                if (is_string($found)) {
                    $reason = $found;
                } else {
                    $measure = $found;
                    $normalized = $this->normalizer->normalize($candidate->price, $measure, $targetUnit, $kgPerUnit, $assumeSingle);
                    $reason = $normalized->rejection;
                }
            }

            if ($reason !== null || $measure === null || $normalized === null) {
                $reason ??= 'unreadable';
                $rejections[$reason] = ($rejections[$reason] ?? 0) + 1;

                continue;
            }

            $prices[] = (string) $normalized->unitPrice;
            $skuIds[] = $candidate->skuId;
            $basis = $normalized->basis === PriceBasis::Equivalence ? PriceBasis::Equivalence : $basis;

            if ((float) $candidate->listPrice > 0.0) {
                $list = $this->normalizer->normalize($candidate->listPrice, $measure, $targetUnit, $kgPerUnit, $assumeSingle);
                if (! $list->isRejected()) {
                    $references[] = (string) $list->unitPrice;
                }
            }
        }

        ksort($rejections);

        if ($prices === []) {
            return new VtexSelection(null, null, null, null, 0, PriceBasis::Measured, [], $rejections);
        }

        usort($prices, fn (string $a, string $b): int => bccomp($a, $b, 6));

        return new VtexSelection(
            unitPrice: PriceMedian::of($prices),
            referencePrice: $references === [] ? null : PriceMedian::of($references),
            priceMin: $prices[0],
            priceMax: $prices[count($prices) - 1],
            accepted: count($prices),
            basis: $basis,
            skuIds: $skuIds,
            rejections: $rejections,
        );
    }

    /**
     * @param  array{must_match?: list<string>, must_not_match?: list<string>}  $entry
     */
    private function filterReason(VtexCandidate $candidate, array $entry): ?string
    {
        if ((float) $candidate->price <= 0.0) {
            return 'no_price';
        }

        if (! $candidate->isAvailable || $candidate->availableQuantity <= 0) {
            return 'unavailable';
        }

        foreach ($entry['must_match'] ?? [] as $pattern) {
            if (preg_match($pattern, $candidate->name) !== 1) {
                return 'not_matched';
            }
        }

        foreach ($entry['must_not_match'] ?? [] as $pattern) {
            if (preg_match($pattern, $candidate->name) === 1) {
                return 'excluded';
            }
        }

        return null;
    }

    /**
     * What the candidate's price buys, or the rejection reason when the name
     * and VTEX's own tags give no usable size.
     */
    private function measureFor(VtexCandidate $candidate, string $targetUnit, bool $assumeSingle): Measure|string
    {
        $text = str_contains($candidate->name, $candidate->itemName) ? $candidate->name : $candidate->name.' '.$candidate->itemName;
        $sizes = $this->packs->measures($text);
        $perKilo = $candidate->measurementUnit === 'kg';

        if (in_array($targetUnit, self::COUNT_UNITS, true)) {
            // A multipack is divided into its units first; then a price per kilo
            // (needs the curated weight), then a weighed or measured pack.
            foreach ($sizes as $size) {
                if ($size->dimension === Dimension::Count && (float) $size->quantity > 1.0) {
                    return $size;
                }
            }

            if ($perKilo) {
                return Measure::bulk(Dimension::Mass);
            }

            foreach ([Dimension::Mass, Dimension::Volume, Dimension::Count] as $dimension) {
                foreach ($sizes as $size) {
                    if ($size->dimension === $dimension) {
                        return $size;
                    }
                }
            }

            return $assumeSingle ? Measure::pack('1', Dimension::Count) : 'no_pack_size';
        }

        if ($perKilo) {
            return Measure::bulk(Dimension::Mass);
        }

        $wanted = $targetUnit === 'l' ? Dimension::Volume : Dimension::Mass;

        foreach ($sizes as $size) {
            if ($size->dimension === $wanted) {
                return $size;
            }
        }

        // A size of another dimension is a mismatch the normalizer names; no size
        // at all is a different failure.
        return $sizes[0] ?? 'no_pack_size';
    }
}
