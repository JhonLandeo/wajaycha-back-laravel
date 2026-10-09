<?php

declare(strict_types=1);

namespace App\Services\Prices\Sources;

use App\DTOs\Prices\ObservationDraft;
use App\DTOs\Prices\Rejection;
use App\Enums\PriceKind;
use App\Repositories\Contracts\PriceRepositoryContract;
use App\Services\Prices\Parsers\VtexOfferSelector;
use App\Services\Prices\Parsers\VtexSearchParser;
use Carbon\CarbonImmutable;

/**
 * Layer 3: Plaza Vea's public VTEX catalogue search, weekly, for the mapped
 * products only (design D7, D10).
 *
 * One unit of work per product: one request, one run-log row. The request is the
 * legacy search the site's own front end uses — term plus the curated category
 * path, at most 50 results — and it identifies itself with the price bot
 * User-Agent. Spaces in the term are percent-encoded; VTEX answers HTTP 400 to a
 * "+". The command spaces the jobs out; this class never retries or works
 * around a refusal.
 *
 * Zero accepted offers writes no observation and is NOT a failure: an absent
 * offer is a valid answer, and the canary catches systemic coverage loss.
 */
final class PlazaVeaSource implements PriceSource
{
    private const SEARCH_URL = 'https://www.plazavea.com.pe/api/catalog_system/pub/products/search';

    /** VTEX caps a page at 50 items (`_to` is inclusive). */
    private const PAGE_SIZE = 50;

    public function __construct(
        private readonly PriceRepositoryContract $repository,
        private readonly SourceHttp $http,
        private readonly VtexSearchParser $parser,
        private readonly VtexOfferSelector $selector,
    ) {}

    public function key(): string
    {
        return 'plazavea';
    }

    public function units(CarbonImmutable $asOf): array
    {
        $units = [];

        foreach ((array) config('prices.mapping') as $slug => $entry) {
            if (isset($entry['plazavea'])) {
                $units[] = new PriceFetchUnit((string) $slug);
            }
        }

        return $units;
    }

    public function fetch(PriceFetchUnit $unit, CarbonImmutable $asOf): SourceFetchResult
    {
        /** @var array{kg_per_unit?: float|null, plazavea?: array<string, mixed>}|null $mapping */
        $mapping = config("prices.mapping.{$unit->key}");

        if ($mapping === null || ! isset($mapping['plazavea'])) {
            return new SourceFetchResult(rejections: [new Rejection($unit->key, 'unmapped')], details: ['reason' => 'unmapped']);
        }

        $product = $this->repository->productsBySlug([$unit->key])[$unit->key] ?? null;

        if ($product === null) {
            return new SourceFetchResult(rejections: [new Rejection($unit->key, 'product_not_in_catalogue')], details: ['reason' => 'product_not_in_catalogue']);
        }

        /** @var array{category_path: string, term: string, must_match?: list<string>, must_not_match?: list<string>, assume_single?: bool} $entry */
        $entry = $mapping['plazavea'];

        $candidates = $this->parser->parse($this->http->get($this->key(), 'vtex', $this->searchUrl($entry)));
        $kgPerUnit = $mapping['kg_per_unit'] ?? null;
        $selection = $this->selector->select($candidates, $entry, $product->unit, $kgPerUnit === null ? null : (string) $kgPerUnit);

        $rejections = [];
        foreach ($selection->rejections as $reason => $count) {
            for ($i = 0; $i < $count; $i++) {
                $rejections[] = new Rejection($unit->key, $reason);
            }
        }

        $details = ['accepted' => $selection->accepted, 'rejections' => $selection->rejections];

        if (! $selection->hasPrice()) {
            return new SourceFetchResult(rejections: $rejections, details: ['reason' => 'no_accepted_candidates'] + $details);
        }

        $observedAt = CarbonImmutable::now();
        $day = $observedAt->setTimezone('America/Lima')->startOfDay();

        return new SourceFetchResult(
            drafts: [new ObservationDraft(
                productId: $product->id,
                source: $this->key(),
                kind: PriceKind::Retail,
                unit: $product->unit,
                unitPrice: (string) $selection->unitPrice,
                referencePrice: $selection->referencePrice,
                priceMin: $selection->priceMin,
                priceMax: $selection->priceMax,
                sampleSize: $selection->accepted,
                basis: $selection->basis,
                periodStart: $day,
                periodEnd: $day,
                observedAt: $observedAt,
                sourceRef: implode(',', $selection->skuIds),
            )],
            rejections: $rejections,
            details: $details,
        );
    }

    /**
     * @param  array{category_path: string, term: string}  $entry
     */
    private function searchUrl(array $entry): string
    {
        return self::SEARCH_URL
            .'?ft='.rawurlencode($entry['term'])
            .'&fq=C:'.$entry['category_path']
            .'&_from=0&_to='.(self::PAGE_SIZE - 1);
    }
}
