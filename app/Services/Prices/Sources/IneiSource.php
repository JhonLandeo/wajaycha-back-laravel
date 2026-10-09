<?php

declare(strict_types=1);

namespace App\Services\Prices\Sources;

use App\DTOs\Prices\ObservationDraft;
use App\DTOs\Prices\Rejection;
use App\Enums\PriceKind;
use App\Exceptions\Prices\PriceSourceFormatChanged;
use App\Repositories\Contracts\PriceRepositoryContract;
use App\Services\Prices\Normalization\Dimension;
use App\Services\Prices\Normalization\Measure;
use App\Services\Prices\Normalization\PriceNormalizer;
use App\Services\Prices\Parsers\IneiCuadro19Parser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * Layer 1: INEI's monthly Cuadro N.19, the retail anchor for Lima (design D8).
 *
 * The weekly check costs one request when nothing is new: the collection page
 * lists the editions, the newest monthly one is compared by id with what is
 * already stored, and only an unseen edition costs the edition page and the
 * PDF. The collection also lists the annual bulletin, so editions are matched by
 * the monthly slug pattern and never by "first PDF link".
 *
 * The PDF goes to private storage, smalot reads only the pages that carry the
 * table, and the file is removed whatever happens. PDFs are never kept.
 */
final class IneiSource implements PriceSource
{
    private const MONTHS = 'enero|febrero|marzo|abril|mayo|junio|julio|agosto|setiembre|septiembre|octubre|noviembre|diciembre';

    /** The table's title; "PRECIOS PROMEDIO MENSUAL" alone also appears on three other pages. */
    private const TABLE_PAGE = '/CUADRO\s*N\S{0,2}\s*19\b/u';

    public function __construct(
        private readonly PriceRepositoryContract $repository,
        private readonly PdfPageReader $pdf,
        private readonly PriceNormalizer $normalizer,
        private readonly SourceHttp $http,
    ) {}

    public function key(): string
    {
        return 'inei';
    }

    public function units(CarbonImmutable $asOf): array
    {
        return [new PriceFetchUnit('all')];
    }

    public function fetch(PriceFetchUnit $unit, CarbonImmutable $asOf): SourceFetchResult
    {
        $base = (string) config('prices.sources.inei.base_url');
        $edition = $this->latestEdition($base);

        if ($this->repository->hasObservationWithRef($this->key(), $edition['id'])) {
            return SourceFetchResult::skipped(['reason' => 'edition_already_ingested', 'edition' => $edition['id']]);
        }

        $pdfUrl = $this->pdfUrl($this->http->get($this->key(), 'gob_pe', $base.$edition['path']));
        $pages = $this->tablePages($pdfUrl, $edition['id']);

        return $this->toResult($pages, $edition['id']);
    }

    /**
     * @return array{id: string, path: string}
     */
    private function latestEdition(string $base): array
    {
        $html = $this->http->get($this->key(), 'gob_pe', $base.config('prices.sources.inei.collection_path'));

        $pattern = '#href="(/institucion/inei/informes-publicaciones/(\d+)-indicadores-de-precios-de-la-economia-(?:'.self::MONTHS.')-\d{4})"#';
        preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);

        $latest = null;
        foreach ($matches as $match) {
            if ($latest === null || (int) $match[2] > (int) $latest['id']) {
                $latest = ['id' => $match[2], 'path' => $match[1]];
            }
        }

