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
use App\Services\Prices\Parsers\GmmlBulletinParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * Layer 2 fallback: MIDAGRI's daily GMML bulletin, used for a day EMMSA did not
 * cover (design D10).
 *
 * It stands down in three situations, each logged as a `skipped` run: EMMSA
 * already has a successful D-1 ({@see WholesaleFallbackGate}), the month page
 * lists no PDF for D-1 (weekends and holidays), and — per product — an EMMSA row
 * exists for that product and day, so the two sources are never mixed.
 *
 * The collection page links one page per month and each month page one PDF per
 * business day, with the date in the file name. The bulletin's own header date
 * must agree with the file name or the run fails: a PDF filed under the wrong
 * day would silently date every price wrong. Prices are the "Hoy" column — the
 * bulletin's own day — divided by "Equiv. en kg".
 */
final class GmmlSource implements PriceSource
{
    private const MONTHS = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
        7 => 'julio', 8 => 'agosto', 9 => 'setiembre|septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    public function __construct(
        private readonly PriceRepositoryContract $repository,
        private readonly PdfPageReader $pdf,
        private readonly PriceNormalizer $normalizer,
        private readonly SourceHttp $http,
        private readonly GmmlBulletinParser $parser,
        private readonly WholesaleFallbackGate $days,
    ) {}

    public function key(): string
    {
        return 'gmml';
    }

    public function units(CarbonImmutable $asOf): array
    {
        return [new PriceFetchUnit('all')];
    }

    public function fetch(PriceFetchUnit $unit, CarbonImmutable $asOf): SourceFetchResult
    {
        $day = $this->days->dayFor($asOf);

        if ($this->days->emmsaCovers($day)) {
            return SourceFetchResult::skipped(['reason' => 'emmsa_covers_day', 'day' => $day->toDateString()]);
        }

        $base = (string) config('prices.sources.gmml.base_url');
        $monthPath = $this->monthPagePath($base, $day);
        $pdfUrl = $this->pdfUrl($this->http->get($this->key(), 'gob_pe', $base.$monthPath), $day);

        if ($pdfUrl === null) {
            return SourceFetchResult::skipped(['reason' => 'no_bulletin_for_day', 'day' => $day->toDateString()]);
        }

        $text = $this->bulletinText($pdfUrl, $day);
        $bulletin = $this->parser->parse($text);

        if (! $bulletin->date->isSameDay($day)) {
            throw PriceSourceFormatChanged::missing($this->key(), 'a bulletin dated '.$day->toDateString());
        }

        return $this->toResult($bulletin->rows, $day);
    }

    private function monthPagePath(string $base, CarbonImmutable $day): string
    {
        $html = $this->http->get($this->key(), 'gob_pe', $base.config('prices.sources.gmml.collection_path'));
        $pattern = '#href="(/institucion/midagri/informes-publicaciones/\d+-reporte-de-ingreso-y-precios-en-el-gran-mercado-mayorista-de-lima-gmml-(?:'
            .self::MONTHS[$day->month].')-'.$day->year.')"#';

        if (preg_match($pattern, $html, $m) !== 1) {
            throw PriceSourceFormatChanged::missing($this->key(), 'the month page for '.$day->format('Y-m'));
        }

        return $m[1];
    }

    private function pdfUrl(string $monthHtml, CarbonImmutable $day): ?string
    {
        $pattern = '#https://cdn\.www\.gob\.pe/[^"\'\s]+?-lima-'.$day->format('d-m-Y').'\.pdf\?v=\d+#';

        return preg_match($pattern, $monthHtml, $m) === 1 ? $m[0] : null;
    }

    private function bulletinText(string $pdfUrl, CarbonImmutable $day): string
    {
        $disk = Storage::disk('local');
        $relative = 'prices/gmml-'.$day->toDateString().'.pdf';
        $disk->put($relative, $this->http->get($this->key(), 'gob_pe', $pdfUrl));

        try {
            return implode("\n", $this->pdf->pagesMatching($disk->path($relative), '/./su'));
        } finally {
            $disk->delete($relative);
        }
    }

    /**
     * @param  list<\App\DTOs\Prices\GmmlRow>  $bulletinRows
     */
    private function toResult(array $bulletinRows, CarbonImmutable $day): SourceFetchResult
    {
        $rowsByLabel = [];
        foreach ($bulletinRows as $row) {
            $rowsByLabel[mb_strtolower($row->label)] = $row;
        }

        $mapping = [];
        /** @var array<string, array<string, mixed>> $all */
        $all = (array) config('prices.mapping');
        foreach ($all as $slug => $entry) {
            if (isset($entry['gmml'])) {
                $mapping[$slug] = $entry['gmml']['label'];
            }
        }

        $products = $this->repository->productsBySlug(array_keys($mapping));
        $now = CarbonImmutable::now();
        $drafts = [];
        $rejections = [];
        $covered = 0;

        foreach ($mapping as $slug => $label) {
            $product = $products[$slug] ?? null;

            if ($product === null) {
                continue;
            }

            if ($product->unit !== 'kg') {
                $rejections[] = new Rejection($slug, 'unit_not_kg');

                continue;
            }

            $row = $rowsByLabel[mb_strtolower($label)] ?? null;

            if ($row === null) {
                $rejections[] = new Rejection($slug, 'label_not_found');

                continue;
            }

            // Never mix sources for one product and day.
            if ($this->repository->hasObservationOn($product->id, 'emmsa', $day)) {
                $covered++;

                continue;
            }

            $normalized = $this->normalizer->normalize($row->priceToday, Measure::pack($row->equivalentKg, Dimension::Mass), 'kg');

            if ($normalized->isRejected()) {
                $rejections[] = new Rejection($slug, (string) $normalized->rejection);

                continue;
            }

            $drafts[] = new ObservationDraft(
                productId: $product->id,
                source: $this->key(),
                kind: PriceKind::Wholesale,
                unit: 'kg',
                unitPrice: (string) $normalized->unitPrice,
                referencePrice: null,
                priceMin: null,
                priceMax: null,
                sampleSize: 1,
                basis: $normalized->basis,
                periodStart: $day,
                periodEnd: $day,
                observedAt: $now,
                sourceRef: 'gmml:'.$day->toDateString(),
            );
        }

        $reasons = [];
        foreach ($rejections as $rejection) {
            $reasons[$rejection->reason] = ($reasons[$rejection->reason] ?? 0) + 1;
        }

        return new SourceFetchResult(
            drafts: $drafts,
            rejections: $rejections,
            details: ['day' => $day->toDateString(), 'covered_by_emmsa' => $covered, 'rejections' => $reasons],
        );
    }
}
