<?php

declare(strict_types=1);

namespace App\Services\Prices\Sources;

use App\DTOs\Prices\ObservationDraft;
use App\DTOs\Prices\Rejection;
use App\Enums\PriceBasis;
use App\Enums\PriceKind;
use App\Exceptions\Prices\PriceSourceNoData;
use App\Repositories\Contracts\PriceRepositoryContract;
use App\Services\Prices\Parsers\EmmsaTableParser;
use App\Services\Prices\Parsers\EmmsaVarietySelector;
use Carbon\CarbonImmutable;

/**
 * Layer 2: EMMSA's daily wholesale report, one request for the whole basket
 * (design D10, spike).
 *
 * Always the PREVIOUS day: asked for the current day in the evening the report
 * came back empty, so D-1 is the only day known to be loaded. Only the products
 * (`vprod`) are sent; leaving the variety empty returns every variety, so one
 * POST covers all mapped slugs. The answer carries names, no codes, so rows are
 * matched by product name and a variety pattern.
 *
 * The request goes through the `emmsa` profile, whose CA bundle holds the Let's
 * Encrypt intermediates the server forgets to send; verification stays on. An
 * empty table is a failed run ({@see PriceSourceNoData}), so the GMML fallback
 * can fill the day and the canary notices.
 */
final class EmmsaSource implements PriceSource
{
    public function __construct(
        private readonly PriceRepositoryContract $repository,
        private readonly SourceHttp $http,
        private readonly EmmsaTableParser $parser,
        private readonly EmmsaVarietySelector $selector,
        private readonly WholesaleFallbackGate $days,
    ) {}

    public function key(): string
    {
        return 'emmsa';
    }

    public function units(CarbonImmutable $asOf): array
    {
        return [new PriceFetchUnit('all')];
    }

    public function fetch(PriceFetchUnit $unit, CarbonImmutable $asOf): SourceFetchResult
    {
        $day = $this->days->dayFor($asOf);
        $mapping = $this->mapping();

        $html = $this->http->postForm($this->key(), 'emmsa', (string) config('prices.sources.emmsa.url'), [
            'vid_tipo' => '1',
            'vprod' => implode(',', array_values(array_unique(array_map(fn (array $m): string => $m['emmsa']['prod'], $mapping)))),
            'vvari' => '',
            'vfecha' => $day->format('d/m/Y'),
        ]);

        $rows = $this->parser->parse($html);

        if ($rows === []) {
            throw PriceSourceNoData::forDay($this->key(), $day->toDateString());
        }

        $products = $this->repository->productsBySlug(array_keys($mapping));
        $now = CarbonImmutable::now();
        $drafts = [];
        $rejections = [];

        foreach ($mapping as $slug => $entry) {
            $product = $products[$slug] ?? null;

            if ($product === null) {
                continue;
            }

            // Wholesale prices are per kilogram; a product bought by another unit
            // has no honest wholesale quote.
            if ($product->unit !== 'kg') {
                $rejections[] = new Rejection($slug, 'unit_not_kg');

                continue;
            }

            $quote = $this->selector->select($rows, $entry['emmsa']['product'], $entry['emmsa']['variety_match']);

            if ($quote === null) {
                $rejections[] = new Rejection($slug, 'no_matching_variety');

                continue;
            }

            $drafts[] = new ObservationDraft(
                productId: $product->id,
                source: $this->key(),
                kind: PriceKind::Wholesale,
                unit: 'kg',
                unitPrice: $quote->avg,
                referencePrice: null,
                priceMin: $quote->min,
                priceMax: $quote->max,
                sampleSize: $quote->rows,
                basis: PriceBasis::Measured,
                periodStart: $day,
                periodEnd: $day,
                observedAt: $now,
                sourceRef: 'emmsa:'.$day->toDateString(),
            );
        }

        $reasons = [];
        foreach ($rejections as $rejection) {
            $reasons[$rejection->reason] = ($reasons[$rejection->reason] ?? 0) + 1;
        }

        return new SourceFetchResult(
            drafts: $drafts,
            rejections: $rejections,
            details: ['day' => $day->toDateString(), 'report_rows' => count($rows), 'rejections' => $reasons],
        );
    }

    /**
     * @return array<string, array{emmsa: array{prod: string, product: string, variety_match: string}}>
     */
    private function mapping(): array
    {
        $mapping = [];

        /** @var array<string, array<string, mixed>> $all */
        $all = (array) config('prices.mapping');
        foreach ($all as $slug => $entry) {
            if (isset($entry['emmsa'])) {
                $mapping[$slug] = ['emmsa' => $entry['emmsa']];
            }
        }

        return $mapping;
    }
}