        return $latest ?? throw PriceSourceFormatChanged::missing($this->key(), 'a monthly edition link');
    }

    private function pdfUrl(string $editionHtml): string
    {
        if (preg_match('#https://cdn\.www\.gob\.pe/[^"\'\s]+?\.pdf\?v=\d+#', $editionHtml, $m) !== 1) {
            throw PriceSourceFormatChanged::missing($this->key(), 'the edition PDF link');
        }

        return $m[0];
    }

    /**
     * @return list<string>
     */
    private function tablePages(string $pdfUrl, string $editionId): array
    {
        $disk = Storage::disk('local');
        $relative = "prices/inei-{$editionId}.pdf";
        $disk->put($relative, $this->http->get($this->key(), 'gob_pe', $pdfUrl));

        try {
            $pages = $this->pdf->pagesMatching($disk->path($relative), self::TABLE_PAGE);
        } finally {
            $disk->delete($relative);
        }

        if ($pages === []) {
            throw PriceSourceFormatChanged::missing($this->key(), 'the Cuadro N.19 pages');
        }

        return $pages;
    }

    /**
     * @param  list<string>  $pages
     */
    private function toResult(array $pages, string $editionId): SourceFetchResult
    {
        $parsed = (new IneiCuadro19Parser((float) config('prices.sources.inei.max_mom_change')))->parse(implode("\n", $pages));

        $mapping = $this->mapping();
        $products = $this->repository->productsBySlug(array_keys($mapping));

        $rowsByLabel = [];
        foreach ($parsed->rows as $row) {
            $rowsByLabel[$row->label] = $row;
        }

        $rejectedLabels = [];
        foreach ($parsed->rejections as $rejection) {
            $rejectedLabels[$rejection->subject] = true;
        }

        $drafts = [];
        $rejections = [];
        $quarantined = 0;
        $now = CarbonImmutable::now();

        foreach ($mapping as $slug => $entry) {
            $product = $products[$slug] ?? null;
            if ($product === null) {
                continue;
            }

            $row = $rowsByLabel[$entry['inei']['label']] ?? null;
            if ($row === null) {
                $rejections[] = new Rejection($slug, isset($rejectedLabels[$entry['inei']['label']]) ? 'unparseable_price' : 'label_not_found');

                continue;
            }

            if ($row->unit !== $entry['inei']['unit']) {
                $rejections[] = new Rejection($slug, 'unit_mismatch');

                continue;
            }

            $normalized = $this->normalizer->normalize(
                $row->price,
                $this->measureFor($row->unit),
                $product->unit,
                $entry['kg_per_unit'] === null ? null : (string) $entry['kg_per_unit'],
            );

            if ($normalized->isRejected()) {
                $rejections[] = new Rejection($slug, (string) $normalized->rejection);

                continue;
            }

            $quarantined += $row->isQuarantined ? 1 : 0;

            $drafts[] = new ObservationDraft(
                productId: $product->id,
                source: $this->key(),
                kind: PriceKind::Retail,
                unit: $product->unit,
                unitPrice: (string) $normalized->unitPrice,
                referencePrice: null,
                priceMin: null,
                priceMax: null,
                sampleSize: 1,
                basis: $normalized->basis,
                periodStart: $parsed->periodStart,
                periodEnd: $parsed->periodEnd,
                observedAt: $now,
                sourceRef: $editionId,
                isQuarantined: $row->isQuarantined,
            );
        }

        $reasons = [];
        foreach ($rejections as $rejection) {
            $reasons[$rejection->reason] = ($reasons[$rejection->reason] ?? 0) + 1;
        }

        return new SourceFetchResult(
            drafts: $drafts,
            rejections: $rejections,
            partial: $rejections !== [] || $quarantined > 0,
            details: ['edition' => $editionId, 'period' => $parsed->periodStart->format('Y-m'), 'rejections' => $reasons, 'quarantined' => $quarantined],
        );
    }

    /**
     * The slugs INEI maps, with the part of the mapping this adapter reads.
     *
     * @return array<string, array{kg_per_unit: float|null, inei: array{label: string, unit: string}}>
     */
    private function mapping(): array
    {
        $mapping = [];

        /** @var array<string, array<string, mixed>> $all */
        $all = (array) config('prices.mapping');
        foreach ($all as $slug => $entry) {
            if (isset($entry['inei'])) {
                $mapping[$slug] = ['kg_per_unit' => $entry['kg_per_unit'] ?? null, 'inei' => $entry['inei']];
            }
        }

        return $mapping;
    }

    /**
     * What one printed INEI unit buys. A kilogram or a litre is the base unit;
     * a can or a bottle is one unit.
     */
    private function measureFor(string $ineiUnit): Measure
    {
        return match ($ineiUnit) {
            'KILOGRAMO' => Measure::bulk(Dimension::Mass),
            'LITRO' => Measure::bulk(Dimension::Volume),
            default => Measure::pack('1', Dimension::Count),
        };
    }
}
